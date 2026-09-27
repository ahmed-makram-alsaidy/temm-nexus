<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ config('platform.brand') }}</title>
<style>
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background:#0f172a; color:#e2e8f0; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .card { background:#1e293b; border:1px solid #334155; border-radius:14px; padding:48px; max-width:560px; text-align:center; }
  h1 { color:#f8fafc; font-size:26px; margin:0 0 10px; }
  p { color:#94a3b8; line-height:1.65; }
  a.btn { display:inline-block; margin:18px 6px 0; padding:11px 26px; border-radius:9px; background:#4f46e5;
          color:#fff; font-weight:600; text-decoration:none; }
  .v { font-size:12px; color:#64748b; margin-top:26px; }
</style>
</head>
<body>
  <div class="card">
    <h1>{{ config('platform.brand') }}</h1>
    <p>Self-hosted backend &amp; migration control plane. Create new backend projects, import existing ones via source connectors (Supabase included), and operate them from one console.</p>
    <a class="btn" href="{{ url('/admin') }}">Open the console</a>
    <div class="v">Platform v{{ config('platform.version') }}</div>
  </div>
</body>
</html>
