<?php

namespace App\Libraries;

// microsoft entra id sign in: openid connect code flow with pkce
class EntraSso
{
    private const AUTHORITY  = 'https://login.microsoftonline.com/';
    private const FLOW_KEY   = 'sso_flow';
    private const FLOW_TTL   = 600;
    private const JWKS_TTL   = 43200;
    private const CLOCK_SKEW = 300;

    public readonly string $tenant;
    public readonly string $clientId;
    private readonly string $clientSecret;
    public readonly array $domains;

    public function __construct(?array $env = null)
    {
        $get = static fn (string $k): string => trim((string) ($env !== null ? ($env[$k] ?? '') : (env($k) ?: '')));

        $this->tenant       = strtolower($get('SSO_TENANT_ID'));
        $this->clientId     = $get('SSO_CLIENT_ID');
        $this->clientSecret = $get('SSO_CLIENT_SECRET');

        $raw = $get('SSO_ALLOWED_DOMAINS') ?: $get('ALLOWED_EMAIL_DOMAINS');

        $this->domains = array_values(array_filter(array_map(
            static fn ($d) => ltrim(strtolower(trim($d)), '@'),
            explode(',', $raw)
        ), static fn ($d) => $d !== ''));
    }

    public function configured(): bool
    {
        return $this->tenant !== ''
            && preg_match('/^[a-z0-9.-]+$/', $this->tenant) === 1
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && ($this->tenantIsGuid() || $this->domains !== []);
    }

    // entra only accepts https redirects, except for localhost
    public function available(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $uri  = $this->redirectUri();
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        return str_starts_with($uri, 'https://') || in_array($host, ['localhost', '127.0.0.1'], true);
    }

