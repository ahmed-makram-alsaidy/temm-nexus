@extends('setup.layout')
@section('content')
<p class="lead">AI is fully optional (bring your own key). Without any provider key the platform works completely — projects, imports, the Migration Center, backups, auth, storage and realtime are unaffected. With a key, the AI Migration Copilot activates.</p>
<div class="note"><strong>Privacy:</strong> nothing is ever sent to an AI provider unless you configure a provider key AND invoke AI features yourself. Keys are added later in <em>Admin → AI Providers</em> and stored encrypted at rest. No key ships with this distribution.</div>
<form method="post" action="{{ url('/setup/step/10') }}">@csrf
  <label class="hint"><input type="checkbox" name="ai_enabled" value="1"> I plan to add an AI provider key (BYOK)</label>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/9') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
