<?php

namespace Tests\Feature\Phase60;

use App\Models\User;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.6.0 Phase B (§B12) — ONE project creation experience.
 *
 * The legacy 13-field create form is no longer the user journey: every
 * normal "New project / Create project / Start migration" entry point ends
 * up at the guided wizard. Old deep links keep working through the
 * redirect instead of breaking.
 */
class LegacyCreateRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    public function test_legacy_create_route_redirects_to_the_guided_wizard(): void
    {
        $this->actingAs($this->owner());

        $response = $this->get('/admin/projects/create');

        $response->assertRedirect(\App\Filament\Pages\NewProjectWizard::getUrl());
    }

    public function test_home_empty_state_links_point_to_the_wizard(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin')->getContent();
        $wizard = \App\Filament\Pages\NewProjectWizard::getUrl();

        $this->assertStringContainsString('href="'.$wizard.'"', $html, 'Home primary action must lead to the guided wizard');
        $this->assertStringNotContainsString('href="'.\App\Filament\Resources\Projects\ProjectResource::getUrl('create').'"', $html, 'Home must not link the legacy create form');
    }
}
