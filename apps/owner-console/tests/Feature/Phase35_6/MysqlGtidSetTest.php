<?php

namespace Tests\Feature\Phase35_6;

use App\Connectors\Mysql\Replication\MysqlGtidSet;
use PHPUnit\Framework\TestCase;

/**
 * Phase 35.6 §7 — GTID set arithmetic used by the MySQL position model
 * (checkpoint union + final-sync containment).
 */
class MysqlGtidSetTest extends TestCase
{
    public function test_parse_and_round_trip(): void
    {
        $set = MysqlGtidSet::parse('3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5:7-9,23D7C7AD-A3C8-4A6B-8B6A-2AA80F6E1F31:1-3');
        $this->assertSame(
            '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5:7-9,23D7C7AD-A3C8-4A6B-8B6A-2AA80F6E1F31:1-3',
            $set->toString(),
        );
    }

    public function test_parse_empty_set(): void
    {
        $this->assertTrue(MysqlGtidSet::parse('')->isEmpty());
        $this->assertSame('', MysqlGtidSet::parse('')->toString());
    }

    public function test_add_merges_intervals(): void
    {
        $uuid = '3E11FA47-71CA-11E1-9E33-C80AA9429562';
        $set = MysqlGtidSet::parse($uuid.':1-5');
        $set = $set->add($uuid, 6)->add($uuid, 7)->add($uuid, 10);
        $this->assertSame($uuid.':1-7:10', $set->toString());
    }

    public function test_union_combines_sets(): void
    {
        $a = MysqlGtidSet::parse('3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5');
        $b = MysqlGtidSet::parse('3E11FA47-71CA-11E1-9E33-C80AA9429562:6-9,23D7C7AD-A3C8-4A6B-8B6A-2AA80F6E1F31:1-1');
        $merged = $a->union($b);
        $this->assertSame(
            '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-9,23D7C7AD-A3C8-4A6B-8B6A-2AA80F6E1F31:1',
            $merged->toString(),
        );
    }

    public function test_containment(): void
    {
        $uuid = '3E11FA47-71CA-11E1-9E33-C80AA9429562';
        $applied = MysqlGtidSet::parse($uuid.':1-10');
        $this->assertTrue($applied->contains(MysqlGtidSet::parse($uuid.':1-5')));
        $this->assertTrue($applied->contains(MysqlGtidSet::parse($uuid.':5-10')));
        $this->assertFalse($applied->contains(MysqlGtidSet::parse($uuid.':1-11')), 'applied misses trx 11');
        $this->assertFalse($applied->contains(MysqlGtidSet::parse($uuid.':1-5,23D7C7AD-A3C8-4A6B-8B6A-2AA80F6E1F31:1')), 'unknown uuid missing');
        $this->assertTrue(MysqlGtidSet::parse('')->contains(MysqlGtidSet::parse('')), 'empty ⊆ empty');
    }

    public function test_partial_range_overlap_is_not_containment(): void
    {
        $uuid = '3E11FA47-71CA-11E1-9E33-C80AA9429562';
        $applied = MysqlGtidSet::parse($uuid.':1-5:8-10');
        $this->assertFalse($applied->contains(MysqlGtidSet::parse($uuid.':1-10')), 'gap at 6-7 is not covered');
        $this->assertTrue($applied->contains(MysqlGtidSet::parse($uuid.':8')));
    }
}
