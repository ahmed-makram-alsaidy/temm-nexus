@extends('setup.layout')
@section('content')
<p class="lead">{!! __('setup.s8_lead') !!}</p>
<form method="post" action="{{ url('/setup/step/8') }}">@csrf
  <label class="hint"><input type="checkbox" name="mail_configured" value="1"> {{ __('setup.s8_smtp_confirmed') }}</label>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/7') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
