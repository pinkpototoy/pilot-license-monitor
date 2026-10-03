@props(['status'])
{{-- UX-01: text label + colour + shape. Colour is never the only signal. --}}
@if ($status)
<span {{ $attributes->merge(['class' => 'badge '.$status->tone()]) }}>{{ $status->label() }}</span>
@endif
