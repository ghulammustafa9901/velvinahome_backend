<?php

declare(strict_types=1);

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Self-contained (link, store name, validity): rendering needs no database, so the queued
 * message carries everything it shows.
 */
final class CustomerPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $link,
        public readonly string $storeName,
        public readonly int $validMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Reset your :store password', ['store' => $this->storeName]));
    }

    public function content(): Content
    {
        return new Content(view: 'identity::password-reset');
    }
}
