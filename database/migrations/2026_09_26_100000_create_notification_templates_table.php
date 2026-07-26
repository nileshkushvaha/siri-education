<?php

declare(strict_types=1);

use App\Notifications\Templates\NotificationTemplateRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-039 requirement #2 — one row per (template_key, channel) pair,
 * pre-seeded here for every combination the code-owned registry
 * defines. Administrators may only edit an EXISTING row (subject/body/
 * is_active) — they can never insert a new key/channel pair, since the
 * Filament resource never exposes create/delete (requirement #6 "no
 * arbitrary template keys or channels").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key');
            $table->string('channel', 20);
            $table->text('subject')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_key', 'channel']);
        });

        $now = now();

        $rows = collect(NotificationTemplateRegistry::all())
            ->flatMap(fn ($definition) => collect(array_keys($definition->content))->map(fn (string $channelValue) => [
                'template_key' => $definition->key->value,
                'channel' => $channelValue,
                'subject' => null,
                'body' => null,
                'is_active' => true,
                'version' => 1,
                'edited_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]))
            ->all();

        if ($rows !== []) {
            DB::table('notification_templates')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
