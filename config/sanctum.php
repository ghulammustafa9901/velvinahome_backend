<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sanctum — customer API tokens only (ADR-0003)
|--------------------------------------------------------------------------
|
| The storefront never uses Sanctum's cookie (SPA) mode: the Next.js BFF holds the token in
| an httpOnly cookie and sends it as a bearer token server-side. So there are no stateful
| domains and no session guard fallback — a request is authenticated by a token from the
| store database, or not at all.
|
*/

return [

    'stateful' => [],

    'guard' => [],

    'expiration' => 60 * 24 * 30,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'cp_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
