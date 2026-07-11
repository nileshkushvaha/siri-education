<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 16A — backs the Consumed -> Reversed allocation transition. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_withdrawal_allocations', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('instructor_withdrawal_allocations', function (Blueprint $table): void {
            $table->dropColumn('reversed_at');
        });
    }
};
