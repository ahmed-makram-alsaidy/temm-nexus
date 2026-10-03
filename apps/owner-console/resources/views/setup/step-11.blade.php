@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s11_lead') }}</p>
<ul class="summary">
    @foreach(__('setup.s11_items') as $item)
      <li>{{ $item }}</li>
    @endforeach
  </ul>
<form method="post" action="{{ url('/setup/step/11') }}">@csrf
  <label class="hint"><input type="checkbox" name="security_ack" value="1" required> {{ __('setup.s11_ack') }}</label>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/10') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
