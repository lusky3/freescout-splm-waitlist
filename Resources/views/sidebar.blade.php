@if (!empty($entries))
    <div class="conv-sidebar-block">
        <div class="panel-group accordion accordion-empty">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title">
                        <a data-toggle="collapse" href=".splmwaitlist-collapse">
                            {{ __('SportsPress Waitlist') }}
                            <b class="caret"></b>
                        </a>
                    </h4>
                </div>
                <div class="splmwaitlist-collapse panel-collapse collapse in">
                    <div class="panel-body">
                        <div class="sidebar-block-header2"><strong>{{ __('SportsPress Waitlist') }}</strong> (<a data-toggle="collapse" href=".splmwaitlist-collapse">{{ __('close') }}</a>)</div>
                        <ul class="sidebar-block-list">
                            @foreach ($entries as $entry)
                                <li>
                                    <div>
                                        {{ $entry['season'] }}
                                        <span class="pull-right {{ $entry['statusClass'] }}">{{ __($entry['statusLabel']) }}</span>
                                    </div>
                                    @if (!empty($entry['detail']))
                                        <div><small class="text-help">{{ $entry['detail'] }}</small></div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
