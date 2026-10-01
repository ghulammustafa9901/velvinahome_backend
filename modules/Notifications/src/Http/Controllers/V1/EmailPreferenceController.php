<?php

declare(strict_types=1);

namespace Modules\Notifications\Http\Controllers\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Notifications\Actions\UnsubscribeEmail;

final class EmailPreferenceController
{
    /**
     * Stop reminder emails to an address, from the signed link in a reminder. Order emails
     * are not affected.
     */
    public function unsubscribe(Request $request, UnsubscribeEmail $unsubscribe): Response
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'signature' => ['required', 'string', 'size:64'],
        ]);

        $unsubscribe->handle((string) $data['email'], (string) $data['signature']);

        return response()->noContent();
    }
}
