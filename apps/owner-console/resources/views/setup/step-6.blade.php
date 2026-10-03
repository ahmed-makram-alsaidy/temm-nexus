@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s6_lead') }}</p>
@if(($progress[6] ?? '') === 'done')
  <div class="note">{{ __('setup.s6_note') }}</div>
@endif
<form method="post" action="{{ url('/setup/step/6') }}">@csrf
  <label>{{ __('setup.s6_your_name') }}</label>
  <input type="text" name="admin_name" required maxlength="120" autocomplete="name">
  <label>{{ __('setup.s6_email') }}</label>
  <input type="email" name="admin_email" required maxlength="255" autocomplete="email">
  <label>{{ __('setup.s6_password') }}</label>
  <input type="password" name="admin_password" required minlength="12" autocomplete="new-password">
  <label>{{ __('setup.s6_confirm') }}</label>
  <input type="password" name="admin_password_confirmation" required minlength="12" autocomplete="new-password">
  <div class="hint">{{ __('setup.s6_note_owner') }}</div>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/5') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
