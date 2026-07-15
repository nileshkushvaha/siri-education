<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17U.2 §1 — closes the Phase 17U.1 residual. That phase's
 * migration deliberately left `booking_guests.booking_id` and
 * `booking_meetings.booking_id` as CASCADE, reasoning they were
 * booking configuration/connection metadata rather than historical
 * records. This phase's task treats them as part of booking history
 * that must survive a parent-record hard-delete attempt exactly like
 * every other dependent covered in 2026_08_31_100000. Constraint
 * names confirmed live via information_schema immediately before
 * writing this migration (both follow the standard
 * `{table}_{column}_foreign` convention). No table is dropped or
 * recreated; only the `ON DELETE` rule changes; no data is touched.
 * The already-applied 2026_08_31_100000 migration is not modified.
 */
return new class extends Migration
{
    /** @var list<array{table: string, column: string, references: string}> */
    private array $constraints = [
        ['table' => 'booking_guests', 'column' => 'booking_id', 'references' => 'bookings'],
        ['table' => 'booking_meetings', 'column' => 'booking_id', 'references' => 'bookings'],
    ];

    public function up(): void
    {
        foreach ($this->constraints as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk): void {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references('id')->on($fk['references'])
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->constraints) as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk): void {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references('id')->on($fk['references'])
                    ->cascadeOnDelete();
            });
        }
    }
};
