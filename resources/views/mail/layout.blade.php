<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:24px 16px;background:#F5EDE6;font-family:Arial,Helvetica,sans-serif;color:#111111;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;margin:0 auto;">
        <tr>
            <td style="padding-bottom:16px;font-size:28px;font-weight:bold;">Loomora</td>
        </tr>
        <tr>
            <td style="background:#FFFFFF;border:3px solid #111111;box-shadow:6px 6px 0 #111111;padding:24px;">
                {{ $slot }}
            </td>
        </tr>
        <tr>
            <td style="padding-top:16px;font-size:12px;color:#444444;">{{ __('mail.footer') }}</td>
        </tr>
    </table>
</body>
</html>
