@if (session('status'))
    <div class="notice" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any() && ! ($hideErrorSummary ?? false))
    <div class="notice error" role="alert">
        <strong>Check the highlighted {{ $errors->count() === 1 ? 'field' : 'fields' }}.</strong>
        <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
