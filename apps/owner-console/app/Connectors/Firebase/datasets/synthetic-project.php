<?php

/**
 * Phase 29J — synthetic Firebase project fixture (sandbox, no real network).
 *
 * A disposable, local-safe stand-in for a real Firebase project: users,
 * products, orders, nested maps, scalar + object arrays, DocumentReference
 * links, *_id naming patterns, Arabic UTF-8 text, geo points, timestamps,
 * bytes, auth-like fixtures and storage files — in NATIVE shape. The fixture
 * transport converts these to the exact REST wire format so every parser in
 * the connector is exercised against production-shaped responses.
 *
 * Native document shape: ['_id' => string, 'fields' => array<string, mixed>]
 * where values are plain PHP scalars/arrays; timestamps are RFC3339 strings
 * prefixed '@', references are '@ref:' + collection + '/' + id, geopoints are
 * ['lat' => float, 'lng' => float], bytes are '@bytes:' + base64.
 */
return [
    'project_id' => 'temm-dogfood-sandbox',
    'database_id' => '(default)',
    'storage_bucket' => 'temm-dogfood-sandbox.appspot.com',

    'collections' => [
        'users' => [
            ['_id' => 'u001', 'fields' => [
                'name' => 'أحمد المكرم',
                'email' => 'ahmed@example.test',
                'age' => 34,
                'active' => true,
                'created_at' => '@2026-01-15T08:30:00.000Z',
                'address' => ['city' => 'القاهرة', 'street' => 'شارع التحرير', 'geo' => ['lat' => 30.0444, 'lng' => 31.2357]],
            ]],
            ['_id' => 'u002', 'fields' => [
                'name' => 'Sara Johnson',
                'email' => 'sara@example.test',
                'age' => 28,
                'active' => true,
                'created_at' => '@2026-02-01T10:00:00.000Z',
                'address' => ['city' => 'Berlin', 'street' => 'Hauptstraße', 'geo' => ['lat' => 52.52, 'lng' => 13.405]],
                'avatar' => '@bytes:iVBORw0KGgoAAAANSUhEUg==',
            ]],
            ['_id' => 'u003', 'fields' => [
                'name' => null,
                'email' => 'omid@example.test',
                'active' => false,
                'created_at' => '@2026-02-20T12:45:00.000Z',
                'address' => ['city' => 'Dubai'],
            ]],
            ['_id' => 'u004', 'fields' => [
                'name' => 'Layla Hassan',
                'email' => 'layla@example.test',
                'age' => 41,
                'active' => true,
                'created_at' => '@2026-03-05T09:15:00.000Z',
                'tags' => ['vip', 'beta', 'الشرق الأوسط'],
            ]],
        ],
        'products' => [
            ['_id' => 'p001', 'fields' => [
                'title' => 'كتاب البرمجة',
                'title_en' => 'The Programming Book',
                'price' => 49.99,
                'stock' => 120,
                'sku' => 'SKU-BOOK-001',
            ]],
            ['_id' => 'p002', 'fields' => [
                'title' => 'قلم أزرق',
                'title_en' => 'Blue Pen',
                'price' => 1.5,
                'stock' => 0,
                'sku' => 'SKU-PEN-002',
            ]],
            ['_id' => 'p003', 'fields' => [
                'title' => 'دفتر ملاحظات',
                'title_en' => 'Notebook',
                'price' => 8.25,
                'stock' => 75,
                'sku' => 'SKU-NOTE-003',
            ]],
        ],
        'orders' => [
            ['_id' => 'o001', 'fields' => [
                'user_id' => 'u001',          // *_id naming pattern → HIGH_CONFIDENCE candidate
                'status' => 'paid',
                'total' => 58.49,
                'placed_at' => '@2026-03-10T14:20:00.000Z',
                'items' => [                   // array of maps → derived child table
                    ['product_id' => 'p001', 'qty' => 1, 'unit_price' => 49.99],
                    ['product_id' => 'p002', 'qty' => 2, 'unit_price' => 1.5],
                ],
                'product_ref' => '@ref:products/p001',  // DocumentReference → EXPLICIT
            ]],
            ['_id' => 'o002', 'fields' => [
                'user_id' => 'u002',
                'status' => 'pending',
                'total' => 8.25,
                'placed_at' => '@2026-03-11T18:05:00.000Z',
                'items' => [
                    ['product_id' => 'p003', 'qty' => 1, 'unit_price' => 8.25],
                ],
            ]],
            ['_id' => 'o003', 'fields' => [
                'user_id' => 'u004',
                'status' => 'shipped',
                'total' => 101.48,
                'placed_at' => '@2026-03-12T07:30:00.000Z',
                'notes' => 'توصيل سريع للمنطقة الشمالية',
            ]],
        ],
        // Subcollection under orders/o001 — becomes a derived subcollection table.
        'subcollections' => [
            'orders/o001' => [
                'timeline' => [
                    ['_id' => 'ev1', 'fields' => ['event' => 'created', 'at' => '@2026-03-10T14:20:00.000Z']],
                    ['_id' => 'ev2', 'fields' => ['event' => 'paid', 'at' => '@2026-03-10T14:25:00.000Z']],
                ],
            ],
        ],
    ],

    // 29F — auth-like fixtures. NO password hashes/salts/tokens are present
    // here BY CONSTRUCTION (mirrors the accounts:batchGet payload, which
    // never includes them).
    'auth_users' => [
        ['localId' => 'u001', 'email' => 'ahmed@example.test', 'emailVerified' => true, 'disabled' => false,
            'providerUserInfo' => [['providerId' => 'password', 'email' => 'ahmed@example.test']],
            'createdAt' => '1737000000000', 'lastLoginAt' => '1767000000000',
            'customClaims' => ['role' => 'admin', 'region' => 'MENA']],
        ['localId' => 'u002', 'email' => 'sara@example.test', 'emailVerified' => true, 'disabled' => false,
            'providerUserInfo' => [['providerId' => 'google.com', 'email' => 'sara@example.test'], ['providerId' => 'password', 'email' => 'sara@example.test']],
            'createdAt' => '1739000000000', 'lastLoginAt' => '1767100000000'],
        ['localId' => 'u003', 'emailVerified' => false, 'disabled' => true,
            'providerUserInfo' => [], 'createdAt' => '1740000000000', 'lastLoginAt' => '1740000000000'],
        ['localId' => 'anon-001', 'disabled' => false,
            'providerUserInfo' => [['providerId' => 'anonymous']], 'createdAt' => '1741000000000', 'lastLoginAt' => '1741000000000'],
    ],

    // 29G — storage objects (native shape; the fixture transport computes
    // md5Hash and sizes from the actual content bytes — honest checksums).
    'storage_objects' => [
        ['name' => 'avatars/u001.png', 'contentType' => 'image/png', 'content' => "\x89PNG\r\n\x1a\navatar-u001-bytes"],
        ['name' => 'avatars/u002.png', 'contentType' => 'image/png', 'content' => "\x89PNG\r\n\x1a\navatar-u002-bytes"],
        ['name' => 'invoices/2026/o001.pdf', 'contentType' => 'application/pdf', 'content' => "%PDF-1.4 invoice o001",
            'metadata' => ['invoice' => 'o001']],
        ['name' => 'الملفات/عربي/readme.txt', 'contentType' => 'text/plain', 'content' => "مرحبا بكم في TEMM Nexus"],
        ['name' => 'uploads/.keep', 'contentType' => 'application/octet-stream', 'content' => ''],
    ],

    // 29H — functions inventory in Cloud Functions v1 shape.
    'functions' => [
        ['name' => 'projects/temm-dogfood-sandbox/locations/us-central1/functions/apiHello',
            'status' => 'ACTIVE', 'entryPoint' => 'apiHello',
            'httpsTrigger' => ['url' => 'https://us-central1-temm-dogfood-sandbox.cloudfunctions.net/apiHello'],
            'triggerType' => 'HTTP_TRIGGER', 'runtime' => 'nodejs20'],
        ['name' => 'projects/temm-dogfood-sandbox/locations/us-central1/functions/onOrderWrite',
            'status' => 'ACTIVE', 'entryPoint' => 'onOrderWrite',
            'eventTrigger' => ['eventType' => 'providers/cloud.firestore/eventTypes/document.write', 'resource' => 'projects/temm-dogfood-sandbox/databases/(default)/documents/orders/{orderId}', 'service' => 'firestore.googleapis.com'],
            'triggerType' => 'EVENT_TRIGGER', 'runtime' => 'nodejs20'],
        ['name' => 'projects/temm-dogfood-sandbox/locations/us-central1/functions/cleanupUploads',
            'status' => 'ACTIVE', 'entryPoint' => 'cleanupUploads',
            'eventTrigger' => ['eventType' => 'google.cloud.pubsub.topic.v1.messagePublish', 'service' => 'pubsub.googleapis.com'],
            'triggerType' => 'EVENT_TRIGGER', 'runtime' => 'nodejs20',
            'labels' => ['deployment-scheduled' => 'true']],
        ['name' => 'projects/temm-dogfood-sandbox/locations/us-central1/functions/onUserCreate',
            'status' => 'ACTIVE', 'entryPoint' => 'onUserCreate',
            'eventTrigger' => ['eventType' => 'providers/firebase.auth/eventTypes/user.create', 'service' => 'firebaseauth.googleapis.com'],
            'triggerType' => 'EVENT_TRIGGER', 'runtime' => 'nodejs20'],
    ],
];
