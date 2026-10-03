@props(['date', 'today'])
@if ($date)
    @php
        $d = \App\Domain\Compliance\ValidityCalculator::daysRemaining($date, $today);
        $rel = $d > 0 ? 'in '.$d.' '.Str::plural('day', $d) : ($d === 0 ? 'today' : abs($d).' '.Str::plural('day', abs($d)).' ago');
    @endphp
    <span class="num">{{ $date->format('d M Y') }}</span> <span class="muted">({{ $rel }})</span>
@else
    <span class="muted">Not recorded</span>
@endif
