<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable()->after('must_change_password');
            $table->timestamp('privacy_accepted_at')->nullable()->after('terms_accepted_at');
            $table->string('terms_version', 50)->nullable()->after('privacy_accepted_at');
            $table->string('privacy_version', 50)->nullable()->after('terms_version');
            $table->string('terms_accepted_ip', 45)->nullable()->after('privacy_version');
            $table->string('privacy_accepted_ip', 45)->nullable()->after('terms_accepted_ip');
            $table->text('terms_accepted_user_agent')->nullable()->after('privacy_accepted_ip');
            $table->text('privacy_accepted_user_agent')->nullable()->after('terms_accepted_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'terms_accepted_at',
                'privacy_accepted_at',
                'terms_version',
                'privacy_version',
                'terms_accepted_ip',
                'privacy_accepted_ip',
                'terms_accepted_user_agent',
                'privacy_accepted_user_agent',
            ]);
        });
    }
};
