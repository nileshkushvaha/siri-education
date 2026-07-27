<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §22.25/26: managed 301/302 redirects.
 *
 * `active_source_path` is a generated column equal to `source_path`
 * when `is_active` is true, else NULL. MySQL's unique index treats
 * multiple NULLs as distinct rows, so this gives "unique among ACTIVE
 * sources only" — deactivated redirects keep their row (auditability,
 * requirement #5) without blocking a new active redirect from later
 * reusing the same source path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_path', 191);
            $table->string('target_path', 191);
            $table->string('type', 3);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('source_path');
            $table->index('is_active');
        });

        Schema::table('redirects', function (Blueprint $table): void {
            $table->string('active_source_path', 191)
                ->nullable()
                ->virtualAs('CASE WHEN is_active = 1 THEN source_path ELSE NULL END')
                ->after('is_active');

            $table->unique('active_source_path', 'redirects_active_source_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
