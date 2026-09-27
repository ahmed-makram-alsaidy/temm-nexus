@extends('setup.layout')
@section('content')
<p class="lead">Name your platform. These values brand the admin UI and outbound messages. Defaults are generic and safe to keep.</p>
<form method="post" action="{{ url('/setup/step/2') }}">@csrf
  <label>Platform name</label>
  <input type="text" name="platform_name" required maxlength="80" value="{{ $settings['name'] }}">
  <label>Brand name (optional — used in the UI header)</label>
  <input type="text" name="brand_name" maxlength="80" value="{{ $settings['brand'] }}">
  <label>Support URL (optional)</label>
  <input type="url" name="support_url" placeholder="https://support.example.com" value="">
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/1') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
