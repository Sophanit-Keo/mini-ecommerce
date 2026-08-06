@component('emails.layout', ['subject' => 'Your '.config('app.name').' verification code'])
    <p style="margin-top:0;">Confirm your email address</p>
    <p>Enter this code in the app to verify your email address:</p>

    <div style="text-align:center; margin:28px 0;">
        <span style="display:inline-block; font-size:32px; font-weight:700; letter-spacing:0.3em; color:#1f9d55; background-color:#eafbf1; padding:16px 24px; border-radius:8px;">
            {{ $code }}
        </span>
    </div>

    <p>This code expires in <strong>10 minutes</strong>.</p>
    <p style="color:#8896a4; font-size:13px;">If you did not create an account, no further action is required.</p>
@endcomponent
