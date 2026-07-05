<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->foreignId('default_currency_id')->nullable()->after('flag')->constrained('currencies')->nullOnDelete();
            $table->foreignId('default_language_id')->nullable()->after('default_currency_id')->constrained('languages')->nullOnDelete();
            $table->string('default_timezone', 80)->nullable()->after('default_language_id');
            $table->string('support_email')->nullable()->after('default_timezone');
            $table->string('support_phone', 50)->nullable()->after('support_email');
            $table->string('date_format', 40)->nullable()->after('support_phone');
            $table->string('time_format', 40)->nullable()->after('date_format');
            $table->string('number_format', 40)->nullable()->after('time_format');
            $table->json('feature_flags')->nullable()->after('number_format');
            $table->json('payment_routing')->nullable()->after('feature_flags');

            $table->index('default_timezone');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->dropForeign(['default_currency_id']);
            $table->dropForeign(['default_language_id']);
            $table->dropIndex(['default_timezone']);
            $table->dropColumn([
                'default_currency_id',
                'default_language_id',
                'default_timezone',
                'support_email',
                'support_phone',
                'date_format',
                'time_format',
                'number_format',
                'feature_flags',
                'payment_routing',
            ]);
        });
    }
};
