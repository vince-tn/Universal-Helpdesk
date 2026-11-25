<?php

namespace App\Libraries;

// tells whether this request reached the visitor over https
final class Https
{
    public static function isSecure(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;

        $https = strtolower((string) ($s['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $proto = strtolower(trim(explode(',', (string) ($s['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($proto === 'https' || strtolower((string) ($s['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on') {
            return true;
        }

        // public hosts are only ever served through an https proxy
        $host = strtolower(explode(':', (string) ($s['HTTP_HOST'] ?? ''), 2)[0]);
        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        foreach (explode(',', (string) (getenv('PUBLIC_HOST_SUFFIXES') ?: ($_ENV['PUBLIC_HOST_SUFFIXES'] ?? ''))) as $suffix) {
            $suffix = '.' . ltrim(strtolower(trim($suffix)), '.');

            if ($suffix !== '.' && str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }
}
