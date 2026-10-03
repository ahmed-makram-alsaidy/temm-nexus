@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s10_lead') }}</p>
<div class="note">{!! __('setup.s10_privacy_note') !!}</div>
<form method="post" action="{{ url('/setup/step/10') }}">@csrf
  <label class="hint"><input type="checkbox" name="ai_enabled" value="1"> {{ __('setup.s10_byok') }}</label>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/9') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
