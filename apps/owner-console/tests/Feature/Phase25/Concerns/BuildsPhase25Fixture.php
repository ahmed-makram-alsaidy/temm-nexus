<?php

namespace Tests\Feature\Phase25\Concerns;

use App\Models\AiProviderConfig;
use App\Models\Project;
use App\Models\User;

trait BuildsPhase25Fixture
{
    protected User $admin;
    protected Project $projectA;
    protected Project $projectB;
    protected AiProviderConfig $fakeProvider;
    protected string $repoRoot;

    protected function buildPhase25(): void
    {
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
        $this->projectA = Project::create([
            'name' => 'P25 A', 'slug' => 'p25-a-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p25a.test', 'api_version' => 'v1',
        ]);
        $this->projectB = Project::create([
            'name' => 'P25 B', 'slug' => 'p25-b-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p25b.test', 'api_version' => 'v1',
        ]);

        $this->fakeProvider = AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake AI (testing)',
            'model' => 'fake-model-1',
            'secret_encrypted' => 'sk-fake-TESTKEY1234567890',
            'enabled' => true,
            'max_output_tokens' => 2048,
            'project_id' => null,
        ]);
    }

    /** Build a small synthetic client repo (supabase-js + dart + env). */
    protected function buildFixtureRepo(?string $path = null): string
    {
        $root = $path ?? (storage_path('framework/testing/phase25/repo-'.uniqid()));
        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        file_put_contents($root.'/package.json', json_encode([
            'name' => 'synthetic-client', 'dependencies' => ['@supabase/supabase-js' => '^2.0.0', 'react' => '^18.0.0'],
        ]));
        file_put_contents($root.'/supabaseClient.js', <<<'JS'
import { createClient } from '@supabase/supabase-js';
export const supabase = createClient('https://abcdefghijk.supabase.co', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoiYW5vbiJ9.x');
export async function login(email, password) {
  const { data } = await supabase.auth.signInWithPassword({ email, password });
  return data;
}
export async function orders() {
  return supabase.from('orders').select('*');
}
export async function settle(id) {
  return supabase.rpc('settle_order', { order_id: id });
}
export async function notify(payload) {
  return supabase.functions.invoke('send-notification', { body: payload });
}
export async function upload(file) {
  return supabase.storage.from('attachments').upload(file.name, file);
}
export async function watchOrders() {
  return supabase.channel('orders-stream').subscribe();
}
JS
);
        file_put_contents($root.'/legacy_client.dart', <<<'DART'
import 'package:supabase_flutter/supabase_flutter.dart';
final client = Supabase.instance.client;
Future<void> signIn(String email, String password) async {
  await client.auth.signInWithPassword(email: email, password: password);
}
final rows = await client.from('shipments').select();
final settled = await client.rpc('settle_order', params: {'order_id': 1});
await client.storage.from('shipment_images').upload('a.png', file);
DART
);
        file_put_contents($root.'/.env', "SUPABASE_SERVICE_KEY=eyJhbGciOiJIUzI1NiJ9.service-role-value-here\nDATABASE_PASSWORD=super-secret-password-99\n");

        return $root;
    }
}
