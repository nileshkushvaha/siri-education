<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a recording's object came from: the automated provider pipeline
 * (every row so far) or an administrator attaching a file by hand after
 * the pipeline failed or never ran. Provenance only — status, storage,
 * retention and access rules are identical for both. Kept separate from
 * `provider`, which ingestion compares against the meeting's provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->string('source', 30)->default('pipeline')->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
