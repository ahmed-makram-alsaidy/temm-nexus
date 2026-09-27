@extends('setup.layout')
@section('content')
<p class="lead">Everything is ready. Completing setup creates the platform owner exactly once, persists the configuration, and locks this wizard permanently.</p>
<div class="note">Re-running or refreshing cannot duplicate the admin or the configuration. If two clients try to complete simultaneously, exactly one succeeds.</div>
@if(($progress[6] ?? '') !== 'done')
  <div class="err">No admin account has been entered yet — go back to step 6.</div>
@endif
<div class="actions"><a class="btn ghost" href="{{ url('/setup/step/11') }}">Back</a>
<form method="post" action="{{ url('/setup/step/12') }}">@csrf<button class="btn">Finish setup</button></form></div>
@endsection
