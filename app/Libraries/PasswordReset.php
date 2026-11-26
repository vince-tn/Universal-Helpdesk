<?php

namespace App\Libraries;

use App\Models\UserModel;

// emailed six digit codes for forgotten passwords
class PasswordReset
{
    public const OK       = 'ok';
    public const NO_CODE  = 'no_code';
    public const EXPIRED  = 'expired';
    public const WRONG    = 'wrong';
    public const TOO_MANY = 'too_many';

    public const CODE_LENGTH        = 6;
    public const TTL_SECONDS        = 900;
    public const MAX_ATTEMPTS       = 5;
    public const COOLDOWN_SECONDS   = 60;
    public const MAX_SENDS_PER_HOUR = 5;

    private const TABLE = 'password_resets';

    // only active, unexpired accounts may reset
    public static function eligible(?array $user): bool
    {
        return $user !== null && (int) ($user['is_active'] ?? 0) === 1 && ! UserModel::isExpired($user);
    }

    // decides one try against a stored code row without touching the db
    public static function assess(?array $row, string $input, int $now, int $max = self::MAX_ATTEMPTS): array
    {
        if ($row === null || ! empty($row['consumed_at'])) {
            return ['status' => self::NO_CODE, 'attempts' => 0, 'burn' => false];
        }

        if (strtotime((string) $row['expires_at']) <= $now) {
            return ['status' => self::EXPIRED, 'attempts' => (int) $row['attempts'], 'burn' => true];
        }

        $attempts = (int) $row['attempts'] + 1;

        if ($attempts > $max) {
            return ['status' => self::TOO_MANY, 'attempts' => $attempts - 1, 'burn' => true];
        }

        $input = preg_replace('/\D/', '', $input) ?? '';

        if ($input === '' || ! password_verify($input, (string) $row['code_hash'])) {
            return ['status' => $attempts >= $max ? self::TOO_MANY : self::WRONG, 'attempts' => $attempts, 'burn' => $attempts >= $max];
        }

        return ['status' => self::OK, 'attempts' => $attempts, 'burn' => true];
    }

    // seconds left before another code may be sent
    public static function cooldownLeft(?string $lastSentAt, int $now): int
    {
        if ($lastSentAt === null || $lastSentAt === '' || strtotime($lastSentAt) === false) {
            return 0;
        }

        return max(0, self::COOLDOWN_SECONDS - ($now - strtotime($lastSentAt)));
    }

    public static function mintCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    // sends a code when the account may reset, returns true only if mail went out
    public function request(string $email, string $ip): bool
    {
        $email = strtolower(trim($email));
        $user  = $email === '' ? null : (new UserModel())->findByEmail($email);

        if (! self::eligible($user)) {
            // same hashing cost as a real send so timing gives less away
            password_hash(self::mintCode(), PASSWORD_DEFAULT);

            return false;
        }

        $latest = $this->table()->where('email', $email)->orderBy('id', 'DESC')->get(1)->getRowArray();

        if (self::cooldownLeft($latest['created_at'] ?? null, time()) > 0) {
            return false;
        }

        $recent = $this->table()->where('email', $email)->where('created_at >=', date('Y-m-d H:i:s', time() - 3600))->countAllResults();

        if ($recent >= self::MAX_SENDS_PER_HOUR) {
            log_message('warning', 'password reset codes held back for {email}: hourly limit', ['email' => $email]);

            return false;
        }

        $code = self::mintCode();

        $this->table()->insert([
            'email'      => $email,
            'code_hash'  => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            'attempts'   => 0,
            'ip_address' => substr($ip, 0, 45),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) db_connect()->insertID();

        $sent = (new Mailer())->send($email, 'Your ' . brand_name() . ' password reset code', $this->body($code));

        if (! $sent) {
            $this->table()->where('id', $id)->delete();
            log_message('error', 'could not email a password reset code to {email}', ['email' => $email]);

            return false;
        }

        // only the newest code works
        $this->table()->where('email', $email)->where('id !=', $id)->where('consumed_at', null)->update(['consumed_at' => date('Y-m-d H:i:s')]);
        $this->table()->where('created_at <', date('Y-m-d H:i:s', time() - 86400))->delete();
        Activity::audit('password_reset_requested', 'reset code sent to ' . $email, null, $user);

        return true;
    }

    // checks a typed code and records the try
    public function verify(string $email, string $input): string
    {
        $email = strtolower(trim($email));
        $row   = $this->table()->where('email', $email)->where('consumed_at', null)->orderBy('id', 'DESC')->get(1)->getRowArray();

        $result = self::assess($row, $input, time());

        if ($row !== null) {
            $data = ['attempts' => min(255, $result['attempts'])];

            if ($result['burn']) {
                $data['consumed_at'] = date('Y-m-d H:i:s');
            }

            $this->table()->where('id', $row['id'])->update($data);
        }

        return $result['status'];
    }

    // cancels any codes still open for this email
    public function clear(string $email): void
    {
        $this->table()->where('email', strtolower(trim($email)))->where('consumed_at', null)->update(['consumed_at' => date('Y-m-d H:i:s')]);
    }

    private function table(): \CodeIgniter\Database\BaseBuilder
    {
        return db_connect()->table(self::TABLE);
    }

    private function body(string $code): string
    {
        return implode("\n", [
            'Your ' . brand_name() . ' password reset code is:',
            '',
            '    ' . $code,
            '',
            'It works for ' . intdiv(self::TTL_SECONDS, 60) . ' minutes and only once.',
            '',
            'If you did not ask to reset your password, ignore this email.',
            'Your password stays the same unless someone enters this code.',
        ]);
    }
}
