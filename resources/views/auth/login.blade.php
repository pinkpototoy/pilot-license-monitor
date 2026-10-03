<x-layouts.guest title="Sign in">
    <h1>Sign in</h1>
    <form method="post" action="{{ url('/login') }}" class="stacked" novalidate>
        @csrf
        <x-field name="email" label="Email" type="email" required autocomplete="username" />
        <x-field name="password" label="Password" type="password" required autocomplete="current-password" />
        <div class="actions">
            <button class="btn">Sign in</button>
            <a href="{{ route('password.request') }}">Forgot your password?</a>
        </div>
    </form>
</x-layouts.guest>
