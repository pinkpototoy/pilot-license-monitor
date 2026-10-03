<x-layouts.guest title="Set password">
    <h1>Set your password</h1>
    <form method="post" action="{{ route('password.update') }}" class="stacked">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email" type="email" :value="$email" required autocomplete="username" />
        <x-field name="password" label="New password" type="password" required autocomplete="new-password" hint="At least 12 characters. A passphrase of several words works well." />
        <x-field name="password_confirmation" label="Repeat new password" type="password" required autocomplete="new-password" />
        <button class="btn">Set password</button>
    </form>
</x-layouts.guest>
