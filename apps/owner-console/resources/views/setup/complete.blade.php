<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup complete</title>
<style>
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
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
    <h1>Setup complete</h1>
    <p>{{ $platform }} is initialized. Sign in with the owner account you created ({{ $owner }}). This wizard is now locked.</p>
    <a class="btn" href="{{ url('/admin/login') }}">Sign in to the admin console</a>
    <div class="v">Platform v{{ $version }}</div>
  </div>
</body>
</html>
