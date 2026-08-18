<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §12.18/§12.21 — moves recording BINARY storage off the local
 * Media Library collection and behind the provider-neutral
 * RecordingStorage abstraction (Google Drive now, Amazon S3 later).
 *
 * storage_driver + storage_path together are the provider-neutral
 * LOCATOR. They are deliberately generic: for Google Drive the path is
 * a file id, for a filesystem/S3 disk it is an object key. Nothing
 * outside the owning storage adapter may parse either value — that is
 * what keeps a `google_drive_file_id` column (and Google-shaped
 * business logic) from ever existing.
 *
 * storage_driver is stored PER ROW rather than read from config so
 * that switching the configured backend later leaves every existing
 * recording readable and deletable through the backend that actually
 * holds it.
 *
 * The (storage_driver, storage_path) unique index is the database-level
 * guarantee behind idempotency: a replayed webhook, a re-run sweep,
 * and a retried job can never end up with two rows pointing at two
 * copies of the same object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            // Provider-neutral storage locator.
            $table->string('storage_driver', 30)->nullable()->after('provider_reference');
            $table->string('storage_path', 512)->nullable()->after('storage_driver');
            // sha256 of the staged source bytes — the integrity value we
            // can compute locally without re-downloading the object.
            $table->string('storage_checksum', 64)->nullable()->after('storage_path');

            // Lifecycle timestamps. available_at already exists and now
            // means "verified and serveable", not merely "uploaded".
            $table->timestamp('transfer_started_at')->nullable()->after('recorded_at');
            $table->timestamp('stored_at')->nullable()->after('transfer_started_at');

            $table->unique(['storage_driver', 'storage_path'], 'recordings_storage_locator_unique');
            // Drives the stale-transfer reclaim in recordings:capture.
            $table->index(['status', 'transfer_started_at'], 'recordings_status_transfer_index');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropUnique('recordings_storage_locator_unique');
            $table->dropIndex('recordings_status_transfer_index');
            $table->dropColumn([
                'storage_driver',
                'storage_path',
                'storage_checksum',
                'transfer_started_at',
                'stored_at',
            ]);
        });
    }
};
