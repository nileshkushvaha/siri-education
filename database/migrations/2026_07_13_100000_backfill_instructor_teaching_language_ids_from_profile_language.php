<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $languages = DB::table('languages')
            ->where('status', 'active')
            ->get(['id', 'code', 'name'])
            ->flatMap(fn (object $language): array => [
                mb_strtolower((string) $language->code) => (string) $language->id,
                mb_strtolower((string) $language->name) => (string) $language->id,
            ]);

        if ($languages->isEmpty()) {
            return;
        }

        DB::table('user_profiles')
            ->whereNotNull('language')
            ->orderBy('id')
            ->each(function (object $profile) use ($languages): void {
                $existing = $this->decodeIds($profile->instructor_teaching_language_ids ?? null);

                if ($existing !== []) {
                    return;
                }

                $mappedIds = collect(preg_split('/[,|]/', (string) $profile->language) ?: [])
                    ->map(fn (string $language): string => mb_strtolower(trim($language)))
                    ->filter()
                    ->map(fn (string $language): ?string => $languages->get($language))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($mappedIds === []) {
                    return;
                }

                DB::table('user_profiles')
                    ->where('id', $profile->id)
                    ->update(['instructor_teaching_language_ids' => json_encode($mappedIds)]);
            });
    }

    public function down(): void
    {
        // Data backfill only. Do not remove instructor teaching-language IDs on rollback.
    }

    private function decodeIds(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