    public function tenantIsGuid(): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->tenant) === 1;
    }

    public function redirectUri(): string
    {
        helper('url');

        return base_url('sso/callback');
    }

    public function begin(): string
    {
        $state    = self::b64url(random_bytes(32));
        $nonce    = self::b64url(random_bytes(32));
        $verifier = self::b64url(random_bytes(64));

        session()->set(self::FLOW_KEY, [
            'state'    => $state,
            'nonce'    => $nonce,
            'verifier' => $verifier,
            'at'       => time(),
        ]);

        return self::AUTHORITY . rawurlencode($this->tenant) . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'             => $this->clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $this->redirectUri(),
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    // returns the validated id token claims or throws with a reason safe to show
    public function finish(array $query): array
    {
        $flow = session()->get(self::FLOW_KEY);
        session()->remove(self::FLOW_KEY);

        if (isset($query['error'])) {
            log_message('warning', 'sso error from entra: {e} {d}', [
                'e' => (string) $query['error'],
                'd' => mb_substr((string) ($query['error_description'] ?? ''), 0, 300),
            ]);

            throw new \RuntimeException('Microsoft did not complete the sign in. Please try again.');
        }

        if (! is_array($flow) || (time() - (int) ($flow['at'] ?? 0)) > self::FLOW_TTL) {
            throw new \RuntimeException('That sign in link has expired. Please try again.');
        }

        $state = (string) ($query['state'] ?? '');
        $code  = (string) ($query['code'] ?? '');

        if ($state === '' || $code === '' || ! hash_equals((string) $flow['state'], $state)) {
            throw new \RuntimeException('The sign in response did not match this browser. Please try again.');
        }

        $idToken = $this->exchange($code, (string) $flow['verifier']);

        return $this->validateIdToken($idToken, (string) $flow['nonce']);
    }

    public function validateIdToken(string $jwt, string $nonce, ?int $now = null): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new \RuntimeException('Microsoft sent a token we could not read.');
        }

        $header = json_decode((string) self::b64urlDecode($parts[0]), true);
        $claims = json_decode((string) self::b64urlDecode($parts[1]), true);
        $sig    = self::b64urlDecode($parts[2]);

        if (! is_array($header) || ! is_array($claims) || $sig === null || ($header['alg'] ?? '') !== 'RS256') {
            throw new \RuntimeException('Microsoft sent a token we could not read.');
        }

        $pem = $this->signingKey((string) ($header['kid'] ?? ''));

        if ($pem === null || openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('The Microsoft token signature did not check out.');
        }

        $this->checkClaims($claims, $nonce, $now ?? time());

        return $claims;
    }

    public function checkClaims(array $claims, string $nonce, int $now): void
    {
        $tid = strtolower((string) ($claims['tid'] ?? ''));
        $iss = (string) ($claims['iss'] ?? '');
        $aud = $claims['aud'] ?? '';

        $tenantOk = $this->tenantIsGuid() ? $tid === $this->tenant : $tid !== '';
        $issOk    = $tenantOk && hash_equals(self::AUTHORITY . $tid . '/v2.0', $iss);
        $audOk    = is_array($aud) ? in_array($this->clientId, $aud, true) : hash_equals($this->clientId, (string) $aud);

        $reason = match (true) {
            ! $issOk => 'issuer',
            ! $audOk => 'audience',
            ! isset($claims['exp']) || (int) $claims['exp'] < $now - self::CLOCK_SKEW => 'expired',
            isset($claims['nbf']) && (int) $claims['nbf'] > $now + self::CLOCK_SKEW => 'not yet valid',
            $nonce === '' || ! hash_equals($nonce, (string) ($claims['nonce'] ?? '')) => 'nonce',
            default => null,
        };

        if ($reason !== null) {
            log_message('warning', 'sso id token rejected: {r}', ['r' => $reason]);

            throw new \RuntimeException('The Microsoft sign in could not be verified (' . $reason . '). Please try again.');
        }
    }

    public function emailFrom(array $claims): ?string
    {
        foreach (['preferred_username', 'email'] as $name) {
            $value = strtolower(trim((string) ($claims[$name] ?? '')));

            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }

    public function subjectOf(array $claims): string
    {
        return mb_substr(strtolower((string) ($claims['tid'] ?? '')) . ':' . (string) ($claims['oid'] ?? $claims['sub'] ?? ''), 0, 191);
    }

    public function domainAllowed(string $email): bool
    {
        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));

        foreach ($this->domains as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function exchange(string $code, string $verifier): string
    {
        $client = \Config\Services::curlrequest(['timeout' => 15, 'http_errors' => false], null, null, false);

        try {
            $response = $client->post(self::AUTHORITY . rawurlencode($this->tenant) . '/oauth2/v2.0/token', [
                'form_params' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $this->redirectUri(),
                    'code_verifier' => $verifier,
                    'scope'         => 'openid profile email',
                ],
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'sso token request failed: {m}', ['m' => $e->getMessage()]);

            throw new \RuntimeException('Could not reach Microsoft to finish signing in. Please try again.');
        }

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || ! is_array($body) || ! is_string($body['id_token'] ?? null)) {
            log_message('error', 'sso token exchange refused: {s} {e}', [
                's' => $response->getStatusCode(),
                'e' => is_array($body) ? mb_substr((string) ($body['error_description'] ?? $body['error'] ?? ''), 0, 300) : 'bad body',
            ]);

            throw new \RuntimeException('Microsoft refused the sign in. Ask IT to check the single sign-on setup.');
        }

        return $body['id_token'];
    }

    private function signingKey(string $kid): ?string
    {
        if ($kid === '') {
            return null;
        }

        // refetch once when the key is unknown, entra rotates keys
        foreach ([false, true] as $refresh) {
            foreach ($this->jwks($refresh) as $jwk) {
                if (($jwk['kid'] ?? '') === $kid && ($jwk['kty'] ?? '') === 'RSA' && isset($jwk['n'], $jwk['e'])) {
                    return self::pemFromModulus((string) $jwk['n'], (string) $jwk['e']);
                }
            }
        }

        return null;
    }

    private function jwks(bool $refresh): array
    {
        $cacheKey = 'sso_jwks_' . md5($this->tenant);

        if (! $refresh) {
            $cached = cache($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $client   = \Config\Services::curlrequest(['timeout' => 10, 'http_errors' => false], null, null, false);
            $response = $client->get(self::AUTHORITY . rawurlencode($this->tenant) . '/discovery/v2.0/keys', ['http_errors' => false]);
            $body     = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'sso jwks fetch failed: {m}', ['m' => $e->getMessage()]);

            return [];
        }

        $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];

        if ($keys !== []) {
            cache()->save($cacheKey, $keys, self::JWKS_TTL);
        }

        return $keys;
    }

    // builds a subjectpublickeyinfo pem from the jwk modulus and exponent
    public static function pemFromModulus(string $n, string $e): ?string
    {
        $mod = self::b64urlDecode($n);
        $exp = self::b64urlDecode($e);

        if ($mod === null || $exp === null || $mod === '' || $exp === '') {
            return null;
        }

        $rsaKey    = self::der(0x30, self::derInteger($mod) . self::derInteger($exp));
        $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $spki      = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $value): ?string
    {
        $raw = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $body): string
    {
        $len = strlen($body);

        if ($len < 0x80) {
            return chr($tag) . chr($len) . $body;
        }

        $lenBytes = ltrim(pack('N', $len), "\x00");

        return chr($tag) . chr(0x80 | strlen($lenBytes)) . $lenBytes . $body;
    }
}
