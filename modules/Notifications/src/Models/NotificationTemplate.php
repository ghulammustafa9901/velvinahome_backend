<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $subject
 * @property string $intro
 * @property bool $is_enabled
 */
final class NotificationTemplate extends Model
{
    /**
     * Placeholders a template may use, as {{ name }}. Values are inserted as plain text and
     * escaped when rendered; templates cannot inject markup. Staff-written reasons (e.g. why
     * an order was cancelled) are deliberately not available.
     */
    public const PLACEHOLDERS = ['store_name', 'customer_name', 'order_number', 'order_total', 'courier', 'tracking_number', 'refund_amount'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn (array $match): string => (string) ($values[$match[1]] ?? ''), $text);
    }
}
