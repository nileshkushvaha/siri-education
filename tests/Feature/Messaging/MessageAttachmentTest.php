<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\Services\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/** SRS §17.34: PDF/image attachments only, strict size/type restrictions, existing Media Library pattern reused. */
class MessageAttachmentTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
        Storage::fake('public');
    }

    public function test_a_pdf_attachment_is_accepted_and_stored(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $message = app(MessagingService::class)->send(
            $conversation,
            $student,
            'Here is the homework.',
            // UploadedFile::fake()->create() with a size argument produces
            // a file whose *reported* size is correct but whose real
            // on-disk content is empty in this environment — Spatie Media
            // Library detects the real (empty) mime and rejects it.
            // createWithContent() writes real bytes with a genuine PDF
            // signature so mime detection succeeds.
            UploadedFile::fake()->createWithContent('homework.pdf', '%PDF-1.4'.str_repeat('A', 500).'%%EOF'),
        );

        $this->assertNotNull($message->getFirstMedia('attachment'));
        $this->assertSame('application/pdf', $message->getFirstMedia('attachment')->mime_type);
    }

    public function test_an_image_attachment_is_accepted(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $message = app(MessagingService::class)->send(
            $conversation,
            $student,
            'Screenshot attached.',
            UploadedFile::fake()->image('screenshot.jpg'),
        );

        $this->assertNotNull($message->getFirstMedia('attachment'));
    }

    public function test_the_send_message_request_rejects_disallowed_file_types(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), [
                'body' => 'Here is an executable.',
                'attachment' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('attachment');
    }

    public function test_the_send_message_request_rejects_oversized_files(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), [
                'body' => 'Big file.',
                'attachment' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('attachment');
    }
}
