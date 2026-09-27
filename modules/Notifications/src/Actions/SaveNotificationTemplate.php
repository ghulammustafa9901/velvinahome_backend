<?php

declare(strict_types=1);

namespace Modules\Notifications\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Notifications\Models\NotificationTemplate;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Edit a message's wording. Only the known placeholders are allowed, so a typo is caught
 * here instead of reaching customers as a blank.
 */
final class SaveNotificationTemplate implements Action
{
    use ChecksPermission;

    public function handle(NotificationTemplate $template, string $subject, string $intro, bool $isEnabled, ?Authorizable $actor = null): NotificationTemplate
    {
        $this->authorizePermission($actor, 'settings.manage');

        foreach (['subject' => $subject, 'intro' => $intro] as $field => $text) {
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
            $unknown = array_diff($matches[1], NotificationTemplate::PLACEHOLDERS);

            if ($unknown !== []) {
                throw ValidationException::withMessages([$field => __('Unknown placeholder: :names. Available: :available.', [
                    'names' => implode(', ', $unknown),
                    'available' => implode(', ', NotificationTemplate::PLACEHOLDERS),
                ])]);
            }
        }

        $template->fill(['subject' => trim($subject), 'intro' => trim($intro), 'is_enabled' => $isEnabled])->save();

        return $template;
    }
}
