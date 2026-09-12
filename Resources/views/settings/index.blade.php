@if (session('success'))
    <div class="alert alert-success alert-dismissible" role="alert" aria-live="polite">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        {{ session('success') }}
    </div>
@endif

<div class="panel panel-default">
    <div class="panel-heading">{{ __('SportsPress Waitlist Status') }}</div>
    <div class="panel-body">
        <p class="text-muted">{{ __('Connects to the SportsPress site\'s waitlist REST endpoint so this customer\'s waitlist status shows in the conversation sidebar.') }}</p>

        <form method="post" action="{{ route('splmwaitlist_save_settings') }}">
            {{ csrf_field() }}
            <div class="form-group {{ $errors->has('wp_base_url') ? 'has-error' : '' }}">
                <label for="splmwaitlist_wp_base_url">{{ __('SportsPress Site URL') }}</label>
                <input
                    type="text"
                    id="splmwaitlist_wp_base_url"
                    name="wp_base_url"
                    class="form-control"
                    placeholder="https://example.com"
                    value="{{ old('wp_base_url', $wpBaseUrl) }}"
                    required
                >
                @if ($errors->has('wp_base_url'))
                    <span class="help-block">{{ $errors->first('wp_base_url') }}</span>
                @endif
            </div>

            <div class="form-group {{ $errors->has('shared_secret') ? 'has-error' : '' }}">
                <label for="splmwaitlist_shared_secret">{{ __('Shared Secret') }}</label>
                <input
                    type="password"
                    id="splmwaitlist_shared_secret"
                    name="shared_secret"
                    class="form-control"
                    autocomplete="off"
                    value="{{ $hasSecret ? str_repeat('•', 16) : '' }}"
                >
                <label style="font-weight: normal; margin-top: 6px;">
                    <input type="checkbox" onclick="document.getElementById('splmwaitlist_shared_secret').type = this.checked ? 'text' : 'password';">
                    {{ __('Show') }}
                </label>
                <span class="help-block">{{ __('Must match the FreeScout Shared Secret configured on the SportsPress site. Minimum 32 characters. Leave the bullets in place to keep the existing secret.') }}</span>
                @if ($errors->has('shared_secret'))
                    <span class="help-block">{{ $errors->first('shared_secret') }}</span>
                @endif
            </div>

            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </form>
    </div>
</div>
