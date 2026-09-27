@extends('setup.layout')
@section('content')
<p class="lead">Public address of this installation. Use your domain if one points here; otherwise the server IP works for bootstrap.</p>
<div class="note"><strong>HTTPS, honestly:</strong> a trusted TLS certificate requires a real domain with DNS pointing at this server. With only an IP address, the platform runs over plain HTTP — fine for a quick evaluation, not for production data. Configure DNS, then set the domain here (Caddy issues certificates automatically for reachable domains).</div>
<form method="post" action="{{ url('/setup/step/7') }}">@csrf
  <label>Platform URL</label>
  <input type="url" name="platform_url" required placeholder="https://panel.example.com" value="{{ $settings['url'] ?: config('app.url') }}">
  <div class="hint">Should match APP_URL in your .env. Change .env too if you alter the hostname later.</div>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/6') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
