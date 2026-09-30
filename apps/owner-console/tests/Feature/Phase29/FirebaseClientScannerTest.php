<?php

namespace Tests\Feature\Phase29;

use App\Connectors\Firebase\FirebaseClientScanner;
use App\Models\ClientRepository;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Repository\ClientDependencyScanner;
use App\Models\ClientCallsite;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29I — Firebase client repository scanning through the generic
 * scanner: firebase/* and firebase-admin imports across JS/TS/React/Node and
 * Flutter/Dart, callsite classification, secret-location detection with
 * SECRET_PRESENT (never value) semantics.
 */
class FirebaseClientScannerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
        $this->project = Project::create([
            'name' => 'P29 Scan', 'slug' => 'p29-scan-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p29s.test', 'api_version' => 'v1',
        ]);
    }

    protected function tearDown(): void
    {
        // Repo fixtures contain secret-SHAPED canaries — never leave them on
        // disk for the secret scan to trip over.
        $base = storage_path('framework/testing/phase29/repos');
        if (is_dir($base)) {
            foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $dir) {
                self::rrmdir((string) $dir->getPathname());
            }
        }
        parent::tearDown();
    }

    protected static function rrmdir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    protected function buildRepo(array $files): string
    {
        $root = storage_path('framework/testing/phase29/repos/'.uniqid());
        foreach ($files as $path => $content) {
            $full = $root.'/'.$path;
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
        }

        return $root;
    }

    protected ClientRepository $repo;

    protected function scan(array $files): array
    {
        $root = $this->buildRepo($files);
        $this->repo = ClientRepositoryService::linkLocal($this->project, 'Synthetic Firebase client', $root);

        return ClientDependencyScanner::scan($this->repo);
    }

    protected function callsites(): \Illuminate\Support\Collection
    {
        return ClientCallsite::where('client_repository_id', $this->repo->id)->get();
    }

    public function test_provider_patterns_are_declared(): void
    {
        $scanner = new FirebaseClientScanner;
        $this->assertSame('Firebase', $scanner->scannerLabel());
        foreach (['javascript', 'typescript', 'react', 'php'] as $language) {
            $this->assertNotEmpty($scanner->patternsFor($language));
        }
        $this->assertNotEmpty($scanner->patternsFor('dart'));
        $this->assertContains('FIREBASE_SERVICE_ACCOUNT', $scanner->secretMarkers());
        $this->assertSame('hardcoded_firebase_url', $scanner->hardcodedUrlRiskCode());
    }

    public function test_javascript_firebase_usage_is_detected_and_classified(): void
    {
        $result = $this->scan([
            'src/app.js' => <<<'JS'
import { initializeApp } from 'firebase/app';
import { getAuth, signInWithEmailAndPassword, onAuthStateChanged } from 'firebase/auth';
import { getFirestore, collection, addDoc, onSnapshot } from 'firebase/firestore';
import { getStorage, ref, uploadBytes } from 'firebase/storage';
import { getFunctions, httpsCallable } from 'firebase/functions';

const app = initializeApp({ apiKey: 'EXAMPLE-API-KEY-NOT-A-REAL-KEY-000000000000' });
const auth = getAuth(app);
const db = getFirestore(app);
await signInWithEmailAndPassword(auth, 'u@e.test', 'pw');
await addDoc(collection(db, 'orders'), { total: 1 });
await onSnapshot(collection(db, 'users'), () => {});
const storage = getStorage(app);
await uploadBytes(ref(storage, 'avatars/x.png'), bytes);
const notify = httpsCallable(getFunctions(app), 'notifyUser');
JS,
            'package.json' => json_encode(['dependencies' => ['firebase' => '^10.0.0']]),
        ]);

        $this->assertGreaterThan(0, $result['callsites']);
        $byCategory = collect($this->callsites()->groupBy('category'))->map->count();
        foreach (['auth', 'firestore', 'storage', 'functions'] as $category) {
            $this->assertGreaterThan(0, $byCategory[$category] ?? 0, $category.' callsites classified');
        }
        $this->assertTrue(
            $this->callsites()->where('category', 'firestore')->where('target', 'orders')->isNotEmpty(),
            'collection(db, orders) captures the collection id'
        );
        $this->assertSame('notifyUser', (string) $this->callsites()->where('category', 'functions')->whereNotNull('target')->value('target'));
    }

    public function test_firebase_admin_server_usage_is_detected(): void
    {
        $this->scan([
            'server/admin.js' => <<<'JS'
const admin = require('firebase-admin');
const serviceAccount = require('./service-account.json');
admin.initializeApp({ credential: admin.credential.cert(serviceAccount) });
const users = await admin.auth().listUsers();
const doc = await admin.firestore().doc('orders/o1').get();
JS,
        ]);
        $categories = $this->callsites()->pluck('category')->unique()->all();
        $this->assertContains('admin', $categories, 'firebase-admin server SDK detected as its own class');
        $this->assertContains('firestore', $categories, 'admin.firestore() classified');
    }

    public function test_dart_flutter_usage_is_detected(): void
    {
        $this->scan([
            'lib/main.dart' => <<<'DART'
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_auth/firebase_auth.dart';
import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:firebase_storage/firebase_storage.dart';

Future<void> main() async {
  await Firebase.initializeApp();
  final auth = FirebaseAuth.instance;
  await auth.signInWithEmailAndPassword(email: 'u@e.test', password: 'pw');
  final db = FirebaseFirestore.instance;
  final users = db.collection('users');
  final storage = FirebaseStorage.instance;
  await storage.ref('avatars/x.png').putData(bytes);
}
DART,
        ]);
        $byLanguage = $this->callsites()->groupBy('language');
        $this->assertTrue($byLanguage->has('dart'), 'dart callsites recorded');
        $categories = $this->callsites()->where('language', 'dart')->pluck('category')->unique()->all();
        $this->assertContains('import', $categories);
        $this->assertContains('auth', $categories);
        $this->assertContains('firestore', $categories);
        $this->assertContains('storage', $categories);
    }

    public function test_hardcoded_firebase_urls_flagged(): void
    {
        $this->scan([
            'src/config.js' => <<<'JS'
export const BACKUP_URL = 'https://my-app.firebaseapp.com';
export const DB_URL = 'https://my-app-default-rtdb.firesbasedatabase.app';
export const REGION = 'europe-west1';
JS,
        ]);
        $urls = $this->callsites()->where('category', 'url');
        $this->assertGreaterThan(0, $urls->count(), 'hardcoded firebase URLs flagged');
    }

    public function test_secret_locations_report_presence_without_values(): void
    {
        $this->scan([
            'functions/index.js' => <<<'JS'
const admin = require('firebase-admin');
admin.initializeApp({ credential: admin.credential.cert(require('./sa.json')) });
JS,
            // The scanner's env/config pass detects secrets by MARKER — the
            // value itself is hashed, never stored (SECRET_PRESENT semantics).
            '.env' => "FIREBASE_SERVICE_ACCOUNT=eyJhbGciOiJSUzI1NiJ9-REALMATERIAL\n",
            'functions/service-account.json' => '{"project_id":"x","private_key":"FAKE-KEY-MATERIAL-NOT-A-REAL-PEM-BLOCK"}',
        ]);
        $secrets = $this->callsites()->where('category', 'secret');
        $this->assertGreaterThan(0, $secrets->count(), 'secret location recorded (SECRET_PRESENT)');
        foreach ($secrets->pluck('evidence_hash') as $hash) {
            $this->assertSame(64, strlen((string) $hash), 'evidence stored hashed');
        }
        $this->assertStringNotContainsString('REALMATERIAL', (string) json_encode($secrets->toArray()), 'secret VALUES never enter scan reports');
    }
}
