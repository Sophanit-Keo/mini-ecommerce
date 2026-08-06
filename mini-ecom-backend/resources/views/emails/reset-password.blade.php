@component('emails.layout', ['subject' => 'Reset your '.config('app.name').' password'])
    <p style="margin-top:0;">Reset your password</p>
    <p>We received a request to reset the password for your account. Click the button below to choose a new one:</p>

    <div style="text-align:center; margin:28px 0;">
        <a href="{{ $url }}" style="display:inline-block; background-color:#1f9d55; color:#ffffff; font-weight:600; font-size:15px; text-decoration:none; padding:14px 28px; border-radius:8px;">
            Reset Password
        </a>
    </div>

    <p>This link expires in {{ $count }} minutes. If you did not request a password reset, no further action is required.</p>
    <p style="color:#8896a4; font-size:13px; word-break:break-all;">If the button doesn't work, copy and paste this URL into your browser: {{ $url }}</p>
@endcomponent
