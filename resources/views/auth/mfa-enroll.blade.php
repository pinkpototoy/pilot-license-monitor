<x-layouts.guest title="Set up two-step verification">
    <h1>Set up two-step verification</h1>
    <p>Staff accounts need a second step to protect student records. Scan this code with an authenticator app such as Google Authenticator or Microsoft Authenticator.</p>
    <div class="qr">
        {!! $qr !!}
        <div>
            <p class="muted">Can't scan? Enter this key:</p>
            <p class="num">{{ $secret }}</p>
        </div>
    </div>
    <form method="post" action="{{ url('/mfa/enroll') }}" class="stacked">
        @csrf
        <x-field name="code" label="6-digit code from the app" required autocomplete="one-time-code" />
        <button class="btn">Turn on two-step verification</button>
    </form>
</x-layouts.guest>
