@extends('setup.layout')
@section('content')
<p class="lead">The first platform owner. There is no default password — choose a strong one (12+ chars with upper &amp; lower case, numbers, symbols). Credentials are stored encrypted until final completion and created exactly once.</p>
@if(($progress[6] ?? '') === 'done')
  <div class="note">An admin account has been entered for this setup. Re-submitting will replace the pending credentials; the final owner is created only once at completion.</div>
@endif
<form method="post" action="{{ url('/setup/step/6') }}">@csrf
  <label>Your name</label>
  <input type="text" name="admin_name" required maxlength="120" autocomplete="name">
  <label>Email</label>
  <input type="email" name="admin_email" required maxlength="255" autocomplete="email">
  <label>Password</label>
  <input type="password" name="admin_password" required minlength="12" autocomplete="new-password">
  <label>Confirm password</label>
  <input type="password" name="admin_password_confirmation" required minlength="12" autocomplete="new-password">
  <div class="hint">This account becomes the platform owner with full control.</div>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/5') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
