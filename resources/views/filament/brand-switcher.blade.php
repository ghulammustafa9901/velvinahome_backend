@if ($brands !== [])
    <div class="fi-brand-switcher" style="display:flex;gap:.5rem;align-items:center;margin-inline-end:.75rem;">
        <label for="brand-switcher" class="fi-brand-switcher-label" style="font-size:.75rem;opacity:.7;">{{ __('Brand') }}</label>
        <select id="brand-switcher"
                onchange="if (this.value) { window.location.href = this.value; }"
                style="font-size:.875rem;border-radius:.5rem;padding:.25rem 1.75rem .25rem .5rem;">
            <option value="" selected>{{ $current }}</option>
            @foreach ($brands as $brand)
                <option value="{{ url('/_switch/'.$brand['id']) }}">{{ $brand['name'] }}</option>
            @endforeach
        </select>
    </div>
@endif
