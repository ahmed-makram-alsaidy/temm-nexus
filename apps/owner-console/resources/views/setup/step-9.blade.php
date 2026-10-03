@extends('setup.layout')
@section('content')
<p class="lead">{!! __('setup.s9_lead') !!}</p>
<div class="note">{!! __('setup.s9_note') !!}</div>
<form method="post" action="{{ url('/setup/step/9') }}">@csrf
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/8') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
