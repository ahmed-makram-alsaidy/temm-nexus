@extends('setup.layout')
@section('content')
<p class="lead">Storage holds uploaded project files and private platform state. Persistence is provided by the Docker volume.</p>
<table class="checks">
@foreach($checks as $c)
  @if(in_array($c['key'], ['storage', 'directories']))
  <tr><td class="st {{ $c['status'] }}">{{ $c['status'] }}</td>
  <td><strong>{{ $c['label'] }}</strong><div class="hint">{{ $c['detail'] }}</div></td></tr>
  @endif
@endforeach
</table>
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/4') }}">Back</a>
<form method="post" action="{{ url('/setup/step/5') }}">@csrf<button class="btn">Continue</button></form></div>
@endsection
