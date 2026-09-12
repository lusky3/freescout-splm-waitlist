@if (!empty($lines))
    <div class="sidebar-block-inner">
        <div style="font-weight: 600; margin-bottom: 4px;">{{ __('SportsPress Waitlist') }}</div>
        @foreach ($lines as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>
@endif
