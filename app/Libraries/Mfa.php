<?php

namespace App\Libraries;

use Config\Encryption;

// two step sign in: encrypted totp secrets, recovery codes, attempt limits
class Mfa
{
    public const REQUIRED_ROLES = ['superadmin', 'supervisor', 'team_leader'];
    public const MAX_FAILURES   = 5;
    public const FAILURE_WINDOW = 600;
    public const RECOVERY_COUNT = 10;

    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function requiredFor(string $role): bool
    {
        return in_array($role, self::REQUIRED_ROLES, true);
    }

    public function isEnabled(?array $user): bool
    {
        return $user !== null
            && (int) ($user['mfa_enabled'] ?? 0) === 1
            && (string) ($user['mfa_secret'] ?? '') !== '';
    }

    public function canStoreSecrets(): bool
    {
        return $this->keys() !== [];
    }

    public function encryptSecret(string $secret): string
    {
        $keys = $this->keys();

        if ($keys === []) {
            throw new \RuntimeException('no encryption key for mfa secrets');
        }

        return base64_encode($this->encrypter($keys[0])->encrypt($secret));
    }

    public function decryptSecret(?string $stored): ?string
    {
        $raw = base64_decode((string) $stored, true);

        if ($raw === false || $raw === '') {
            return null;
        }

        // try the app key first, then the mfa key, so either can be added later
        foreach ($this->keys() as $key) {
            try {
                $plain = $this->encrypter($key)->decrypt($raw);

                if (is_string($plain) && $plain !== '') {
                    return $plain;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    public function locked(int $userId): bool
    {
        return (int) (cache($this->failKey($userId)) ?? 0) >= self::MAX_FAILURES;
    }

    public function recordFailure(int $userId): int
    {
        $count = (int) (cache($this->failKey($userId)) ?? 0) + 1;
        cache()->save($this->failKey($userId), $count, self::FAILURE_WINDOW);

        return max(0, self::MAX_FAILURES - $count);
    }

    public function clearFailures(int $userId): void
    {
        cache()->delete($this->failKey($userId));
    }

    // checks a code from the app and burns its step so it cannot be replayed
    public function verifyTotp(array $user, string $code): bool
    {
        $secret = $this->decryptSecret($user['mfa_secret'] ?? null);
        $key    = $secret === null ? null : Totp::base32Decode($secret);

        if ($key === null) {
            return false;
        }

        $last = isset($user['mfa_last_step']) ? (int) $user['mfa_last_step'] : null;
        $step = Totp::verify($key, $code, $last);

        return $step !== null && $this->claimStep((int) $user['id'], $step);
    }

    // returns how many recovery codes remain, or null when the code is wrong
    public function useRecoveryCode(array $user, string $code): ?int
    {
        $code   = $this->normaliseRecovery($code);
        $stored = (string) ($user['mfa_recovery'] ?? '');
        $hashes = json_decode($stored, true);

        if ($code === '' || ! is_array($hashes)) {
            return null;
        }

        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && password_verify($code, $hash)) {
                unset($hashes[$i]);
                $left = array_values($hashes);

                $db = db_connect();
                $db->table('users')
                    ->where('id', (int) $user['id'])
                    ->where('mfa_recovery', $stored)
                    ->update(['mfa_recovery' => json_encode($left)]);

                return $db->affectedRows() === 1 ? count($left) : null;
            }
        }

        return null;
    }

    public function recoveryLeft(?array $user): int
    {
        $hashes = json_decode((string) ($user['mfa_recovery'] ?? ''), true);

        return is_array($hashes) ? count($hashes) : 0;
    }

    public function looksLikeTotp(string $code): bool
    {
        return preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '') === 1;
    }

    // turns mfa on and returns the plain recovery codes to show once
    public function enable(int $userId, string $secret, int $step): array
    {
        [$plain, $hashed] = $this->newRecoveryCodes();

        db_connect()->table('users')->where('id', $userId)->update([
            'mfa_secret'    => $this->encryptSecret($secret),
            'mfa_enabled'   => 1,
            'mfa_recovery'  => $hashed,
            'mfa_last_step' => $step,
        ]);

        return $plain;
    }

    public function regenerateRecovery(int $userId): array
    {
        [$plain, $hashed] = $this->newRecoveryCodes();

        db_connect()->table('users')->where('id', $userId)->update(['mfa_recovery' => $hashed]);

        return $plain;
    }

    public function disable(int $userId): void
    {
        db_connect()->table('users')->where('id', $userId)->update([
            'mfa_secret'    => null,
            'mfa_enabled'   => 0,
            'mfa_recovery'  => null,
            'mfa_last_step' => null,
        ]);

        $this->clearFailures($userId);
    }

    public static function groupSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private function newRecoveryCodes(): array
    {
        $plain  = [];
        $hashed = [];
        $max    = strlen(self::RECOVERY_ALPHABET) - 1;

        for ($n = 0; $n < self::RECOVERY_COUNT; $n++) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::RECOVERY_ALPHABET[random_int(0, $max)];
            }

            $plain[]  = substr($code, 0, 5) . '-' . substr($code, 5);
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }

        return [$plain, json_encode($hashed)];
    }

    private function normaliseRecovery(string $code): string
    {
        $clean = preg_replace('/[^a-z0-9]/', '', strtolower($code)) ?? '';

        return strlen($clean) === 10 ? $clean : '';
    }

    private function claimStep(int $userId, int $step): bool
    {
        $db = db_connect();
        $db->table('users')
            ->set('mfa_last_step', $step)
            ->where('id', $userId)
            ->groupStart()
                ->where('mfa_last_step', null)
                ->orWhere('mfa_last_step <', $step)
            ->groupEnd()
            ->update();

        return $db->affectedRows() === 1;
    }

    private function failKey(int $userId): string
    {
        return 'mfa_fail_' . $userId;
    }

    private function keys(): array
    {
        $keys = [];
        $app  = $this->decodeKey((string) config(Encryption::class)->key);

        if ($app !== '') {
            $keys[] = $app;
        }

        $mfa = (string) (env('MFA_KEY') ?: '');

        if (trim($mfa) !== '') {
            $keys[] = hash_hkdf('sha256', trim($mfa), 32, 'servicedesk-mfa-secrets');
        }

        return $keys;
    }

    private function decodeKey(string $key): string
    {
        if (str_starts_with($key, 'hex2bin:')) {
            return (string) hex2bin(substr($key, 8));
        }

        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }

    private function encrypter(string $key): \CodeIgniter\Encryption\EncrypterInterface
    {
        $config      = clone config(Encryption::class);
        $config->key = $key;

        return \Config\Services::encrypter($config, false);
    }
}
