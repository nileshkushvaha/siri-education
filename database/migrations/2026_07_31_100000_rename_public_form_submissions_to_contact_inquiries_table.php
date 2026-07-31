<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('public_form_submissions', 'contact_inquiries');
    }

    public function down(): void
    {
        Schema::rename('contact_inquiries', 'public_form_submissions');
    }
};
