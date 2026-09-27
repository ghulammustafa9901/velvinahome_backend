<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Actions\ReceiveWebhook;
use Modules\Payments\Data\InboundWebhook;

/**
 * POST /api/v1/webhooks/{provider} on the brand's API host: the host selects the tenant, the
 * adapter verifies the signature. Always 200 for a stored event, so providers stop retrying.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $provider, ReceiveWebhook $receive): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->isJson() ? $request->json()->all() : $request->request->all();

        $stored = $receive->handle(new InboundWebhook(
            provider: $provider,
            rawBody: $request->getContent(),
            headers: $request->headers->all(),
            payload: $payload,
            ipAddress: $request->ip(),
        ));

        return response()->json(['received' => $stored]);
    }
}
