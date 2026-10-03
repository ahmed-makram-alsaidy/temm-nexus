<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Services\Localization\LocaleManager::direction() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup complete</title>
<style>
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Naskh Arabic UI", "Noto Sans Arabic", sans-serif;
         background:#0f172a; color:#e2e8f0; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .card { background:#1e293b; border:1px solid #334155; border-radius:14px; padding:44px; max-width:520px; text-align:center; }
  h1 { color:#4ade80; font-size:24px; margin-top:0; }
  p { color:#94a3b8; line-height:1.6; }
  a.btn { display:inline-block; margin-top:14px; padding:11px 28px; border-radius:9px; background:#4f46e5;
          color:#fff; font-weight:600; text-decoration:none; }
  .v { font-size:12px; color:#64748b; margin-top:22px; }
</style>
</head>
<body>
  <div class="card">
    <h1>{{ __('setup.complete_title') }}</h1>
    <p>{{ __('setup.complete_body', ['platform' => $platform, 'owner' => $owner]) }}</p>
    <a class="btn" href="{{ url('/admin/login') }}">{{ __('setup.complete_sign_in') }}</a>
    <div class="v">Platform v{{ $version }}</div>
  </div>
</body>
</html>
