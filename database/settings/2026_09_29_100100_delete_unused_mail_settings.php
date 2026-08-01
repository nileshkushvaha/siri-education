<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Deletes three mail settings that were editable in the admin panel but
     * never read by any code.
     *
     *  - mail.transactional_domain — displayed as "Verified Sending Domain",
     *    but nothing validated a sender against it, so filling it in implied a
     *    safety check that did not exist.
     *  - mail.queue_emails — labelled "Send emails asynchronously via the queue
     *    worker", but queueing is decided by each notification's ShouldQueue
     *    implementation, not by this flag. Toggling it off would not have made
     *    a single email send synchronously.
     *  - mail.retry_attempts — retries are owned by each notification's $tries
     *    and $backoff (see ConfiguresTransactionalEmail), not by this value.
     *
     * Each one was actively misleading rather than merely unused: an operator
     * could change it, see it persist, and reasonably conclude the platform's
     * behaviour had changed. Deleting them is safe precisely because nothing
     * consumed them — no behaviour changes here.
     *
     * Re-adding any of them later should mean wiring it up in the same change.
     */
    public function up(): void
    {
        $this->migrator->deleteIfExists('mail.transactional_domain');
        $this->migrator->deleteIfExists('mail.queue_emails');
        $this->migrator->deleteIfExists('mail.retry_attempts');
    }
};
