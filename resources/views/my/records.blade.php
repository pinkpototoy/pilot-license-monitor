@php
    $state = $student->compliance_state;
    $worst = $student->currentCredentials->sortBy(fn ($c) => array_search($c->current_status->value, ['expired','incomplete_data','expiring_soon','not_yet_valid','active']))->first();
@endphp
<x-layouts.app title="My licenses">
    <h1>Hello, {{ $student->first_name }}</h1>

    @if ($state->value === 'compliant')
        <div class="notice" role="status"><strong>You're all set.</strong> Every required license and certificate is valid.</div>
    @elseif ($worst && in_array($worst->current_status->value, ['expired', 'expiring_soon']))
        @php($d = $worst->currentPeriod?->expiry_date ? \App\Domain\Compliance\ValidityCalculator::daysRemaining($worst->currentPeriod->expiry_date, $today) : null)
        <div class="notice error" role="alert"><strong>Action needed:</strong> your {{ $worst->type->name }}
            @if ($d !== null && $d < 0) expired {{ abs($d) }} {{ Str::plural('day', abs($d)) }} ago. @elseif ($d === 0) expires today. @else expires in {{ $d }} {{ Str::plural('day', $d) }}. @endif
            Renew it with the issuing authority, then start a renewal below and upload the renewed document.</div>
    @else
        <div class="notice warn" role="status">Some of your records are incomplete. The records office will contact you, or you can visit them to update your details.</div>
    @endif

    <h2>Your licenses and certificates</h2>
    @forelse ($student->currentCredentials as $c)
        <article class="credential tone-{{ $c->current_status->tone() }}">
            <header><h3>{{ $c->type->name }}</h3><div class="actions"><x-badge :status="$c->current_status" />@if($c->openRenewalCase)<x-badge :status="$c->openRenewalCase->status" />@endif</div></header>
            <div class="inner">
                <dl class="facts">
                    <dt>Number</dt><dd class="num">{{ $c->license_number ? Str::mask($c->license_number, '•', 0, max(0, strlen($c->license_number) - 4)) : 'Not recorded' }}</dd>
                    <dt>Issued</dt><dd>{{ $c->currentPeriod?->issue_date?->format('d M Y') ?? 'Not recorded' }}</dd>
                    <dt>Expires</dt><dd><x-relative-date :date="$c->currentPeriod?->expiry_date" :today="$today" /></dd>
                </dl>
                <div class="actions">
                    @if ($c->openRenewalCase)
                        <a class="btn" href="{{ route('renewals.show', $c->openRenewalCase) }}">
                            {{ $c->openRenewalCase->status->value === 'needs_correction' ? 'Fix rejected documents' : ($c->openRenewalCase->status->value === 'draft' ? 'Continue renewal' : 'View renewal progress') }}</a>
                    @elseif (in_array($c->current_status->value, ['expiring_soon', 'expired', 'incomplete_data'], true))
                        <form method="post" action="{{ route('renewals.open', $c) }}">@csrf<button class="btn">Start renewal</button></form>
                    @else
                        <span class="muted">Renewal opens {{ $c->currentPeriod?->expiry_date?->subDays($c->type->expiring_soon_days)->format('d M Y') ?? 'when dates are recorded' }}.</span>
                    @endif
                </div>
                @if ($c->type->requirements->isNotEmpty())
                    <details><summary>Documents needed to renew</summary>
                        <ul>@foreach ($c->type->requirements as $r)<li>{{ $r->documentType->name }}@unless($r->mandatory) <span class="muted">(if applicable)</span>@endunless</li>@endforeach</ul>
                    </details>
                @endif
            </div>
        </article>
    @empty
        <p class="empty">No licenses are recorded for you yet. Contact the records office if you already hold one.</p>
    @endforelse
</x-layouts.app>
