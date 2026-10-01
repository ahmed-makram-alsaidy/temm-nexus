<?php

namespace App\Connectors\Firebase;

use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;

/**
 * Phase 29I — Firebase client dependency patterns for the generic scanner.
 *
 * Detects usage in source repositories: the firebase/* JS SDK family,
 * firebase-admin server SDK, the Flutter/Dart plugin family, and the config
 * files that bind apps to a Firebase project. Callsites are classified
 * (auth / firestore / storage / functions / messaging) so the migration plan
 * can quantify what the client code must convert.
 */
class FirebaseClientScanner implements ClientScannerProvider
{
    public function scannerLabel(): string
    {
        return 'Firebase';
    }

    public function patternsFor(string $language): array
    {
        $common = [
            ['category' => 'url', 'regex' => '/https:\/\/[a-z0-9\-]+\.firebaseapp\.com|https:\/\/[a-z0-9\-]+\.web\.app|https:\/\/[a-z0-9\-]+\.firebasedatabase\.app|[a-z0-9\-]+\.firebasestorage\.app/i', 'target' => null],
            ['category' => 'client_init', 'regex' => '/initializeApp\s*\(|initializeAppFromResource\s*\(/', 'target' => null],
            ['category' => 'secret', 'regex' => '/FIREBASE_SERVICE_ACCOUNT|SERVICE_ACCOUNT_JSON|private_key\s*[:=]\s*[\'"]|firebaseAdmin|google-services\.json|GoogleService-Info\.plist/', 'target' => null],
        ];

        if ($language === 'dart') {
            return array_merge([
                ['category' => 'import', 'regex' => '/package:firebase_core\/|package:firebase_auth\/|package:cloud_firestore\/|package:firebase_storage\/|package:firebase_functions\/|package:firebase_messaging\//', 'target' => 0],
                ['category' => 'auth', 'regex' => '/FirebaseAuth\.instance[\.\w]*(signInWith|signOut|createUser|sendPasswordReset|verifyPhoneNumber)\w*\s*\(|signInWith\w+\s*\(/', 'target' => 0],
                ['category' => 'firestore', 'regex' => '/FirebaseFirestore\.instance|\.collection\s*\(\s*[\'"]([A-Za-z0-9_\-\/]+)[\'"]\s*\)|\.doc\s*\(\s*[\'"]([A-Za-z0-9_\-\/]+)[\'"]\s*\)/', 'target' => 1],
                ['category' => 'storage', 'regex' => '/FirebaseStorage\.instance|\.ref\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)/', 'target' => 1],
                ['category' => 'functions', 'regex' => '/FirebaseFunctions\.instance|httpsCallable\s*\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', 'target' => 1],
            ], $common);
        }

        // javascript / typescript / react / next / node
        return array_merge([
            ['category' => 'import', 'regex' => '/[\'"]firebase\/(app|auth|firestore|storage|functions|messaging|analytics|database)[\'"]|[\'"]firebase-admin[\'"]|require\s*\(\s*[\'"]firebase(-admin)?(?:\/[a-z]+)?[\'"]\s*\)/', 'target' => 0],
            ['category' => 'auth', 'regex' => '/getAuth\s*\(|firebase\.auth\s*\(|\bauth\s*\.\s*(signInWith\w+|signOut|createUserWith\w+|signInWith\w+)\s*\(|onAuthStateChanged\s*\(/', 'target' => 0],
            ['category' => 'firestore', 'regex' => '/getFirestore\s*\(|firebase\.firestore\s*\(|\bcollection\s*\(\s*(?:db|firestore)\s*,\s*[\'"]([A-Za-z0-9_\-\/]+)[\'"]|\bdoc\s*\(\s*[\'"]([A-Za-z0-9_\-\/]+)[\'"]|onSnapshot\s*\(|addDoc\s*\(|setDoc\s*\(|updateDoc\s*\(|getDocs\s*\(|query\s*\(/', 'target' => 1],
            ['category' => 'storage', 'regex' => '/getStorage\s*\(|firebase\.storage\s*\(|uploadBytes\s*\(|getDownloadURL\s*\(|ref\s*\(\s*(?:storage|firebase\.storage\.Ref)\s*,\s*[\'"]?([^\'")]*)/', 'target' => 1],
            ['category' => 'functions', 'regex' => '/getFunctions\s*\(|httpsCallable\s*\(.{0,120}?[\'"]([A-Za-z0-9_\-]+)[\'"]/', 'target' => 1],
            ['category' => 'messaging', 'regex' => '/getMessaging\s*\(|getToken\s*\(\s*(?:messaging|{\s*messaging)/', 'target' => 0],
            ['category' => 'admin', 'regex' => '/admin\.auth\s*\(\)|admin\.firestore\s*\(\)|admin\.messaging\s*\(\)|credential\.cert\s*\(|initializeApp\s*\(\s*{\s*credential/', 'target' => 0],
        ], $common);
    }

    /** Provider-specific env markers merged with the scanner's generic base list. */
    public function secretMarkers(): array
    {
        return ['FIREBASE_SERVICE_ACCOUNT', 'FIREBASE_PRIVATE_KEY', 'SERVICE_ACCOUNT_JSON'];
    }

    public function configDirNames(): array
    {
        return ['firebase', 'functions'];
    }

    public function hardcodedUrlRiskCode(): string
    {
        return 'hardcoded_firebase_url';
    }
}
