<x-layouts.guest title="Two-step verification">
    <h1>Enter your code</h1>
    <p>Open your authenticator app and enter the 6-digit code. Lost your phone? Enter one of your recovery codes instead.</p>
    <form method="post" action="{{ url('/mfa/challenge') }}" class="stacked">
        @csrf
        <x-field name="code" label="Code" required autocomplete="one-time-code" />
        <button class="btn">Verify</button>
    </form>
</x-layouts.guest>
