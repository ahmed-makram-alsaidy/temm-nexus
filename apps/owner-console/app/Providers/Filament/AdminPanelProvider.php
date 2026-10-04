<?php

namespace App\Providers\Filament;

use App\Filament\Support\ControlPlaneChrome;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // 0.4.0 P2 fix: the panel must identify as the product, not as
            // "Backend Control Plane". The brand is operator-configurable.
            ->brandName(config('platform.brand'))
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->maxContentWidth('nx-width-shell')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('250px')
            // 0.6.0 Phase A — light is the DEFAULT product experience; the
            // theme switcher keeps dark mode available as an explicit choice.
            ->defaultThemeMode(\Filament\Enums\ThemeMode::Light)
            // 0.4.0 Phase C: the fixed group list is gone. The navigation is
            // built explicitly by ProductNavigation, so there are no empty
            // groups and no group that holds a single link.
            ->navigation(fn () => ControlPlaneChrome::navigation())
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                ControlPlaneChrome::stylesHook(),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                ControlPlaneChrome::bodyEndHook(),
            )
            // 0.6.0 Phase B — the project context is a horizontal TAB BAR at
            // the top of the content area (Overview · Migration · Data ·
            // Access · Build · Operate · Settings), replacing the 35-link
            // project sidebar. The platform sidebar stays quiet in project
            // context; the topbar chip keeps the "which project am I in, and
            // how do I get out" answer always visible.
            ->renderHook(
                \Filament\View\PanelsRenderHook::CONTENT_START,
                ControlPlaneChrome::projectTabbarHook(),
            )
            // 0.4.0 Phase C — project context chip in the topbar. Since the
            // project workspace navigation now renders at the END of the
            // sidebar, the topbar carries the always-visible answer to "which
            // project am I in, and how do I get out".
            ->renderHook(
                \Filament\View\PanelsRenderHook::TOPBAR_END,
                ControlPlaneChrome::projectContextHook(),
            )
            // 0.4.0-rc.5 (Phase 41) — the visible language switcher.
            ->renderHook(
                \Filament\View\PanelsRenderHook::TOPBAR_END,
                ControlPlaneChrome::localeSwitcherHook(),
            )
            // Phase 26K.2 — platform version is always visible in the console.
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                fn () => '<div style="text-align:center;padding:18px 0 26px;font-size:12px;color:rgb(var(--gray-400));">'
                    .e(config('platform.brand')).' · v'.e(config('platform.version')).'</div>',
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                \App\Http\Middleware\EnsurePlatformInitialized::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // 0.4.0-rc.5 (Phase 41): locale resolves AFTER the session
                // starts, BEFORE anything renders.
                \App\Http\Middleware\SetRequestLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
