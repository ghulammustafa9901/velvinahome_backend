<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c1917;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;padding:32px;">
                <tr><td style="font-size:20px;font-weight:600;padding-bottom:16px;">{{ $storeName }}</td></tr>
                <tr>
                    <td style="font-size:15px;line-height:1.6;padding-bottom:24px;">
                        {{ __('We received a request to reset the password for your account. The link below works once and expires in :minutes minutes.', ['minutes' => $validMinutes]) }}
                    </td>
                </tr>
                <tr>
                    <td style="padding-bottom:24px;">
                        <a href="{{ $link }}" style="display:inline-block;background:#1c1917;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">{{ __('Choose a new password') }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="font-size:13px;color:#78716c;line-height:1.6;">
                        {{ __('If you did not ask for this, you can ignore this email — your password stays the same.') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
