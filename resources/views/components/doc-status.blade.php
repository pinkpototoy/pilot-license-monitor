@props(['doc' => null, 'mandatory' => true])
@php
    [$tone, $label] = match (true) {
        $doc === null => $mandatory ? ['grey', 'Not uploaded'] : ['grey', 'Optional'],
        $doc->scan_status === 'pending' => ['orange', 'Scanning'],
        $doc->scan_status === 'infected' => ['red', 'Blocked by virus scan'],
        $doc->scan_status === 'error' => ['grey', 'Scan failed'],
        $doc->verification_status === 'approved' => ['green', 'Approved'],
        $doc->verification_status === 'rejected' => ['red', 'Rejected'],
        default => ['orange', 'Waiting for review'],
    };
@endphp
<span class="badge {{ $tone }}">{{ $label }}</span>
