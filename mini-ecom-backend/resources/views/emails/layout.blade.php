<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f5; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td align="center" style="background-color:#1f9d55; padding:32px 24px;">
                            {{-- Embedded as a data URI, not linked via asset(): mail clients (Gmail included)
                                 fetch <img src> URLs from their own servers, which can never reach a
                                 local-dev APP_URL like http://localhost:8000 — the image would render as
                                 a broken placeholder for every recipient until this app has a public URL. --}}
                            <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/logo.png'))) }}" alt="{{ config('app.name') }}" width="64" height="64" style="display:block; border-radius:16px;">
                            <div style="color:#ffffff; font-size:20px; font-weight:700; margin-top:12px;">{{ config('app.name') }}</div>
                            <div style="color:#d9f2e3; font-size:13px; margin-top:4px;">Fresh groceries, delivered to your door.</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px; color:#1f2933; font-size:15px; line-height:1.6;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 28px; background-color:#f4f6f5; color:#8896a4; font-size:12px; text-align:center;">
                            &copy; {{ date('Y') }} {{ config('app.name') }}. If you didn't expect this email, you can safely ignore it.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
