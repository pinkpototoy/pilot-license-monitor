<x-layouts.guest title="Reset password">
    <h1>Reset your password</h1>
    <p>Enter your account email. We'll send a link that works once and expires in 30 minutes.</p>
    <form method="post" action="{{ route('password.email') }}" class="stacked">
        @csrf
        <x-field name="email" label="Email" type="email" required autocomplete="username" />
        <div class="actions"><button class="btn">Send reset link</button><a href="{{ route('login') }}">Back to sign in</a></div>
    </form>
</x-layouts.guest>
