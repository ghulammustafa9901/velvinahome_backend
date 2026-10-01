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
                    <td>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #e7e5e4;font-size:14px;">
                            @foreach ($lines as $line)
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #e7e5e4;">
                                        {{ $line['title'] }}@if ($line['variant_title']) — {{ $line['variant_title'] }}@endif
                                        <span style="color:#78716c;">× {{ $line['quantity'] }}</span>
                                    </td>
                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #e7e5e4;white-space:nowrap;">{{ $line['total']->format() }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:10px 0;font-weight:600;">{{ __('Total before delivery') }}</td>
                                <td align="right" style="padding:10px 0;font-weight:600;">{{ $total->format() }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:28px 0 8px;">
                        <a href="{{ $recoverUrl }}" style="display:inline-block;background:#1c1917;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">{{ __('Return to your cart') }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:12px;color:#78716c;padding-top:24px;line-height:1.5;">
                        {{ __('Prices and stock are checked again when you return.') }}
                        <a href="{{ $unsubscribeUrl }}" style="color:#78716c;">{{ __('No more reminders') }}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
