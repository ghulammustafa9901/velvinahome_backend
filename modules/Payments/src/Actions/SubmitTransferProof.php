<?php

declare(strict_types=1);

namespace Modules\Payments\Actions;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Models\Payment;
use Modules\Support\Contracts\Action;

/**
 * The customer uploads their bank/wallet transfer receipt. It goes to the private
 * disk (never public; staff view it through a signed, expiring URL) and the payment waits for
 * a person to verify it — an uploaded image is not proof of payment either.
 */
final class SubmitTransferProof implements Action
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function handle(string $orderUlid, UploadedFile $receipt, ?string $reference = null): Payment
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($receipt->getRealPath());

        if (! in_array($mime, self::ALLOWED, true) || $receipt->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['receipt' => __('Upload a JPEG, PNG, WebP or PDF receipt of at most 5 MB.')]);
        }

        $payment = Payment::query()->where('order_ulid', $orderUlid)->where('method_code', 'manual_transfer')
            ->whereIn('status', ['pending', 'awaiting_verification'])->latest('id')->first();

        if ($payment === null) {
            throw ValidationException::withMessages(['receipt' => __('This order is not waiting for a transfer.')]);
        }

        $extension = $mime === 'application/pdf' ? 'pdf' : 'img';
        $path = 'payment-proofs/'.strtolower((string) Str::ulid()).'.'.$extension;
        Storage::disk('private')->put($path, (string) file_get_contents($receipt->getRealPath()));

        $payment->forceFill([
            'status' => 'awaiting_verification',
            'proof_path' => $path,
            'gateway_reference' => $reference === null ? $payment->gateway_reference : mb_substr($reference, 0, 120),
        ])->save();

        return $payment;
    }
}
