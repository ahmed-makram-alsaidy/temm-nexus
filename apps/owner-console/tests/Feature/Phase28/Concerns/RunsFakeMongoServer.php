<?php

namespace Tests\Feature\Phase28\Concerns;

/**
 * Phase 28V.1 — synthetic MongoDB source (wire-level fake server).
 *
 * Builds the `shop` database with users/products/orders/events/mixed_documents,
 * ObjectIds, references, nested addresses, an order items[] array, Decimal128,
 * dates, optional fields, schema variance, indexes (unique + TTL), a
 * $jsonSchema validator, a GridFS bucket pair and Arabic samples — exactly the
 * 28V.1 synthetic source, served over the real wire protocol.
 */
trait RunsFakeMongoServer
{
    /** @var resource|null */
    protected $fakeMongoProcess = null;
    protected int $fakeMongoPort = 0;

    /** Static dataset access for debug harnesses. */
    public static function datasetForDebug(): array
    {
        return static::shopDataset();
    }

    protected static function oid(int $n): array
    {
        return ['t' => 'objectId', 'v' => str_pad(dechex($n), 24, '0', STR_PAD_LEFT)];
    }

    protected static function dec(string $value): array
    {
        return ['t' => 'decimal128', 'v' => $value];
    }

    protected static function date(int $epochMs): array
    {
        return ['t' => 'date', 'v' => $epochMs];
    }

    protected static function bin(string $bytes, int $subtype = 0): array
    {
        return ['t' => 'binary', 'v' => $bytes, 'subtype' => $subtype];
    }

