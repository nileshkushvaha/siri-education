<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Support\PulseMigration;

/**
 * Phase 24O.1 — GAP-033 corrective: customized copy of Pulse v1.7.4's stock
 * migration. The ONLY change from stock is the mysql/mariadb `key_hash`
 * expression, everywhere it appears (`pulse_values`, `pulse_entries`,
 * `pulse_aggregates`) — `unhex(md5(`key`))` -> `unhex(left(sha2(`key`, 256), 32))`.
 *
 * WHY: this MySQL install (9.7.1) has no working `MD5()`/`SHA1()`/`SHA()`
 * SQL builtins (confirmed via direct probing — not an OpenSSL/config
 * toggle; `SHA2()` and `CRC32()` still work). Confirmed via full read of
 * vendor/laravel/pulse/src/Storage/DatabaseStorage.php that `key_hash` is
 * NEVER compared against an independently-computed literal anywhere in
 * Pulse's PHP runtime for the mysql/pgsql drivers — it is written only by
 * this generated column (PHP never computes it for these drivers, unlike
 * sqlite) and read only via GROUP BY / self-join-on-key_hash / upsert
 * conflict-target lists, i.e. it is a fully opaque, self-consistent
 * uniqueness/index key. The algorithm therefore does not need to match
 * MD5 — it only needs to be deterministic, collision-resistant, and
 * produce the same 16-byte binary shape the schema/column type expects.
 * `unhex(left(sha2(key, 256), 32))` takes the first 128 bits of a SHA-256
 * digest — first 32 hex chars = 16 bytes, still `char(16)` compatible —
 * verified via a scratch-table probe (VIRTUAL generated column accepted,
 * deterministic, 16-byte length, and the `UNIQUE` constraint correctly
 * rejects a repeated key exactly as before).
 *
 * pgsql and sqlite branches are stock/unmodified — this app has no
 * evidence either needs the same fix.
 *
 * UPGRADE WARNING: `php artisan vendor:publish --tag=pulse-migrations`
 * (without `--force`) will NOT overwrite this file, since Laravel's
 * publisher skips existing files by default. Do not blindly re-publish
 * with `--force` on a future `laravel/pulse` upgrade — re-apply this same
 * review first (see docs/pulse-monitoring.md).
 */
return new class extends PulseMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->shouldRun()) {
            return;
        }

        Schema::create('pulse_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            match ($this->driver()) {
                'mariadb', 'mysql' => $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(left(sha2(`key`, 256), 32))'),
                'pgsql' => $table->uuid('key_hash')->storedAs('md5("key")::uuid'),
                'sqlite' => $table->string('key_hash'),
            };
            $table->mediumText('value');

            $table->index('timestamp'); // For trimming...
            $table->index('type'); // For fast lookups and purging...
            $table->unique(['type', 'key_hash']); // For data integrity and upserts...
        });

        Schema::create('pulse_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            match ($this->driver()) {
                'mariadb', 'mysql' => $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(left(sha2(`key`, 256), 32))'),
                'pgsql' => $table->uuid('key_hash')->storedAs('md5("key")::uuid'),
                'sqlite' => $table->string('key_hash'),
            };
            $table->bigInteger('value')->nullable();

            $table->index('timestamp'); // For trimming...
            $table->index('type'); // For purging...
            $table->index('key_hash'); // For mapping...
            $table->index(['timestamp', 'type', 'key_hash', 'value']); // For aggregate queries...
        });

        Schema::create('pulse_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type');
            $table->mediumText('key');
            match ($this->driver()) {
                'mariadb', 'mysql' => $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(left(sha2(`key`, 256), 32))'),
                'pgsql' => $table->uuid('key_hash')->storedAs('md5("key")::uuid'),
                'sqlite' => $table->string('key_hash'),
            };
            $table->string('aggregate');
            $table->decimal('value', 20, 2);
            $table->unsignedInteger('count')->nullable();

            $table->unique(['bucket', 'period', 'type', 'aggregate', 'key_hash']); // Force "on duplicate update"...
            $table->index(['period', 'bucket']); // For trimming...
            $table->index('type'); // For purging...
            $table->index(['period', 'type', 'aggregate', 'bucket']); // For aggregate queries...
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_values');
        Schema::dropIfExists('pulse_entries');
        Schema::dropIfExists('pulse_aggregates');
    }
};
