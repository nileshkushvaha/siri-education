<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Per-booking meeting creation state — distinct from
            // meeting_provider/meeting_ref/meeting_url, which only ever
            // told you the last-known link, never whether creation was
            // attempted, succeeded, or failed.
            $table->string('meeting_status', 20)->default('pending')->after('meeting_url');
            // Zoom-style host/start link — never exposed to students.
            $table->string('meeting_host_url', 2048)->nullable()->after('meeting_status');
            $table->string('meeting_password', 255)->nullable()->after('meeting_host_url');
            // Sanitized provider metadata only — never raw payloads/secrets.
            $table->json('meeting_metadata')->nullable()->after('meeting_password');

            $table->index('meeting_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['meeting_status']);
            $table->dropColumn(['meeting_status', 'meeting_host_url', 'meeting_password', 'meeting_metadata']);
        });
    }
};
