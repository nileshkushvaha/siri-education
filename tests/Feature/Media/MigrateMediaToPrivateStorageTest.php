<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * media:migrate-to-private. Historical rows
 * are simulated by forcing an upload onto the 'public' disk via
 * toMediaCollection($collection, 'public') — the second parameter is
 * Spatie's own disk override, standing in for "uploaded before this
 * phase changed the collection's configured disk to local."
 */
final class MigrateMediaToPrivateStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    private function image(string $name = 'evidence.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function historicalMessageAttachment(): Media
    {
        $student = User::factory()->create();
        $instructor = User::factory()->create();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment', 'public');

        return $message->fresh()->getFirstMedia('attachment');
    }

    public function test_dry_run_reports_candidates_without_changing_anything(): void
    {
        $media = $this->historicalMessageAttachment();

        $this->artisan('media:migrate-to-private', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('public', $media->fresh()->disk);
        $this->assertTrue(Storage::disk('public')->exists($media->getPathRelativeToRoot()));
        $this->assertFalse(Storage::disk('local')->exists($media->getPathRelativeToRoot()));
    }

    public function test_successful_run_copies_verifies_updates_and_deletes_the_public_original(): void
    {
        $media = $this->historicalMessageAttachment();
        $path = $media->getPathRelativeToRoot();
        $originalSize = Storage::disk('public')->size($path);

        $this->artisan('media:migrate-to-private')->assertSuccessful();

        $fresh = $media->fresh();
        $this->assertSame('local', $fresh->disk);
        $this->assertSame($media->id, $fresh->id);
        $this->assertSame($media->model_type, $fresh->model_type);
        $this->assertSame($media->model_id, $fresh->model_id);
        $this->assertSame($media->collection_name, $fresh->collection_name);

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame($originalSize, Storage::disk('local')->size($path));
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_conversion_files_are_migrated_alongside_the_original(): void
    {
        $media = $this->historicalMessageAttachment();
        $this->assertTrue($media->hasGeneratedConversion('preview'));
        $previewPath = $media->getPathRelativeToRoot('preview');
        $this->assertTrue(Storage::disk('public')->exists($previewPath));

        $this->artisan('media:migrate-to-private')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($previewPath));
        $this->assertFalse(Storage::disk('public')->exists($previewPath));
    }

    public function test_a_failed_copy_leaves_the_original_and_the_media_row_untouched(): void
    {
        $media = $this->historicalMessageAttachment();
        $path = $media->getPathRelativeToRoot();

        // Simulate a source file that vanished before the migration ran.
        Storage::disk('public')->delete($path);

        $this->artisan('media:migrate-to-private')->assertFailed();

        $fresh = $media->fresh();
        $this->assertSame('public', $fresh->disk);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_rerunning_after_a_successful_migration_is_a_safe_no_op(): void
    {
        $media = $this->historicalMessageAttachment();

        $this->artisan('media:migrate-to-private')->assertSuccessful();
        $firstRunDisk = $media->fresh()->disk;

        $this->artisan('media:migrate-to-private')->assertSuccessful();

        $this->assertSame('local', $firstRunDisk);
        $this->assertSame('local', $media->fresh()->disk);
        $this->assertSame(1, Media::query()->where('id', $media->id)->count());
    }

    public function test_batch_size_bounds_how_many_rows_are_processed_per_run(): void
    {
        $student = User::factory()->create();
        $instructor = User::factory()->create();
        $conversation = Conversation::factory()->between($student, $instructor)->create();

        for ($i = 0; $i < 5; $i++) {
            $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
            $message->addMedia($this->image("a{$i}.jpg"))->toMediaCollection('attachment', 'public');
        }

        $this->artisan('media:migrate-to-private', ['--batch-size' => 2])->assertSuccessful();

        $this->assertSame(2, Media::query()->where('collection_name', 'attachment')->where('disk', 'local')->count());
        $this->assertSame(3, Media::query()->where('collection_name', 'attachment')->where('disk', 'public')->count());
    }

    public function test_multiple_collections_are_covered_in_one_command(): void
    {
        $messageMedia = $this->historicalMessageAttachment();

        $student = User::factory()->create();
        $case = SupportCase::factory()->create(['student_id' => $student->id, 'created_by' => $student->id]);
        $case->addMedia($this->image('proof.jpg'))->toMediaCollection('evidence', 'public');
        $caseMedia = $case->fresh()->getFirstMedia('evidence');

        $this->artisan('media:migrate-to-private')->assertSuccessful();

        $this->assertSame('local', $messageMedia->fresh()->disk);
        $this->assertSame('local', $caseMedia->fresh()->disk);
    }
}
