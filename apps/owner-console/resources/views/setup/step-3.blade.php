@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s3_lead') }}</p>
<table class="checks">
@foreach($checks as $c)
  @if(in_array($c['key'], ['database', 'migrations']))
  <tr><td class="st {{ $c['status'] }}">{{ $c['status'] }}</td>
  <td><strong>{{ $c['label'] }}</strong><div class="hint">{{ $c['detail'] }}</div></td></tr>
  @endif
@endforeach
</table>
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/2') }}">{{ __('setup.back') }}</a>
<form method="post" action="{{ url('/setup/step/3') }}">@csrf<button class="btn">{{ __('setup.continue') }}</button></form></div>
@endsection
