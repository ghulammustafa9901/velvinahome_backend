<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use Illuminate\Support\Facades\DB;

/**
 * The suppression list for non-essential email (cart reminders; order emails are always
 * sent), and the signature that lets an unsubscribe link work without a login.
 */
final class EmailPreferences
{
    public static function hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public static function signature(string $email): string
    {
        return hash_hmac('sha256', 'unsubscribe|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public static function suppressed(string $email): bool
    {
        return DB::table('email_suppressions')->where('email_hash', self::hash($email))->exists();
    }
}
