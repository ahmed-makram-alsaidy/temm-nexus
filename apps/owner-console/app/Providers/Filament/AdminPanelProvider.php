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
            ->brandName('Backend Control Plane')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->maxContentWidth('full')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('250px')
            ->navigationGroups([
                'Projects',
                'Infrastructure',
                'Governance',
            ])
            ->navigation(fn () => ControlPlaneChrome::navigation())
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                ControlPlaneChrome::stylesHook(),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                ControlPlaneChrome::searchShortcutHook(),
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::SIDEBAR_NAV_START,
                ControlPlaneChrome::workspaceHook(),
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
