@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s4_lead') }}</p>
<table class="checks">
@foreach($checks as $c)
  @if($c['key'] === 'redis')
  <tr><td class="st {{ $c['status'] }}">{{ $c['status'] }}</td>
  <td><strong>{{ $c['label'] }}</strong><div class="hint">{{ $c['detail'] }}</div></td></tr>
  @endif
@endforeach
</table>
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/3') }}">{{ __('setup.back') }}</a>
<form method="post" action="{{ url('/setup/step/4') }}">@csrf<button class="btn">{{ __('setup.continue') }}</button></form></div>
@endsection