    /** Synthetic 28V.1 shop dataset. */
    public static function shopDataset(): array
    {
        $t0 = 1735689600000; // 2025-01-01T00:00:00Z
        $users = [];
        $names = ['القاهرة للتوصيل', 'alexandria-logistics', 'إدارة الطلبات', 'وصلة فرع', 'Cairo User'];
        foreach (range(1, 8) as $i) {
            $users[] = [
                '_id' => static::oid($i),
                'name' => $names[$i % count($names)],
                'email' => "user{$i}@shop.test",
                'age' => 20 + $i,
                'is_active' => $i % 2 === 0,
                'balance' => static::dec('19.99'.($i % 10)),
                'created_at' => static::date($t0 + $i * 86400000),
                'address' => ['city' => 'القاهرة', 'street' => 'شارع التحرير '.$i, 'geo' => ['lat' => 30.044 + $i / 100, 'lng' => 31.235]],
                'tags' => ['vip', 'الإسكندرية'],
            ];
        }
        $products = [];
        foreach (range(1, 6) as $i) {
            $products[] = [
                '_id' => static::oid(100 + $i),
                'sku' => "SKU-{$i}",
                'title' => "منتج {$i}",
                'price' => static::dec('1234.5678'),
                'stock' => $i * 10,
            ];
        }
        $orders = [];
        foreach (range(1, 6) as $i) {
            $orders[] = [
                '_id' => static::oid(500 + $i),
                'user_id' => static::oid(($i % 8) + 1),
                'total' => static::dec('250.75'),
                'currency' => 'EGP',
                'status' => 'shipped',
                'created_at' => static::date($t0 + $i * 3600000),
                'items' => [
                    ['product_id' => static::oid(100 + (($i % 6) + 1)), 'qty' => $i, 'price' => static::dec('12.34'), 'note' => 'وصلة'],
                    ['product_id' => static::oid(100 + (($i % 5) + 2)), 'qty' => $i + 1, 'price' => static::dec('56.78')],
                ],
            ];
        }
        $events = [];
        foreach (range(1, 10) as $i) {
            $events[] = [
                '_id' => static::oid(900 + $i),
                'kind' => 'page_view',
                'payload' => ['url' => "/page/{$i}", 'ms' => $i * 7],
                'created_at' => static::date($t0 + $i),
                'expires_at' => static::date($t0 + 86400000 * 30), // TTL-indexed field
            ];
        }
        // mixed_documents — deliberate schema variance (28E.3) + deep nesting (28L.2).
        $mixed = [
            ['_id' => static::oid(701), 'value' => 'string-shape', 'score' => 1],
            ['_id' => static::oid(702), 'value' => 42, 'score' => 2],
            ['_id' => static::oid(703), 'value' => ['nested' => 'object-shape'], 'score' => 3],
            ['_id' => static::oid(704), 'score' => 4], // value missing entirely
            ['_id' => static::oid(705), 'value' => null, 'score' => 5],
        ];
        $deep = ['_id' => static::oid(710), 'l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => ['l8' => ['l9' => ['l10' => 'bottom']]]]]]]]]];
        $mixed[] = $deep;

        return [
            'databases' => [
                'shop' => [
                    'collections' => [
                        'users' => [
                            'documents' => $users,
                            'indexes' => [
                                ['name' => '_id_', 'key' => ['_id' => 1]],
                                ['name' => 'email_unique', 'key' => ['email' => 1], 'unique' => true],
                            ],
                        ],
                        'products' => [
                            'documents' => $products,
                            'indexes' => [['name' => 'sku_unique', 'key' => ['sku' => 1], 'unique' => true]],
                            'options' => ['validator' => ['$jsonSchema' => ['bsonType' => 'object', 'required' => ['sku', 'price']]],
                                'validationLevel' => 'moderate', 'validationAction' => 'error'],
                        ],
                        'orders' => [
                            'documents' => $orders,
                            'indexes' => [
                                ['name' => 'user_id_1', 'key' => ['user_id' => 1]],
                                ['name' => 'created_ttl', 'key' => ['created_at' => 1], 'expireAfterSeconds' => 3600],
                            ],
                        ],
                        'events' => [
                            'documents' => $events,
                            'indexes' => [['name' => 'expires_ttl', 'key' => ['expires_at' => 1], 'expireAfterSeconds' => 0]],
                        ],
                        'mixed_documents' => ['documents' => $mixed, 'indexes' => []],
                        // GridFS bucket pair (28N) — metadata only.
                        'photos.files' => [
                            'documents' => [
                                ['_id' => static::oid(801), 'filename' => 'صورة.png', 'length' => 1024, 'md5' => 'd41d8cd98f00b204e9800998ecf8427e', 'uploadDate' => static::date($t0)],
                            ],
                            'indexes' => [],
                        ],
                        'photos.chunks' => [
                            'documents' => [['_id' => static::oid(811), 'files_id' => static::oid(801), 'n' => 0, 'data' => static::bin(str_repeat("\x00", 32))]],
                            'indexes' => [],
                        ],
                    ],
                ],
                // System databases — excluded from import by default (28C).
                'admin' => ['collections' => ['system.version' => ['documents' => [], 'indexes' => []]]],
                'local' => ['collections' => ['oplog.rs' => ['documents' => [], 'indexes' => []]]],
            ],
        ];
    }

    /** Spawn the fake server; returns mongodb://127.0.0.1:<port>/shop. */
    protected function startFakeMongo(?string $authUserPass = null): string
    {
        $dir = storage_path('framework/testing/phase28');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $datasetFile = $dir.'/dataset-'.uniqid().'.php';
        $dataset = $this->shopDataset();
        if ($authUserPass !== null) {
            $dataset['auth'] = true;
        }
        file_put_contents($datasetFile, '<?php return '.var_export($dataset, true).';');
        $php = PHP_BINARY;
        $port = 0;
        $args = [escapeshellarg($php), escapeshellarg(__DIR__.'/fake-mongo-server.php'), escapeshellarg($datasetFile), (string) $port];
        if ($authUserPass !== null) {
            $args[] = '--auth';
            $args[] = escapeshellarg($authUserPass);
        }
        $command = implode(' ', $args);
        $this->fakeMongoProcess = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $dir.'/server-err.log', 'a']], $pipes);
        if (! is_resource($this->fakeMongoProcess)) {
            throw new \RuntimeException('failed to start fake mongodb server');
        }
        $line = fgets($pipes[1], 128);
        if (! preg_match('/READY 127\.0\.0\.1:(\d+)/', (string) $line, $m)) {
            proc_terminate($this->fakeMongoProcess);
            throw new \RuntimeException('fake mongodb server did not report READY: '.substr((string) $line, 0, 100));
        }
        $this->fakeMongoPort = (int) $m[1];

        return "mongodb://127.0.0.1:{$this->fakeMongoPort}/shop";
    }

    protected function stopFakeMongo(): void
    {
        if (is_resource($this->fakeMongoProcess)) {
            proc_terminate($this->fakeMongoProcess);
            proc_close($this->fakeMongoProcess);
            $this->fakeMongoProcess = null;
        }
    }
}
