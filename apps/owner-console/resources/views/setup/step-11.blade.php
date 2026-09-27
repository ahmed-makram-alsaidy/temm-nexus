@extends('setup.layout')
@section('content')
<p class="lead">Review the security posture applied by this installation.</p>
<ul class="summary">
  <li>No default credentials exist — the owner account is created only through this wizard.</li>
  <li>Secrets (Supabase PATs, AI keys, project secrets) are encrypted at rest.</li>
  <li>PostgreSQL and Redis are internal-only; ports 80/443 are the public surface.</li>
  <li>Login, setup and sensitive endpoints are rate-limited.</li>
  <li>No telemetry: nothing leaves this server unless you invoke AI with your own key.</li>
  <li>Admin debug output is disabled in production (APP_DEBUG=false).</li>
</ul>
<form method="post" action="{{ url('/setup/step/11') }}">@csrf
  <label class="hint"><input type="checkbox" name="security_ack" value="1" required> I understand and accept this configuration for my installation</label>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/10') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
