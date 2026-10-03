@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s5_lead') }}</p>
<table class="checks">
@foreach($checks as $c)
  @if(in_array($c['key'], ['storage', 'directories']))
  <tr><td class="st {{ $c['status'] }}">{{ $c['status'] }}</td>
  <td><strong>{{ $c['label'] }}</strong><div class="hint">{{ $c['detail'] }}</div></td></tr>
  @endif
@endforeach
</table>
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/4') }}">{{ __('setup.back') }}</a>
<form method="post" action="{{ url('/setup/step/5') }}">@csrf<button class="btn">{{ __('setup.continue') }}</button></form></div>
@endsection
