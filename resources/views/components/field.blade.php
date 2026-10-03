@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false, 'autocomplete' => null])
@php($id = 'f-'.$name)
<div class="field">
    <label for="{{ $id }}">{{ $label }}@unless($required) <span class="muted">(optional)</span>@endunless</label>
    @if ($hint)<span class="hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" @if($required) required @endif
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-err" @enderror>{{ old($name, $value) }}</textarea>
    @elseif ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" @if($required) required @endif @error($name) aria-invalid="true" aria-describedby="{{ $id }}-err" @enderror>
            {{ $slot }}
        </select>
    @else
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}"
            @if($required) required @endif @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if($hint) aria-describedby="{{ $id }}-hint" @endif
            @error($name) aria-invalid="true" aria-describedby="{{ $id }}-err" @enderror>
    @endif
    @error($name)<span class="error" id="{{ $id }}-err">{{ $message }}</span>@enderror
</div>
