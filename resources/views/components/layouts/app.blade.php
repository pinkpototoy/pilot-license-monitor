<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Pilot License Monitor' }} · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&family=B612:wght@400;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
@php
    $user = auth()->user();
    $unread = \App\Models\OutboundNotification::where('user_id', $user->id)->where('channel', 'in_app')
        ->whereIn('status', ['sent', 'pending'])->whereNull('read_at')->count();
    $toVerify = $user->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::Staff)
        ? \App\Models\RenewalCase::whereIn('status', ['submitted', 'resubmitted'])->count() : 0;
@endphp
@if ($user->role->isStaffSide())
    <div class="shell">
        <nav class="nav" aria-label="Main">
            <div class="brand">Pilot License Monitor<small>Records &amp; compliance</small></div>
            <ul>
                <li><a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a></li>
                <li><a href="{{ route('students.index') }}" @if(request()->routeIs('students.*', 'credentials.*')) aria-current="page" @endif>Students</a></li>
                @if ($user->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::Staff))
                    <li><a href="{{ route('renewals.queue') }}" @if(request()->routeIs('renewals.*')) aria-current="page" @endif>Verification @if($toVerify)<span class="count">{{ $toVerify }}<span class="visually-hidden"> waiting</span></span>@endif</a></li>
                @endif
                <li><a href="{{ route('reports.index') }}" @if(request()->routeIs('reports.*')) aria-current="page" @endif>Reports</a></li>
                @if ($user->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::Staff))
                    <li><a href="{{ route('imports.index') }}" @if(request()->routeIs('imports.*')) aria-current="page" @endif>Import</a></li>
                @endif
                <li><a href="{{ route('inbox') }}" @if(request()->routeIs('inbox', 'preferences')) aria-current="page" @endif>Inbox @if($unread)<span class="count">{{ $unread }}<span class="visually-hidden"> unread</span></span>@endif</a></li>
                @can('configure-system')
                    <li class="group">Administration</li>
                    <li><a href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users*')) aria-current="page" @endif>Users</a></li>
                    <li><a href="{{ route('admin.credential-types') }}" @if(request()->routeIs('admin.credential-types*')) aria-current="page" @endif>Credentials &amp; checklists</a></li>
                    <li><a href="{{ route('admin.reminders') }}" @if(request()->routeIs('admin.reminders')) aria-current="page" @endif>Reminders</a></li>
                    <li><a href="{{ route('audit.index') }}" @if(request()->routeIs('audit.*')) aria-current="page" @endif>Audit log</a></li>
                @endcan
            </ul>
            <div class="who">
                <div><strong>{{ $user->name }}</strong>{{ $user->role->label() }}</div>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="btn secondary small">Sign out</button></form>
            </div>
        </nav>
        <main class="main" id="content">
            @include('partials.messages')
            {{ $slot }}
        </main>
    </div>
@else
    <header class="top">
        <div class="wrap">
            <a class="brand" href="{{ route('my.records') }}">Pilot License Monitor</a>
            <nav class="actions" aria-label="Main">
                <a href="{{ route('my.records') }}">My licenses</a>
                <a href="{{ route('inbox') }}">Inbox @if($unread)<span class="count">{{ $unread }}<span class="visually-hidden"> unread</span></span>@endif</a>
                <a href="{{ route('preferences') }}">Settings</a>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="btn secondary small">Sign out</button></form>
            </nav>
        </div>
    </header>
    <main class="narrow" id="content">
        @include('partials.messages')
        {{ $slot }}
    </main>
@endif
</body>
</html>
