<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` is referenced by 238 foreign keys, 83 of them RESTRICT/NO ACTION,
 * so a hard delete of any user with history fails at the database and would
 * destroy financial audit trail if it did not.
 *
 * UserPolicy already defined restore()/forceDelete() and the Restore:User /
 * ForceDelete:User permissions already existed — only the column was missing.
 *
 * Not a replacement for `users.status`: status is reversible lifecycle state,
 * soft delete is removal from view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Indexed: the global scope adds `deleted_at is null` to every
            // query on this table.
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
