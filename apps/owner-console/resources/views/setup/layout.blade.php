<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Services\Localization\LocaleManager::direction() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('setup.page_title', ['brand' => $settings['brand'], 'step' => $step, 'total' => $total]) }}</title>
<style>
  /* 0.6.0 Phase H (R3/R4): light-first setup chrome aligned with the product
     token system (nexus.css light theme values). Presentation only — the
     step gating, forms and acceptance checkboxes are untouched. */
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background:#f7f7f9; color:#16161a; min-height:100vh; }
  [dir="rtl"] body { font-family: "Segoe UI", Tahoma, "Noto Naskh Arabic UI", "Noto Sans Arabic", sans-serif; }
  [dir="rtl"] .actions { flex-direction: row-reverse; }
  [dir="rtl"] ul.summary { padding-left:0; padding-right:18px; }
  .wrap { max-width:860px; margin:0 auto; padding:28px 24px 48px; }
  .brand { font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:#6366f1; margin-bottom:4px; }
  h1 { font-size:21px; margin:0 0 10px; color:#16161a; }
  .steps-progress { font-size:13px; color:#55555f; margin:0 0 8px; }
  .steps { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 16px; padding:0; list-style:none; }
  .steps li { font-size:12px; padding:4px 9px; border-radius:999px; border:1px solid #e4e4e9; color:#8b8b95; background:#fff; }
  .steps li.done { background:rgb(99 102 241 / 0.10); color:#6366f1; border-color:rgb(99 102 241 / 0.35); }
  .steps li.now { background:#6366f1; color:#fff; border-color:#6366f1; font-weight:600; }
  .steps li.now::after { content: attr(data-label); margin-inline-start:6px; }
  .card { background:#fff; border:1px solid #e4e4e9; border-radius:14px; padding:22px; }
  p.lead { color:#55555f; line-height:1.6; margin-top:0; }
  table.checks { width:100%; border-collapse:collapse; font-size:14px; }
  table.checks td { padding:9px 8px; border-bottom:1px solid #e4e4e9; vertical-align:top; }
  td.st { font-weight:700; width:70px; }
  .st.PASS { color:#22c55e; background:rgb(34 197 94 / 0.12); border-radius:6px; text-align:center; }
  .st.FAIL { color:#f43f5e; background:rgb(244 63 94 / 0.12); border-radius:6px; text-align:center; }
  .st.INFO { color:#f59e0b; background:rgb(245 158 11 / 0.12); border-radius:6px; text-align:center; }
  label { display:block; font-size:13px; color:#55555f; margin:14px 0 6px; }
  input[type=text], input[type=email], input[type=password], input[type=url] {
      width:100%; padding:10px 12px; border-radius:9px; border:1px solid #d2d2da;
      background:#fff; color:#16161a; font-size:15px; }
  .hint { font-size:12px; color:#8b8b95; margin-top:5px; line-height:1.5; }
  .actions { margin-top:18px; display:flex; justify-content:space-between; align-items:center; }
  .btn { display:inline-block; padding:10px 24px; border-radius:9px; border:0; cursor:pointer;
         background:#6366f1; color:#fff; font-size:15px; font-weight:600; text-decoration:none; }
  .btn.ghost { background:#fff; border:1px solid #d2d2da; color:#55555f; }
  .err { background:rgb(244 63 94 / 0.12); border:1px solid #f43f5e; color:#9f1239; padding:12px 16px;
         border-radius:9px; margin-bottom:16px; font-size:14px; }
  .note { background:rgb(56 189 248 / 0.12); border:1px solid #38bdf8; color:#0c4a6e; padding:12px 16px;
          border-radius:9px; font-size:13px; line-height:1.55; margin:12px 0; }
  ul.summary { line-height:1.85; font-size:14px; color:#16161a; padding-left:18px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">{{ __('setup.brand_line', ['brand' => $settings['brand']]) }}</div>
  <h1>{{ __('setup.steps.'.$step) }}</h1>
  <p class="steps-progress">{{ __('setup.step_progress', ['step' => $step, 'total' => $total]) }}</p>
  <ol class="steps">
    @foreach(range(1, $total) as $i)
      <li class="{{ $i == $step ? 'now' : (($progress[$i] ?? '') === 'done' ? 'done' : '') }}"
          title="{{ __('setup.steps.'.$i) }}"
          data-label="{{ __('setup.steps.'.$i) }}">{{ $i }}</li>
    @endforeach
  </ol>
  @if(!empty($error))<div class="err">{{ $error }}</div>@endif
  <div class="card">@yield('content')</div>
  <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
    @foreach(\App\Services\Localization\LocaleManager::available() as $code => $name)
      <form method="POST" action="{{ route('locale.update') }}">@csrf
        <input type="hidden" name="locale" value="{{ $code }}">
        <button type="submit" style="background:#fff;border:1px solid #d2d2da;color:{{ app()->getLocale() === $code ? '#6366f1' : '#8b8b95' }};border-radius:8px;padding:6px 14px;cursor:pointer;font-size:13px">{{ $name }}</button>
      </form>
    @endforeach
  </div>
</div>
</body>
</html>
