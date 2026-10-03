@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s7_lead') }}</p>
<div class="note">{!! __('setup.s7_https_note') !!}</div>
<form method="post" action="{{ url('/setup/step/7') }}">@csrf
  <label>{{ __('setup.s7_platform_url') }}</label>
  <input type="url" name="platform_url" required placeholder="https://panel.example.com" value="{{ $settings['url'] ?: config('app.url') }}">
  <div class="hint">{{ __('setup.s7_url_hint') }}</div>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/6') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
