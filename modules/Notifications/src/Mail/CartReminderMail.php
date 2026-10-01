<?php

declare(strict_types=1);

namespace Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Modules\Support\ValueObjects\Money;

final class CartReminderMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{title: string, variant_title: string|null, quantity: int, total: Money, image_url: string|null}>  $lines
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $intro,
        public readonly array $lines,
        public readonly Money $total,
        public readonly string $recoverUrl,
        public readonly string $unsubscribeUrl,
        public readonly string $storeName,
        public readonly ?string $fromAddress,
    ) {}

    public function envelope(): Envelope
    {
        $address = $this->fromAddress ?? config('mail.from.address');

        return new Envelope(
            from: is_string($address) ? new Address($address, $this->storeName) : null,
            subject: $this->subjectLine,
        );
    }

    /**
     * Mail clients show their own unsubscribe button (RFC 8058).
     */
    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>']);
    }

    public function content(): Content
    {
        return new Content(view: 'notifications::cart-reminder', with: [
            'intro' => $this->intro,
            'lines' => $this->lines,
            'total' => $this->total,
            'recoverUrl' => $this->recoverUrl,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'storeName' => $this->storeName,
        ]);
    }
}
