<?php

/**
 * Phase 31F — synthetic MySQL database fixture (sandbox, no server).
 *
 * Exercises the full 31A/31B/31C/31D/31E surface: unsigned boundaries,
 * decimal finance values, enum, json, utf8mb4 Arabic, generated fields,
 * FKs, AUTO_INCREMENT state, timestamps, latin1 transcoding flags, views,
 * triggers, routines and events.
 */
return [
    'database' => 'synthetic_mysql',
    'server_version' => '8.0.36',

    'tables' => [
        'customers' => [
            'engine' => 'InnoDB', 'table_collation' => 'utf8mb4_0900_ai_ci', 'auto_increment' => '3', 'table_rows' => 2,
            'columns' => [
                ['column_name' => 'id', 'column_type' => 'int unsigned', 'is_nullable' => 'NO', 'column_key' => 'PRI', 'extra' => 'auto_increment', 'numeric_precision' => 10],
                ['column_name' => 'email', 'column_type' => 'varchar(180)', 'is_nullable' => 'NO', 'character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_0900_ai_ci'],
                ['column_name' => 'full_name', 'column_type' => 'text', 'is_nullable' => 'YES', 'character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_0900_ai_ci'],
                ['column_name' => 'balance', 'column_type' => 'decimal(14,2)', 'is_nullable' => 'NO', 'column_default' => '0', 'numeric_precision' => 14, 'numeric_scale' => 2],
                ['column_name' => 'is_active', 'column_type' => 'tinyint(1)', 'is_nullable' => 'NO', 'column_default' => '1'],
                ['column_name' => 'flags', 'column_type' => 'int unsigned', 'is_nullable' => 'YES', 'numeric_precision' => 10],
                ['column_name' => 'big_counter', 'column_type' => 'bigint unsigned', 'is_nullable' => 'YES', 'numeric_precision' => 20],
                ['column_name' => 'profile', 'column_type' => 'json', 'is_nullable' => 'YES'],
                ['column_name' => 'joined_at', 'column_type' => 'timestamp', 'is_nullable' => 'NO', 'column_default' => 'CURRENT_TIMESTAMP'],
                ['column_name' => 'level', 'column_type' => "enum('bronze','silver','gold')", 'is_nullable' => 'YES', 'character_set_name' => 'utf8mb4'],
                ['column_name' => 'legacy_note', 'column_type' => 'text', 'is_nullable' => 'YES', 'character_set_name' => 'latin1', 'collation_name' => 'latin1_swedish_ci'],
            ],
            'primary_key' => ['id'],
            'foreign_keys' => [],
            'indexes' => [
                ['index_name' => 'email', 'non_unique' => 0, 'column_name' => 'email'],
            ],
            'rows' => [
                ['id' => 1, 'email' => 'ahmed@example.test', 'full_name' => 'أحمد المكرم', 'balance' => '1250.75',
                    'is_active' => 1, 'flags' => '4294967295', 'big_counter' => '18446744073709551615',
                    'profile' => '{"city": "القاهرة"}', 'joined_at' => '2026-01-15 08:30:00', 'level' => 'gold', 'legacy_note' => 'legacy'],
                ['id' => 2, 'email' => 'sara@example.test', 'full_name' => 'Sara Johnson', 'balance' => '0.01',
                    'is_active' => 0, 'flags' => '0', 'big_counter' => '0',
                    'profile' => null, 'joined_at' => '2026-02-01 10:00:00', 'level' => 'silver', 'legacy_note' => null],
            ],
        ],
        'orders' => [
            'engine' => 'InnoDB', 'table_collation' => 'utf8mb4_0900_ai_ci', 'auto_increment' => '1004', 'table_rows' => 3,
            'columns' => [
                ['column_name' => 'id', 'column_type' => 'bigint unsigned', 'is_nullable' => 'NO', 'column_key' => 'PRI', 'extra' => 'auto_increment', 'numeric_precision' => 20],
                ['column_name' => 'customer_id', 'column_type' => 'int unsigned', 'is_nullable' => 'NO', 'numeric_precision' => 10],
                ['column_name' => 'total', 'column_type' => 'decimal(14,2)', 'is_nullable' => 'NO', 'numeric_precision' => 14, 'numeric_scale' => 2],
                ['column_name' => 'status', 'column_type' => "enum('pending','paid','shipped','cancelled')", 'is_nullable' => 'YES', 'character_set_name' => 'utf8mb4'],
                ['column_name' => 'placed_at', 'column_type' => 'timestamp', 'is_nullable' => 'NO'],
                ['column_name' => 'total_with_tax', 'column_type' => 'decimal(14,2)', 'is_nullable' => 'YES', 'extra' => 'STORED GENERATED', 'generation_expression' => '(total * 1.15)'],
            ],
            'primary_key' => ['id'],
            'foreign_keys' => [
                ['column_name' => 'customer_id', 'referenced_table_name' => 'customers', 'referenced_column_name' => 'id', 'constraint_name' => 'orders_customer_fk'],
            ],
            'indexes' => [],
            'rows' => [
                ['id' => '1001', 'customer_id' => 1, 'total' => '58.49', 'status' => 'paid', 'placed_at' => '2026-03-10 14:20:00', 'total_with_tax' => '67.26'],
                ['id' => '1002', 'customer_id' => 2, 'total' => '8.25', 'status' => 'pending', 'placed_at' => '2026-03-11 18:05:00', 'total_with_tax' => '9.49'],
            ],
        ],
        'no_key_table' => [
            'engine' => 'InnoDB', 'table_collation' => 'utf8mb4_0900_ai_ci', 'auto_increment' => null, 'table_rows' => 2,
            'columns' => [
                ['column_name' => 'note', 'column_type' => 'varchar(50)', 'is_nullable' => 'YES', 'character_set_name' => 'utf8mb4'],
            ],
            'primary_key' => [],
            'foreign_keys' => [],
            'indexes' => [],
            'rows' => [
                ['note' => 'أول ملاحظة'],
                ['note' => 'second note'],
            ],
        ],
    ],

    'views' => [
        ['table_name' => 'paid_orders', 'view_definition' => 'SELECT id, total FROM orders WHERE status = \'paid\''],
    ],

    'triggers' => [
        ['trigger_name' => 'orders_before_insert', 'event_object_table' => 'orders',
            'action_timing' => 'BEFORE', 'event_manipulation' => 'INSERT', 'action_statement' => 'BEGIN END'],
    ],

    'routines' => [
        ['routine_name' => 'add_two', 'routine_type' => 'FUNCTION', 'data_type' => 'int', 'dtd_identifier' => 'int',
            'routine_definition' => 'RETURN a + b', 'parameters' => 'a int, b int'],
        ['routine_name' => 'monthly_rollup', 'routine_type' => 'PROCEDURE', 'data_type' => null, 'dtd_identifier' => null,
            'routine_definition' => 'BEGIN END', 'parameters' => 'IN target_month date'],
    ],

    'events' => [
        ['event_name' => 'nightly_cleanup', 'event_definition' => 'DELETE FROM no_key_table WHERE note IS NULL',
            'interval_value' => 1, 'interval_field' => 'DAY', 'status' => 'ENABLED', 'last_executed' => '2026-03-11 00:00:00'],
    ],
];
