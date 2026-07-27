<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Storage\DatabaseStorage;
use Tests\TestCase;

/**
 * This MySQL install has no working
 * MD5()/SHA1()/SHA() SQL builtins (confirmed via direct probing, not an
 * OpenSSL/config toggle — SHA2()/CRC32() still work). A full read of
 * vendor/laravel/pulse/src/Storage/DatabaseStorage.php confirmed `key_hash`
 * is never compared against an independently-computed literal anywhere in
 * Pulse's PHP runtime for mysql/pgsql — it is written only by the
 * generated column and read only via GROUP BY / self-join-on-key_hash /
 * upsert conflict-target lists, i.e. a fully opaque, self-consistent
 * uniqueness/index key. `database/migrations/2026_07_20_150026_create_pulse_tables.php`
 * therefore replaces the mysql/mariadb branch's `unhex(md5(`key`))` with
 * `unhex(left(sha2(`key`, 256), 32))` — same 16-byte shape, deterministic,
 * collision-resistant, algorithm-agnostic from Pulse's own point of view.
 * See docs/pulse-monitoring.md for the full writeup.
 */
class PulseMigrationHashCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Required test 1: no runtime dependency on a specific hash value/algorithm for mysql/pgsql. */
    public function test_pulse_storage_never_compares_key_hash_against_a_computed_literal(): void
    {
        $source = file_get_contents((new \ReflectionClass(DatabaseStorage::class))->getFileName());

        // The only place PHP computes a key_hash VALUE is guarded by
        // requiresManualKeyHash() (sqlite only) — for mysql/pgsql it is
        // never independently computed or compared against a literal.
        $this->assertStringContainsString('requiresManualKeyHash', $source);
        $this->assertMatchesRegularExpression(
            "/return \\\$this->connection\\(\\)->getDriverName\\(\\) === 'sqlite';/",
            $source,
            'requiresManualKeyHash() must remain scoped to sqlite only — if a future Pulse version widens this, the MySQL substitution needs re-review.'
        );

        // key_hash is only ever used for GROUP BY / self-join / upsert
        // conflict targets — never in a where('key_hash', <literal>) or
        // whereRaw(...) comparing it to a computed hash value.
        $this->assertDoesNotMatchRegularExpression(
            "/where\\(\\s*'key_hash'\\s*,/",
            $source,
            'A literal key_hash comparison would mean the algorithm is no longer opaque — re-review the MySQL substitution before upgrading.'
        );
    }

    /** Required test 2: chosen replacement expression is supported by current MySQL. */
    public function test_replacement_hash_expression_is_supported_by_current_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific compatibility check.');
        }

        $result = DB::selectOne("SELECT UNHEX(LEFT(SHA2('pulse-compat-check', 256), 32)) as hash");

        $this->assertNotNull($result->hash);
    }

    /** Required test 3: generated hash is deterministic. */
    public function test_replacement_hash_expression_is_deterministic(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific compatibility check.');
        }

        $first = DB::selectOne("SELECT HEX(UNHEX(LEFT(SHA2('same-key', 256), 32))) as h")->h;
        $second = DB::selectOne("SELECT HEX(UNHEX(LEFT(SHA2('same-key', 256), 32))) as h")->h;

        $this->assertSame($first, $second);
    }

    /** Required test 4: generated hash preserves the expected 16-byte binary length. */
    public function test_replacement_hash_preserves_expected_binary_length(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific compatibility check.');
        }

        $length = DB::selectOne("SELECT LENGTH(UNHEX(LEFT(SHA2('length-check', 256), 32))) as len")->len;

        $this->assertSame(16, (int) $length);
    }

    /** Different keys must produce different hashes (collision resistance). */
    public function test_different_keys_produce_different_hashes(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific compatibility check.');
        }

        $one = DB::selectOne("SELECT HEX(UNHEX(LEFT(SHA2('key-one', 256), 32))) as h")->h;
        $two = DB::selectOne("SELECT HEX(UNHEX(LEFT(SHA2('key-two', 256), 32))) as h")->h;

        $this->assertNotSame($one, $two);
    }

    /** Required test 6: migration is discoverable in the normal path. */
    public function test_migration_is_discoverable(): void
    {
        $this->assertFileExists(base_path('database/migrations/2026_07_20_150026_create_pulse_tables.php'));

        $status = collect(DB::table('migrations')->pluck('migration'));

        $this->assertTrue(
            $status->contains('2026_07_20_150026_create_pulse_tables'),
            'The Pulse migration should be recorded in the migrations table after running.'
        );
    }

    /** Required test 7: Pulse tables exist after migration. */
    public function test_pulse_tables_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('pulse_values'));
        $this->assertTrue(Schema::hasTable('pulse_entries'));
        $this->assertTrue(Schema::hasTable('pulse_aggregates'));
    }

    /** Required test 8: required indexes exist. */
    public function test_required_indexes_exist(): void
    {
        $valuesIndexes = collect(Schema::getIndexes('pulse_values'))->pluck('name');
        $entriesIndexes = collect(Schema::getIndexes('pulse_entries'))->pluck('name');
        $aggregatesIndexes = collect(Schema::getIndexes('pulse_aggregates'))->pluck('name');

        $this->assertTrue($valuesIndexes->contains('pulse_values_type_key_hash_unique'));
        $this->assertTrue($entriesIndexes->contains('pulse_entries_key_hash_index'));
        $this->assertTrue($aggregatesIndexes->contains('pulse_aggregates_bucket_period_type_aggregate_key_hash_unique'));
    }

    /** Required test 5: logical uniqueness remains enforced on the real schema. */
    public function test_duplicate_logical_key_is_rejected_by_the_unique_constraint(): void
    {
        DB::table('pulse_values')->insert([
            'timestamp' => now()->timestamp,
            'type' => 'compat_uniqueness_check',
            'key' => 'duplicate-key',
            'value' => 'a',
        ]);

        $this->expectException(QueryException::class);

        DB::table('pulse_values')->insert([
            'timestamp' => now()->timestamp,
            'type' => 'compat_uniqueness_check',
            'key' => 'duplicate-key',
            'value' => 'b',
        ]);
    }
}
