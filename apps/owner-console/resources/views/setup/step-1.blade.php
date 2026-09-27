@extends('setup.layout')
@section('content')
<p class="lead">Welcome. This wizard initializes your self-hosted platform: it verifies the stack, creates the first platform owner, and locks itself when done. Nothing here touches your data — existing databases are only verified, never modified.</p>
<table class="checks">
@foreach($checks as $c)
  <tr>
    <td class="st {{ $c['status'] }}">{{ $c['status'] }}</td>
    <td><strong>{{ $c['label'] }}</strong><div class="hint">{{ $c['detail'] }}</div></td>
  </tr>
@endforeach
</table>
<div class="actions">
  <span></span>
  <form method="post" action="{{ url('/setup/step/1') }}">@csrf
    <button class="btn">Continue</button>
  </form>
</div>
@endsection
