<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf', $token);
        }
        return $token;
    }

    public static function verify(Request $request): void
    {
        $sent = $request->header('X-CSRF-Token') ?: (string) $request->get('_csrf', '');
        if ($sent === '' || !hash_equals(self::token(), $sent)) {
            throw new HttpException('Your session expired. Please reload the page.', 419);
        }
    }
}
