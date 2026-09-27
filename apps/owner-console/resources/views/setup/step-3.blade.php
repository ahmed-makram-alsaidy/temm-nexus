@extends('setup.layout')
@section('content')
<p class="lead">Server-side connectivity check against your PostgreSQL instance.</p>
<table class="checks">
@foreach($checks as $c)
  @if(in_array($c['key'], ['database', 'migrations']))
  <tr><td class="st {{ $c['status'] }}">{{ $c['status'] }}</td>
  <td><strong>{{ $c['label'] }}</strong><div class="hint">{{ $c['detail'] }}</div></td></tr>
  @endif
@endforeach
</table>
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/2') }}">Back</a>
<form method="post" action="{{ url('/setup/step/3') }}">@csrf<button class="btn">Continue</button></form></div>
@endsection
