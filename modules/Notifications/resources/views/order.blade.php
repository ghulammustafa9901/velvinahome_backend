<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c1917;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f4;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;padding:32px;">
                <tr>
                    <td style="font-size:20px;font-weight:600;padding-bottom:16px;">{{ $storeName }}</td>
                </tr>
                <tr>
                    <td style="font-size:15px;line-height:1.6;padding-bottom:24px;">{{ $intro }}</td>
                </tr>
                <tr>
                    <td style="font-size:13px;color:#57534e;padding-bottom:8px;">
                        {{ __('Order') }} <strong>{{ $order['number'] }}</strong> · {{ $order['status_label'] }}
                    </td>
                </tr>
                <tr>
                    <td>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #e7e5e4;font-size:14px;">
                            @foreach ($order['lines'] as $line)
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e7e5e4;">
                                        {{ $line['title'] }}@if ($line['variant_title']) — {{ $line['variant_title'] }}@endif
                                        <span style="color:#78716c;">× {{ $line['quantity'] }}</span>
                                    </td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e7e5e4;white-space:nowrap;">{{ $line['total']->format() }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:6px 0;color:#57534e;">{{ __('Subtotal') }}</td>
                                <td align="right" style="padding:6px 0;">{{ $order['totals']['subtotal']->format() }}</td>
                            </tr>
                            @if ($order['totals']['discount']->isPositive())
                                <tr>
                                    <td style="padding:6px 0;color:#57534e;">{{ __('Discount') }}</td>
                                    <td align="right" style="padding:6px 0;">−{{ $order['totals']['discount']->format() }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:6px 0;color:#57534e;">{{ __('Shipping') }}</td>
                                <td align="right" style="padding:6px 0;">{{ $order['totals']['shipping']->format() }}</td>
                            </tr>
                            @if ($order['totals']['tax']->isPositive())
                                <tr>
                                    <td style="padding:6px 0;color:#57534e;">{{ $order['totals']['tax_inclusive'] ? __('Includes tax') : __('Tax') }}</td>
                                    <td align="right" style="padding:6px 0;">{{ $order['totals']['tax']->format() }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:10px 0;font-weight:600;">{{ __('Total') }}</td>
                                <td align="right" style="padding:10px 0;font-weight:600;">{{ $order['totals']['total']->format() }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @if ($order['payment']['instructions'])
                    <tr>
                        <td style="padding-top:16px;font-size:14px;line-height:1.6;">
                            <strong>{{ __('How to pay') }}</strong><br>
                            {!! nl2br(e($order['payment']['instructions'])) !!}
                        </td>
                    </tr>
                @endif
                @if ($order['shipping_address'])
                    <tr>
                        <td style="padding-top:16px;font-size:14px;line-height:1.5;color:#44403c;">
                            <strong>{{ __('Delivering to') }}</strong><br>
                            {{ $order['shipping_address']['first_name'] }} {{ $order['shipping_address']['last_name'] }}<br>
                            {{ $order['shipping_address']['line1'] }}@if ($order['shipping_address']['line2']), {{ $order['shipping_address']['line2'] }}@endif<br>
                            {{ $order['shipping_address']['city'] }}@if ($order['shipping_address']['province']), {{ $order['shipping_address']['province'] }}@endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding-top:24px;font-size:12px;color:#a8a29e;">{{ $storeName }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
