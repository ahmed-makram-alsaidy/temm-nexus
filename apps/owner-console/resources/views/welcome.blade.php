<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Services\Localization\LocaleManager::direction() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ config('platform.brand') }}</title>
<style>
  /* 0.6.0 Phase H (R4): public landing aligned with the product token system
     (nexus.css light theme values) — same canvas, surface, border, radius and
     accent language as the console and setup. Presentation only. */
  :root { color-scheme: light; }
  body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Naskh Arabic UI", "Noto Sans Arabic", sans-serif;
         background:#f7f7f9; color:#16161a; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .card { background:#fff; border:1px solid #e4e4e9; border-radius:14px; padding:48px; max-width:560px; text-align:center; }
  h1 { color:#16161a; font-size:26px; margin:0 0 10px; }
  p { color:#55555f; line-height:1.65; }
  a.btn { display:inline-block; margin:18px 6px 0; padding:11px 26px; border-radius:9px; background:#6366f1;
          color:#fff; font-weight:600; text-decoration:none; }
  .v { font-size:12px; color:#8b8b95; margin-top:26px; }
</style>
</head>
<body>
  <div class="card">
    <h1>{{ config('platform.brand') }}</h1>
    <p>{{ __('welcome.blurb') }}</p>
    <a class="btn" href="{{ url('/admin') }}">{{ __('welcome.open_console') }}</a>
    <div style="margin-top:16px">
      @foreach(\App\Services\Localization\LocaleManager::available() as $code => $name)
        <form method="POST" action="{{ route('locale.update') }}" style="display:inline;margin:0 4px">@csrf
          <input type="hidden" name="locale" value="{{ $code }}">
          <button type="submit" style="background:#fff;border:1px solid #d2d2da;color:{{ app()->getLocale() === $code ? '#6366f1' : '#8b8b95' }};border-radius:8px;padding:5px 12px;cursor:pointer;font-size:13px">{{ $name }}</button>
        </form>
      @endforeach
    </div>
    <div class="v">Platform v{{ config('platform.version') }}</div>
  </div>
</body>
</html>
