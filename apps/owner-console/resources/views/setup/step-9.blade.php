@extends('setup.layout')
@section('content')
<p class="lead">Backups protect every project database and the platform database. The Backup Center in the admin UI configures destinations, schedules and retention; the included scripts can stage <code>pg_dump</code> files under the backup volume.</p>
<div class="note">Recommended before going live: create a backup destination in <em>Backup Center</em>, run one manual backup, and verify a restore. See <code>docs/BACKUP.md</code>.</div>
<form method="post" action="{{ url('/setup/step/9') }}">@csrf
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/8') }}">Back</a><button class="btn">Continue</button></div>
</form>
@endsection
