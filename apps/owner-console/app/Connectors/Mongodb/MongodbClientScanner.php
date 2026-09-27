<?php

namespace App\Connectors\Mongodb;

use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;

/**
 * Phase 28R — MongoDB client dependency patterns, contributed through the
 * Phase 27 ClientScannerProvider contract. Detects the common driver
 * idioms (Node driver, Mongoose, Prisma MongoDB provider, PyMongo/Motor,
 * MongoEngine, Laravel MongoDB packages) plus raw URIs and embedded
 * secrets. Coverage is honest, not universal (28R). Evidence is stored
 * HASHED — values never enter the database (28R.2: reads repository files
 * only, never executes application code).
 */
class MongodbClientScanner implements ClientScannerProvider
{
    public function scannerLabel(): string
    {
        return 'MongoDB';
    }

    public function patternsFor(string $language): array
    {
        $common = [
            // Raw connection strings + credentials markers.
            ['category' => 'url', 'regex' => '/mongodb(\+srv)?:\/\/[^\s\'"]+/i', 'target' => null],
            ['category' => 'client_init', 'regex' => '/MongoClient\s*\(|mongoose\.connect\s*\(|new\s+MongoClient\s*\(/', 'target' => null],
            ['category' => 'secret', 'regex' => '/MONGO(?:DB)?_(?:URI|URL|PASSWORD|CONNECTION)|MONGO_URI|mongodb\+srv:\/\//i', 'target' => null],
        ];

        if ($language === 'python') {
            return array_merge([
                ['category' => 'client_init', 'regex' => '/pymongo|MongoEngine|motor\.motor_asyncio|AsyncIOMotorClient/', 'target' => null],
                ['category' => 'collection_access', 'regex' => '/(?:db|database)\[["\']([\w\.\-]+)["\']\]|\.(\w+)\s*=\s*db\.\w+/', 'target' => null],
                ['category' => 'find', 'regex' => '/\.find(?:_one)?\s*\(|\.aggregate\s*\(/', 'target' => null],
                ['category' => 'insert_update_delete', 'regex' => '/\.insert_(?:one|many)\s*\(|\.update_(?:one|many)\s*\(|\.delete_(?:one|many)\s*\(|\.replace_one\s*\(/', 'target' => null],
                ['category' => 'transaction', 'regex' => '/\.start_session\s*\(|with_transaction/', 'target' => null],
            ], $common);
        }

        if ($language === 'php') {
            return array_merge([
                ['category' => 'client_init', 'regex' => '/MongoDB\\\\Client|jenssegers\\\\mongodb|mongodb\/mongodb/', 'target' => null],
                ['category' => 'collection_access', 'regex' => '/->selectCollection\s*\(\s*[\'"]([\w\.\-]+)[\'"]/', 'target' => 1],
                ['category' => 'find', 'regex' => '/->(?:find|findOne|aggregate)\s*\(/', 'target' => null],
                ['category' => 'insert_update_delete', 'regex' => '/->(?:insertOne|insertMany|updateOne|updateMany|deleteOne|deleteMany|replaceOne|bulkWrite)\s*\(/', 'target' => null],
                ['category' => 'change_stream', 'regex' => '/->watch\s*\(/', 'target' => null],
                ['category' => 'gridfs', 'regex' => '/GridFSBucket|->getBucket\b/', 'target' => null],
            ], $common);
        }

        // javascript / typescript (Node driver, Mongoose) + dart fallback.
        return array_merge([
            ['category' => 'collection_access', 'regex' => '/\.collection\s*\(\s*[\'"]([\w\.\-]+)[\'"]|mongoose\.model\s*\(\s*[\'"]([\w\.\-]+)[\'"]|\.db\s*\(\s*[\'"]([\w\.\-]+)[\'"]/', 'target' => 1],
            ['category' => 'find', 'regex' => '/\.find(?:One)?\s*\(|\.aggregate\s*\(|\.findOneAndUpdate\s*\(/', 'target' => null],
            ['category' => 'insert_update_delete', 'regex' => '/\.insert(?:One|Many)\s*\(|\.update(?:One|Many)\s*\(|\.delete(?:One|Many)\s*\(|\.replaceOne\s*\(/', 'target' => null],
            ['category' => 'change_stream', 'regex' => '/\.watch\s*\(/', 'target' => null],
            ['category' => 'gridfs', 'regex' => '/GridFSBucket|GridFsStorage/', 'target' => null],
            ['category' => 'transaction', 'regex' => '/\.startSession\s*\(|withTransaction/', 'target' => null],
            ['category' => 'prisma', 'regex' => '/provider\s*=\s*[\'"]mongodb[\'"]/', 'target' => null],
        ], $common);
    }

    public function secretMarkers(): array
    {
        return ['MONGO_URI', 'MONGODB_URI', 'MONGO_URL', 'MONGODB_PASSWORD', 'MONGO_PASSWORD', 'MONGO_CONNECTION'];
    }

    public function configDirNames(): array
    {
        return [];
    }

    public function hardcodedUrlRiskCode(): string
    {
        return 'hardcoded_mongodb_uri';
    }
}
