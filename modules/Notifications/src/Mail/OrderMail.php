<?php

declare(strict_types=1);

namespace Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One email layout for every order message: the store's intro text, then the order summary.
 * Sent synchronously from the outbox publisher, which already runs in a queued job after
 * commit, so no mail ever goes out for an order that rolled back.
 */
final class OrderMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $order  OrderBook::describe()
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $intro,
        public readonly array $order,
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

    public function content(): Content
    {
        return new Content(view: 'notifications::order', with: [
            'intro' => $this->intro,
            'order' => $this->order,
            'storeName' => $this->storeName,
        ]);
    }
}
