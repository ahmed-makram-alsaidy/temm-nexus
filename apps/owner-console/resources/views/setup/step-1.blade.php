@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s1_lead') }}</p>
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
    <button class="btn">{{ __("setup.continue") }}</button>
  </form>
</div>
@endsection
