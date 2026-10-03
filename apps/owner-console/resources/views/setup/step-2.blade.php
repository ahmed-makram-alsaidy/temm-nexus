@extends('setup.layout')
@section('content')
<p class="lead">{{ __('setup.s2_lead') }}</p>
<form method="post" action="{{ url('/setup/step/2') }}">@csrf
  <label>{{ __('setup.s2_platform_name') }}</label>
  <input type="text" name="platform_name" required maxlength="80" value="{{ $settings['name'] }}">
  <label>{{ __('setup.s2_brand_name') }}</label>
  <input type="text" name="brand_name" maxlength="80" value="{{ $settings['brand'] }}">
  <label>{{ __('setup.s2_support_url') }}</label>
  <input type="url" name="support_url" placeholder="https://support.example.com" value="">
  <label>{{ __('setup.s2_default_language') }}</label>
  <select name="platform_locale" style="width:100%;padding:10px 12px;border-radius:9px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font-size:15px;">
    @foreach(\App\Services\Localization\LocaleManager::available() as $code => $name)
      <option value="{{ $code }}" @selected(old('platform_locale', 'en') === $code)>{{ $name }}</option>
    @endforeach
  </select>
  <div class="hint">{{ __('setup.s2_default_language_helper') }}</div>
  <div class="actions"><a class="btn ghost" href="{{ url('/setup/step/1') }}">{{ __('setup.back') }}</a><button class="btn">{{ __('setup.continue') }}</button></div>
</form>
@endsection
