<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->char('phone_country_iso2', 2)->nullable()->after('phone');
            $table->string('phone_dial_code', 8)->nullable()->after('phone_country_iso2');
            $table->string('phone_national_number', 20)->nullable()->after('phone_dial_code');
            $table->string('phone_e164', 18)->nullable()->index()->after('phone_national_number');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_e164');
            $table->string('phone_verification_status', 20)->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', fn (Blueprint $table) => $table->dropColumn([
            'phone_country_iso2', 'phone_dial_code', 'phone_national_number', 'phone_e164',
            'phone_verified_at', 'phone_verification_status',
        ]));
    }
};
