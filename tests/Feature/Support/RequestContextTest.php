<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
| Spec §9: request id propagated from the storefront and returned, and on every log line
| written while serving the request.
*/

it('echoes a well-formed incoming request id and replaces a malformed one', function (): void {
    $url = 'http://localhost/api/v1/storefront/config';

    $this->withHeader('X-Request-Id', 'bff-01J9ZC3RQK7')->getJson($url)
        ->assertHeader('X-Request-Id', 'bff-01J9ZC3RQK7');

    $replaced = $this->withHeader('X-Request-Id', '<script>alert(1)</script>')->getJson($url);

    expect($replaced->headers->get('X-Request-Id'))->toHaveLength(26);
});

it('puts the request id into the context of every log record', function (): void {
    Route::middleware(['api'])->get('api/v1/test-log', function () {
        Log::info('probe line');

        return ['ok' => true];
    });

    $captured = [];
    Event::listen(MessageLogged::class, function () use (&$captured): void {
        $captured[] = Context::all();
    });

    $this->withHeader('X-Request-Id', 'req-log-probe-1')->getJson('http://localhost/api/v1/test-log')->assertOk();

    expect($captured)->not->toBeEmpty()
        ->and($captured[0]['request_id'] ?? null)->toBe('req-log-probe-1');
});
