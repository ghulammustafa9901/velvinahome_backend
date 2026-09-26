<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestPlatform;

/*
| Spec §9: request id propagated from the storefront and returned; tenant id and request id on
| every log line written while serving a tenant.
*/

it('echoes a well-formed incoming request id and replaces a malformed one', function (): void {
    $url = 'http://'.TestPlatform::apiHost(TestPlatform::BRAND_B).'/api/v1/storefront/config';

    $this->withHeader('X-Request-Id', 'bff-01J9ZC3RQK7')->getJson($url)
        ->assertHeader('X-Request-Id', 'bff-01J9ZC3RQK7');

    $replaced = $this->withHeader('X-Request-Id', '<script>alert(1)</script>')->getJson($url);

    expect($replaced->headers->get('X-Request-Id'))->toHaveLength(26);
});

it('puts tenant and request ids into the context of every log record', function (): void {
    Route::middleware(['tenant:api', 'api'])->get('api/v1/test-log', function () {
        Log::info('probe line');

        return ['ok' => true];
    });

    $captured = [];
    Event::listen(MessageLogged::class, function () use (&$captured): void {
        $captured[] = Context::all();
    });

    $this->withHeader('X-Request-Id', 'req-log-probe-1')
        ->getJson('http://'.TestPlatform::apiHost(TestPlatform::BRAND_C).'/api/v1/test-log')
        ->assertOk();

    expect($captured)->not->toBeEmpty()
        ->and($captured[0]['tenant_id'] ?? null)->toBe(TestPlatform::brand(TestPlatform::BRAND_C)->id)
        ->and($captured[0]['request_id'] ?? null)->toBe('req-log-probe-1');
});
