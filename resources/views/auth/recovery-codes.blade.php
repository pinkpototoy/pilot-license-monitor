<x-layouts.guest title="Recovery codes">
    <h1>Save your recovery codes</h1>
    <p>Each code works once if you lose access to your authenticator app. Store them somewhere safe. They won't be shown again.</p>
    <ul class="codes">@foreach ($codes as $code)<li>{{ $code }}</li>@endforeach</ul>
    <a class="btn" href="{{ route('home') }}">I've saved them, continue</a>
</x-layouts.guest>
