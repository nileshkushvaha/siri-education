<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert plaintext capability tokens to SHA-256 hashes (both are
        // 64 chars, so the column fits). Guests keep their existing links:
        // lookups hash the presented token before comparing.
        DB::statement('UPDATE bookings SET manage_token = SHA2(manage_token, 256) WHERE manage_token IS NOT NULL');
    }

    public function down(): void
    {
        // Irreversible by design — plaintext tokens are gone. Rolling back
        // the code without this data change would invalidate guest links.
    }
};
