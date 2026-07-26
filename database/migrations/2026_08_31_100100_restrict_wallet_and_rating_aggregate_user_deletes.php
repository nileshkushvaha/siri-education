<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A live FK audit found three more `users`-referencing cascades
 * reaching historical child records that must be protected (wallet
 * ledger entries, instructor rating aggregates), unreachable via the
 * booking-centric chain an earlier migration covered but still
 * directly deletable by physically deleting a `users` row. Same
 * treatment: `ON DELETE RESTRICT`, no data touched.
 */
return new class extends Migration
{
    /** @var list<array{table: string, column: string}> */
    private array $constraints = [
        ['table' => 'wallets', 'column' => 'user_id'],
        ['table' => 'wallet_ledger_entries', 'column' => 'user_id'],
        ['table' => 'instructor_rating_aggregates', 'column' => 'instructor_id'],
    ];

    public function up(): void
    {
        foreach ($this->constraints as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk): void {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references('id')->on('users')
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
                    ->references('id')->on('users')
                    ->cascadeOnDelete();
            });
        }
    }
};
