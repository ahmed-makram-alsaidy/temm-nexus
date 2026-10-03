@php($code = '503')
@php($title = __('errors.503_title'))
@php($body = __('errors.503_body'))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Services\Localization\LocaleManager::direction() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $code }} — {{ config('platform.brand') }}</title>
<style>
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Naskh Arabic UI", "Noto Sans Arabic", sans-serif;
         background:#0f172a; color:#e2e8f0; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .card { background:#1e293b; border:1px solid #334155; border-radius:14px; padding:48px; max-width:560px; text-align:center; }
  h1 { color:#f8fafc; font-size:26px; margin:0 0 10px; }
  .code { color:#818cf8; font-size:14px; letter-spacing:.12em; text-transform:uppercase; }
  p { color:#94a3b8; line-height:1.65; }
  a.btn { display:inline-block; margin:18px 6px 0; padding:11px 26px; border-radius:9px; background:#4f46e5;
          color:#fff; font-weight:600; text-decoration:none; }
</style>
</head>
<body>
  <div class="card">
    <div class="code">{{ $code }}</div>
    <h1>{{ $title }}</h1>
    <p>{{ $body }}</p>
    <a class="btn" href="{{ url('/admin') }}">{{ __('errors.back_home') }}</a>
  </div>
</body>
</html>
