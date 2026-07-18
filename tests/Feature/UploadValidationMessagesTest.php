<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Laravel's default "uploaded" message ("The :attribute failed to
 * upload.") gives no reason — this almost always means the browser sent
 * a file larger than upload_max_filesize, which PHP rejects before
 * Laravel ever sees the content. lang/en/validation.php overrides this
 * globally (it fires from Livewire's own temp-upload endpoint, not just
 * app FormRequests) to name the actual limit and suggest a fix.
 */
final class UploadValidationMessagesTest extends TestCase
{
    public function test_uploaded_rule_message_names_the_server_upload_limit(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);
        // Simulate what PHP itself reports when a file exceeds
        // upload_max_filesize — this is the actual failure Livewire's
        // upload endpoint hits, not a Laravel-level rule.
        $file = new UploadedFile($file->getPathname(), $file->getClientOriginalName(), $file->getClientMimeType(), UPLOAD_ERR_INI_SIZE, true);

        // Laravel doesn't run "uploaded" as an ordinary rule — it's an
        // automatic check inside Validator::validateAttribute() that fires
        // whenever the value is an invalid UploadedFile and the attribute
        // has any file-related rule (like "file") attached.
        $validator = Validator::make(['files' => [$file]], ['files.0' => 'file']);

        $this->assertTrue($validator->fails());
        $message = $validator->errors()->first('files.0');

        $this->assertStringContainsString('could not be uploaded', $message);
        $this->assertStringContainsString(ini_get('upload_max_filesize'), $message);
        $this->assertStringNotContainsString('files.0', $message);
    }
}
