<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Homework\Enums\HomeworkResourceCollection;
use App\Models\HomeworkAssignment;
use App\Models\Message;
use App\Models\Recording;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * GAP-037 — the shared StandardImageConversion definitions (thumb/
 * display/preview), applied via HasStandardImageConversions across
 * every evidence-backed collection. Covers registration correctness,
 * image-only execution, dimensions/upscale rules, storage-boundary
 * preservation, queuing, idempotency, and failure isolation.
 */
final class StandardImageConversionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    private function image(string $name = 'photo.jpg', int $width = 10, int $height = 10): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4'.str_repeat('A', 300).'%%EOF');
    }

    private function profile(): UserProfile
    {
        return User::factory()->create()->profile;
    }

    // ── Registration + dimensions ────────────────────────────────────

    public function test_avatar_gets_a_thumb_conversion_upscaled_to_a_fixed_square(): void
    {
        $profile = $this->profile();

        $profile->addMedia($this->image('avatar.jpg', 10, 10))->toMediaCollection('avatar');

        $media = $profile->fresh()->getFirstMedia('avatar');
        $this->assertTrue($media->hasGeneratedConversion('thumb'));

        [$width, $height] = getimagesize($media->getPath('thumb'));
        $this->assertSame(150, $width);
        $this->assertSame(150, $height);
    }

    public function test_cover_display_conversion_never_upscales_a_smaller_original(): void
    {
        $profile = $this->profile();

        $profile->addMedia($this->image('cover.jpg', 10, 10))->toMediaCollection('cover');

        $media = $profile->fresh()->getFirstMedia('cover');
        $this->assertTrue($media->hasGeneratedConversion('display'));

        [$width, $height] = getimagesize($media->getPath('display'));
        $this->assertSame(10, $width);
        $this->assertSame(10, $height);
    }

    public function test_display_conversion_preserves_aspect_ratio_and_caps_the_larger_dimension(): void
    {
        $profile = $this->profile();

        // 1600x400 — wider than 800, so width is the limiting dimension.
        $profile->addMedia($this->image('cover-wide.jpg', 1600, 400))->toMediaCollection('cover');

        $media = $profile->fresh()->getFirstMedia('cover');
        [$width, $height] = getimagesize($media->getPath('display'));

        $this->assertSame(800, $width);
        $this->assertSame(200, $height);
    }

    // ── Image-only execution ─────────────────────────────────────────

    public function test_image_resource_gets_a_preview_conversion_but_pdf_resource_does_not(): void
    {
        $assignment = HomeworkAssignment::factory()->create();

        $assignment->addMedia($this->image())->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);
        $assignment->addMedia($this->pdf())->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);

        $fresh = $assignment->fresh();
        $media = $fresh->getMedia(HomeworkResourceCollection::InstructorResources->value);

        $imageMedia = $media->firstWhere('mime_type', 'image/jpeg');
        $pdfMedia = $media->firstWhere('mime_type', 'application/pdf');

        $this->assertNotNull($imageMedia);
        $this->assertNotNull($pdfMedia);
        $this->assertTrue($imageMedia->hasGeneratedConversion('preview'));
        $this->assertFalse($pdfMedia->hasGeneratedConversion('preview'));
    }

    public function test_pdf_original_remains_fully_intact_and_downloadable_after_upload(): void
    {
        $assignment = HomeworkAssignment::factory()->create();
        $assignment->addMedia($this->pdf('resource.pdf'))->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);

        $media = $assignment->fresh()->getFirstMedia(HomeworkResourceCollection::InstructorResources->value);

        $this->assertNotNull($media);
        $this->assertSame('resource.pdf', $media->file_name);
        $this->assertTrue(Storage::disk('local')->exists($media->getPathRelativeToRoot()));
        $this->assertGreaterThan(0, $media->size);
    }

    public function test_message_attachment_image_gets_a_preview_but_pdf_does_not(): void
    {
        $message = Message::factory()->create();

        $message->addMedia($this->image())->toMediaCollection('attachment');
        $imageMedia = $message->fresh()->getFirstMedia('attachment');

        $this->assertTrue($imageMedia->hasGeneratedConversion('preview'));

        $otherMessage = Message::factory()->create();
        $otherMessage->addMedia($this->pdf())->toMediaCollection('attachment');
        $pdfMedia = $otherMessage->fresh()->getFirstMedia('attachment');

        $this->assertFalse($pdfMedia->hasGeneratedConversion('preview'));
    }

    // ── No conversions for Recording (Phase 40) ──────────────────────

    public function test_recording_collection_registers_no_conversions(): void
    {
        $recording = Recording::factory()->create();

        $recording->registerAllMediaConversions();

        $this->assertSame([], $recording->mediaConversions);
    }

    // ── Storage boundary preservation ────────────────────────────────

    public function test_avatar_conversion_stays_on_the_same_public_disk_as_the_original(): void
    {
        $profile = $this->profile();
        $profile->addMedia($this->image())->toMediaCollection('avatar');

        $media = $profile->fresh()->getFirstMedia('avatar');

        $this->assertSame('public', $media->disk);
        $this->assertTrue(Storage::disk('public')->exists($media->getPathRelativeToRoot('thumb')));
    }

    public function test_homework_conversion_stays_on_the_same_private_local_disk_as_the_original(): void
    {
        $assignment = HomeworkAssignment::factory()->create();
        $assignment->addMedia($this->image())->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);

        $media = $assignment->fresh()->getFirstMedia(HomeworkResourceCollection::InstructorResources->value);

        $this->assertSame('local', $media->disk);
        $this->assertTrue(Storage::disk('local')->exists($media->getPathRelativeToRoot('preview')));
        // Never copied to the public disk.
        $this->assertFalse(Storage::disk('public')->exists($media->getPathRelativeToRoot('preview')));
    }

    // ── Original preservation ─────────────────────────────────────────

    public function test_original_file_is_preserved_unchanged_after_conversions_run(): void
    {
        $profile = $this->profile();
        $profile->addMedia($this->image('avatar.jpg', 10, 10))->toMediaCollection('avatar');

        $media = $profile->fresh()->getFirstMedia('avatar');
        $originalSize = $media->size;

        $this->assertTrue(Storage::disk('public')->exists($media->getPathRelativeToRoot()));
        [$origWidth, $origHeight] = getimagesize($media->getPath());
        $this->assertSame(10, $origWidth);
        $this->assertSame(10, $origHeight);
        $this->assertSame($originalSize, $media->fresh()->size);
    }

    // ── Queued processing ─────────────────────────────────────────────

    public function test_conversions_are_dispatched_through_the_queue_not_run_inline(): void
    {
        Queue::fake();

        $profile = $this->profile();
        $profile->addMedia($this->image())->toMediaCollection('avatar');

        Queue::assertPushed(PerformConversionsJob::class);

        // The fake queue never actually runs the job, so nothing is
        // generated yet — proving this genuinely goes through the
        // queue rather than running synchronously inline.
        $media = $profile->fresh()->getFirstMedia('avatar');
        $this->assertFalse($media->hasGeneratedConversion('thumb'));
    }

    // ── Idempotent (re)processing ─────────────────────────────────────

    public function test_regenerating_conversions_is_idempotent_and_creates_no_duplicate_media_rows(): void
    {
        $profile = $this->profile();
        $profile->addMedia($this->image())->toMediaCollection('avatar');

        $media = $profile->fresh()->getFirstMedia('avatar');
        $fileManipulator = app(FileManipulator::class);

        $fileManipulator->createDerivedFiles($media);
        $fileManipulator->createDerivedFiles($media);

        $this->assertSame(1, Media::query()->where('collection_name', 'avatar')->count());
        $this->assertTrue($media->fresh()->hasGeneratedConversion('thumb'));
    }

    // ── Conversion failure isolation ──────────────────────────────────

    public function test_a_failed_conversion_attempt_never_deletes_the_original_media_row(): void
    {
        $profile = $this->profile();
        $profile->addMedia($this->image())->toMediaCollection('avatar');

        $media = $profile->fresh()->getFirstMedia('avatar');
        $mediaId = $media->id;

        // Force a genuine processing failure: remove the original file
        // from disk before re-running conversion generation, so
        // FileManipulator cannot read a source file to convert.
        Storage::disk('public')->delete($media->getPathRelativeToRoot());

        try {
            app(FileManipulator::class)->createDerivedFiles($media);
        } catch (\Throwable) {
            // Expected — the point of this test is what survives, not the exception itself.
        }

        $this->assertNotNull(Media::query()->find($mediaId));
    }

    // ── Bounded rendering queries ──────────────────────────────────────

    public function test_rendering_conversion_urls_for_many_media_items_issues_no_extra_queries(): void
    {
        $assignment = HomeworkAssignment::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $assignment->addMedia($this->image("r{$i}.jpg"))->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);
        }

        $media = $assignment->fresh()->getMedia(HomeworkResourceCollection::InstructorResources->value);

        DB::enableQueryLog();
        foreach ($media as $item) {
            $item->hasGeneratedConversion('preview');
            if (str_starts_with($item->mime_type, 'image/')) {
                $item->getAvailableUrl(['preview']);
            }
        }
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 5; $i < 25; $i++) {
            $assignment->addMedia($this->image("r{$i}.jpg"))->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);
        }

        $largeMedia = $assignment->fresh()->getMedia(HomeworkResourceCollection::InstructorResources->value);

        DB::enableQueryLog();
        foreach ($largeMedia as $item) {
            $item->hasGeneratedConversion('preview');
            if (str_starts_with($item->mime_type, 'image/')) {
                $item->getAvailableUrl(['preview']);
            }
        }
        $largeQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Reading already-loaded Media attributes never issues a query
        // per item — 5 vs 25 items costs the same zero extra queries.
        $this->assertSame(0, $smallCount);
        $this->assertSame(0, $largeQueryCount);
    }
}
