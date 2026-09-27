<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup — {{ $settings['brand'] }} · Step {{ $step }}/{{ $total }}</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background:#0f172a; color:#e2e8f0; min-height:100vh; }
  .wrap { max-width:860px; margin:0 auto; padding:48px 24px 96px; }
  .brand { font-size:13px; letter-spacing:.12em; text-transform:uppercase; color:#818cf8; margin-bottom:6px; }
  h1 { font-size:26px; margin:0 0 20px; color:#f8fafc; }
  .steps { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 28px; padding:0; list-style:none; }
  .steps li { font-size:11px; padding:4px 9px; border-radius:999px; border:1px solid #334155; color:#64748b; }
  .steps li.done { background:#1e293b; color:#a5b4fc; border-color:#4338ca; }
  .steps li.now { background:#4338ca; color:#fff; border-color:#4338ca; }
  .card { background:#1e293b; border:1px solid #334155; border-radius:14px; padding:26px; }
  p.lead { color:#94a3b8; line-height:1.6; margin-top:0; }
  table.checks { width:100%; border-collapse:collapse; font-size:14px; }
  table.checks td { padding:9px 8px; border-bottom:1px solid #334155; vertical-align:top; }
  td.st { font-weight:700; width:70px; }
  .st.PASS { color:#4ade80; } .st.FAIL { color:#f87171; } .st.INFO { color:#fbbf24; }
  label { display:block; font-size:13px; color:#94a3b8; margin:16px 0 6px; }
  input[type=text], input[type=email], input[type=password], input[type=url] {
      width:100%; padding:10px 12px; border-radius:9px; border:1px solid #475569;
      background:#0f172a; color:#e2e8f0; font-size:15px; }
  .hint { font-size:12px; color:#64748b; margin-top:5px; line-height:1.5; }
  .actions { margin-top:26px; display:flex; justify-content:space-between; align-items:center; }
  .btn { display:inline-block; padding:11px 26px; border-radius:9px; border:0; cursor:pointer;
         background:#4f46e5; color:#fff; font-size:15px; font-weight:600; text-decoration:none; }
  .btn.ghost { background:transparent; border:1px solid #475569; color:#94a3b8; }
  .err { background:#450a0a; border:1px solid #b91c1c; color:#fecaca; padding:12px 16px;
         border-radius:9px; margin-bottom:18px; font-size:14px; }
  .note { background:#172554; border:1px solid #1e40af; color:#bfdbfe; padding:12px 16px;
          border-radius:9px; font-size:13px; line-height:1.55; margin:14px 0; }
  ul.summary { line-height:1.9; font-size:14px; color:#cbd5e1; padding-left:18px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">{{ $settings['brand'] }} — first-run setup</div>
  <h1>{{ $label }}</h1>
  <ol class="steps">
    @foreach(range(1, $total) as $i)
      <li class="{{ $i == $step ? 'now' : (($progress[$i] ?? '') === 'done' ? 'done' : '') }}">{{ $i }}</li>
    @endforeach
  </ol>
  @if(!empty($error))<div class="err">{{ $error }}</div>@endif
  <div class="card">@yield('content')</div>
</div>
</body>
</html>
