@props(['credential', 'today'])
@php
    $period = $credential->currentPeriod;
    $days = $period?->expiry_date ? \App\Domain\Compliance\ValidityCalculator::daysRemaining($period->expiry_date, $today) : null;
    $status = $credential->current_status;
@endphp
<li class="strip {{ $status->tone() }}">
    <div class="bay">{{ $status->label() }}<small>{{ $credential->type->name }}</small></div>
    <div class="body">
        <a href="{{ route('students.show', $credential->student_id) }}">{{ $credential->student->fullName() }}</a>
        <span class="meta"><span class="num">{{ $credential->student->student_number }}</span> · <span class="num" title="License number">{{ $credential->license_number ?? 'No number' }}</span></span>
    </div>
    <div class="when">
        @if ($period?->expiry_date)
            <strong>{{ $period->expiry_date->format('d M Y') }}</strong>
            <span>@if($days > 0) in {{ $days }} {{ Str::plural('day', $days) }} @elseif($days === 0) expires today @else expired {{ abs($days) }} {{ Str::plural('day', abs($days)) }} ago @endif</span>
        @else
            <strong>—</strong><span>no expiry date</span>
        @endif
    </div>
</li>
