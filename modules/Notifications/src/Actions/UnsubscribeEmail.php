<?php

declare(strict_types=1);

namespace Modules\Notifications\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Notifications\Support\EmailPreferences;
use Modules\Support\Contracts\Action;

/**
 * An unsubscribe link from a reminder email. The signature proves the link came from us, so
 * nobody can unsubscribe someone else by typing their address. Idempotent.
 */
final class UnsubscribeEmail implements Action
{
    public function handle(string $email, string $signature): void
    {
        if (! hash_equals(EmailPreferences::signature($email), $signature)) {
            throw ValidationException::withMessages(['signature' => __('This unsubscribe link is not valid.')]);
        }

        DB::table('email_suppressions')->insertOrIgnore([
            'email_hash' => EmailPreferences::hash($email),
            'reason' => 'unsubscribed',
            'created_at' => now(),
        ]);
    }
}
