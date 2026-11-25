<?php

namespace App\Filters;

use App\Libraries\Https;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

// browser hardening headers on every response
class SecurityHeaders implements FilterInterface
{
    // todo: drop unsafe-inline once inline scripts move to files or nonces
    public const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Content-Security-Policy', self::CSP);
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->setHeader('X-Permitted-Cross-Domain-Policies', 'none');

        // hsts only means something over https, and must not pin the lan http host
        if (Https::isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
