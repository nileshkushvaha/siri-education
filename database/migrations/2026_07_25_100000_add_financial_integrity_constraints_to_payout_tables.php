<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15.1 — database-level financial invariants.
 *
 * 1. Allocations must carry a positive amount (enforced CHECK,
 *    MySQL 8.0.16+; this instance runs 9.x).
 * 2. Single active default payout method per instructor, emulating a
 *    partial unique index: a STORED generated column holds the
 *    instructor_id only while the row is (default AND verified AND not
 *    soft-deleted), otherwise NULL — and MySQL permits unlimited NULLs
 *    under a unique index. This is a backstop behind the service's
 *    owner-row locking, not a replacement for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE instructor_withdrawal_allocations
                ADD CONSTRAINT chk_iwa_amount_positive CHECK (amount_minor > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE instructor_payout_methods
                ADD COLUMN active_default_owner_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN is_default = 1
                             AND status = 'verified'
                             AND deleted_at IS NULL
                            THEN instructor_id
                            ELSE NULL
                        END
                    ) STORED,
                ADD UNIQUE INDEX ipm_active_default_owner_unique (active_default_owner_key)
        SQL);
    }

    public function down(): void
    {
        Schema::table('instructor_payout_methods', function ($table): void {
            $table->dropUnique('ipm_active_default_owner_unique');
            $table->dropColumn('active_default_owner_key');
        });

        DB::statement('ALTER TABLE instructor_withdrawal_allocations DROP CONSTRAINT chk_iwa_amount_positive');
    }
};
