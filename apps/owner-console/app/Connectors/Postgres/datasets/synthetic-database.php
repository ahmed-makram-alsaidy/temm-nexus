<?php

/**
 * Phase 30D — synthetic PostgreSQL database fixture (sandbox, no server).
 *
 * A disposable stand-in exercising the FULL 30B metadata surface: two
 * schemas, exact PG types (30C) including numeric(p,s), timestamptz, uuid,
 * jsonb, bytea, arrays, enum, domain, generated + identity columns, a
 * partitioned table, view, matview, sequence with state, functions,
 * procedure, trigger, RLS policies, extension (unknown type → NEEDS_REVIEW)
 * and Arabic UTF-8 + numeric-precision data rows.
 */
return [
    'database' => 'synthetic_pg',
    'server' => [
        'version_num' => 160002,
        'server_encoding' => 'UTF8',
        'lc_ctype' => 'en_US.utf8',
    ],

    'schemas' => ['public', 'reporting'],

    // 30C — pg_type strings are the EXACT format_type() output.
    'tables' => [
        'public' => [
            'customers' => [
                'columns' => [
                    ['name' => 'id', 'pg_type' => 'integer', 'notnull' => true, 'identity' => 'a'],
                    ['name' => 'email', 'pg_type' => 'character varying(180)', 'notnull' => true],
                    ['name' => 'full_name', 'pg_type' => 'text'],
                    ['name' => 'balance', 'pg_type' => 'numeric(14,2)', 'notnull' => true, 'default' => '0'],
                    ['name' => 'metadata', 'pg_type' => 'jsonb'],
                    ['name' => 'joined_at', 'pg_type' => 'timestamp with time zone', 'notnull' => true, 'default' => 'now()'],
                    ['name' => 'avatar', 'pg_type' => 'bytea'],
                    ['name' => 'login_count', 'pg_type' => 'integer', 'notnull' => true, 'default' => '0', 'generated' => true, 'generated_expr' => '(login_count + 1)'],
                    ['name' => 'level', 'pg_type' => 'customer_level'],
                    ['name' => 'tags', 'pg_type' => 'text[]'],
                ],
                'primary_key' => ['id'],
                'foreign_keys' => [],
                'checks' => [['name' => 'customers_balance_check', 'contype' => 'c', 'definition' => 'CHECK (balance >= 0)']],
                'indexes' => [
                    ['index_name' => 'customers_email_idx', 'indisunique' => true, 'is_partial' => false, 'key_columns' => 'email', 'definition' => 'CREATE UNIQUE INDEX customers_email_idx ON public.customers USING btree (email)'],
                ],
                'policies' => [['policy_name' => 'customers_own_row', 'command' => 'SELECT', 'using_expression' => 'true']],
                'partitions' => [],
                'rows' => [
                    ['id' => 1, 'email' => 'ahmed@example.test', 'full_name' => 'أحمد المكرم', 'balance' => '1250.75',
                        'metadata' => '{"city": "القاهرة"}', 'joined_at' => '2026-01-15 08:30:00+00', 'avatar' => null,
                        'login_count' => 10, 'level' => 'gold', 'tags' => ['vip', 'الشرق الأوسط']],
                    ['id' => 2, 'email' => 'sara@example.test', 'full_name' => 'Sara Johnson', 'balance' => '0.01',
                        'metadata' => null, 'joined_at' => '2026-02-01 10:00:00+00', 'avatar' => 'bin64:iVBORw0KGgo=',
                        'login_count' => 3, 'level' => 'silver', 'tags' => null],
                ],
            ],
            'orders' => [
                'columns' => [
                    ['name' => 'id', 'pg_type' => 'bigint', 'notnull' => true, 'identity' => 'a'],
                    ['name' => 'customer_id', 'pg_type' => 'integer', 'notnull' => true],
                    ['name' => 'total', 'pg_type' => 'numeric(14,2)', 'notnull' => true],
                    ['name' => 'status', 'pg_type' => 'order_status'],
                    ['name' => 'placed_at', 'pg_type' => 'timestamp with time zone', 'notnull' => true],
                ],
                'primary_key' => ['id'],
                'foreign_keys' => [
                    ['conname' => 'orders_customer_id_fkey', 'column_name' => 'customer_id', 'references_schema' => 'public',
                        'references_table' => 'customers', 'references_column' => 'id', 'condeferrable' => false, 'condeferred' => false, 'confupdtype' => 'c', 'confdeltype' => 'a'],
                ],
                'checks' => [],
                'indexes' => [
                    ['index_name' => 'orders_placed_at_idx', 'indisunique' => false, 'is_partial' => false, 'key_columns' => 'placed_at', 'definition' => 'CREATE INDEX orders_placed_at_idx ON public.orders USING btree (placed_at)'],
                ],
                'policies' => [],
                'partitions' => [],
                'rows' => [
                    ['id' => 1001, 'customer_id' => 1, 'total' => '58.49', 'status' => 'paid', 'placed_at' => '2026-03-10 14:20:00+00'],
                    ['id' => 1002, 'customer_id' => 2, 'total' => '8.25', 'status' => 'pending', 'placed_at' => '2026-03-11 18:05:00+00'],
                    ['id' => 1003, 'customer_id' => 1, 'total' => '101.48', 'status' => 'shipped', 'placed_at' => '2026-03-12 07:30:00+00'],
                ],
            ],
            'events_by_month' => [
                // 30B — partitioned parent (relkind p) with two children.
                'relkind' => 'p',
                'columns' => [
                    ['name' => 'event_id', 'pg_type' => 'bigint', 'notnull' => true],
                    ['name' => 'occurred_at', 'pg_type' => 'timestamp with time zone', 'notnull' => true],
                    ['name' => 'payload', 'pg_type' => 'jsonb'],
                ],
                'primary_key' => ['event_id', 'occurred_at'],
                'foreign_keys' => [],
                'checks' => [],
                'indexes' => [],
                'policies' => [],
                'partitions' => [
                    ['partition_name' => 'events_2026_01', 'bound' => "FOR VALUES FROM ('2026-01-01 00:00:00+00') TO ('2026-02-01 00:00:00+00')"],
                    ['partition_name' => 'events_2026_02', 'bound' => "FOR VALUES FROM ('2026-02-01 00:00:00+00') TO ('2026-03-01 00:00:00+00')"],
                ],
                'rows' => [
                    ['event_id' => 1, 'occurred_at' => '2026-01-05 09:00:00+00', 'payload' => '{"type": "signup"}'],
                    ['event_id' => 2, 'occurred_at' => '2026-02-05 09:00:00+00', 'payload' => '{"type": "order"}'],
                ],
            ],
            'geometries' => [
                // 30C — extension type → NEEDS_REVIEW.
                'columns' => [
                    ['name' => 'id', 'pg_type' => 'integer', 'notnull' => true, 'identity' => 'a'],
                    ['name' => 'location', 'pg_type' => 'geometry(Point,4326)'],
                ],
                'primary_key' => ['id'],
                'foreign_keys' => [],
                'checks' => [],
                'indexes' => [],
                'policies' => [],
                'partitions' => [],
                'rows' => [
                    ['id' => 1, 'location' => '0101000020E61000009A99999999B946400000000000004440'],
                ],
            ],
        ],
        'reporting' => [
            'daily_revenue' => [
                'columns' => [
                    ['name' => 'day', 'pg_type' => 'date', 'notnull' => true],
                    ['name' => 'revenue', 'pg_type' => 'numeric(14,2)', 'notnull' => true],
                ],
                'primary_key' => ['day'],
                'foreign_keys' => [],
                'checks' => [],
                'indexes' => [],
                'policies' => [],
                'partitions' => [],
                'rows' => [
                    ['day' => '2026-03-10', 'revenue' => '58.49'],
                    ['day' => '2026-03-11', 'revenue' => '8.25'],
                ],
            ],
        ],
    ],

    'views' => [
        'public' => [
            ['relname' => 'paid_orders', 'definition' => 'SELECT orders.id, orders.total FROM orders WHERE orders.status = \'paid\''],
        ],
        'reporting' => [
            ['relname' => 'revenue_by_day', 'definition' => 'SELECT daily_revenue.day, sum(daily_revenue.revenue) AS revenue FROM daily_revenue GROUP BY daily_revenue.day', 'matview' => true],
        ],
    ],

    'sequences' => [
        ['sequence_name' => 'customers_id_seq', 'sequence_schema' => 'public', 'owned_by_table_schema' => 'public',
            'owned_by_table' => 'customers', 'owned_by_column' => 'id', 'last_value' => '2', 'data_type' => 'integer'],
        ['sequence_name' => 'orders_id_seq', 'sequence_schema' => 'public', 'owned_by_table_schema' => 'public',
            'owned_by_table' => 'orders', 'owned_by_column' => 'id', 'last_value' => '1003', 'data_type' => 'bigint'],
    ],

    'functions' => [
        ['proname' => 'add_two', 'prokind' => 'f', 'language' => 'sql', 'prosecdef' => false,
            'identity_arguments' => 'a integer, b integer', 'result_type' => 'integer',
            'definition' => 'CREATE OR REPLACE FUNCTION public.add_two(a integer, b integer) RETURNS integer LANGUAGE sql AS $function$ SELECT a + b $function$'],
        ['proname' => 'monthly_rollup', 'prokind' => 'p', 'language' => 'plpgsql', 'prosecdef' => false,
            'identity_arguments' => 'IN target_month date', 'result_type' => '',
            'definition' => 'CREATE OR REPLACE PROCEDURE reporting.monthly_rollup(IN target_month date) LANGUAGE plpgsql AS $procedure$ BEGIN NULL; END $procedure$'],
    ],

    'triggers' => [
        ['tgname' => 'orders_audit', 'table_name' => 'orders',
            'definition' => 'CREATE TRIGGER orders_audit AFTER INSERT ON public.orders FOR EACH ROW EXECUTE FUNCTION public.add_two(0, 0)'],
    ],

    'extensions' => [
        ['extname' => 'postgis', 'extversion' => '3.4.1'],
        ['extname' => 'pgcrypto', 'extversion' => '1.3'],
    ],

    'enums' => [
        'public' => [
            'customer_level' => ['bronze', 'silver', 'gold'],
            'order_status' => ['pending', 'paid', 'shipped', 'cancelled'],
        ],
        'reporting' => [],
    ],

    'domains' => [
        'public' => [
            ['typname' => 'positive_amount', 'base_type' => 'numeric(14,2)', 'typnotnull' => true,
                'typdefault' => null, 'check_definition' => 'CHECK (VALUE >= (0)::numeric)'],
        ],
        'reporting' => [],
    ],
];
