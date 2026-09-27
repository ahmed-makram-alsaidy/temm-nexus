@extends('setup.layout')
@section('content')
<p class="lead">Outgoing email is optional. Without it, the platform operates normally — mail-dependent features (password reset emails, notification channels) report <strong>NOT CONFIGURED</strong> instead of failing. You can add SMTP later.</p>
<form method="post" action="{{ url('/setup/step/8') }}">@csrf
  <label class="hint"><input type="checkbox" name="mail_configured" value="1"> SMTP is configured in my .env (MAIL_MAILER/MAIL_HOST/…)</label>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/7') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
