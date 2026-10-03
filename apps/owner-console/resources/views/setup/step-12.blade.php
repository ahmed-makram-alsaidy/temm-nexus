@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s12_lead') }}</p>
<div class="note">{{ __('setup.s12_note') }}</div>
@if(($progress[6] ?? '') !== 'done')
  <div class="err">{{ __('setup.s12_no_admin') }}</div>
@endif
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/11') }}">{{ __('setup.back') }}</a>
<form method="post" action="{{ url('/setup/step/12') }}">@csrf<button class="btn">{{ __('setup.finish') }}</button></form></div>
@endsection
