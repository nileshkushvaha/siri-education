# Resend Transactional Email

## Provider

Production transactional email uses **Resend** through the official `resend/resend-laravel` package.

```dotenv
MAIL_MAILER=resend
RESEND_API_KEY=...
MAIL_FROM_ADDRESS=noreply@sirieducation.com
MAIL_FROM_NAME="${APP_NAME}"
```

Local development may override `MAIL_MAILER=log`; tests may use `MAIL_MAILER=array`.

## Architecture

Transactional email must flow through Laravel Notifications or Mailables on the queue.

```
Domain event
    -> queued listener
    -> queued notification
    -> configured mailer
    -> email_logs table
    -> Resend webhook status reconciliation
```

Do not send email directly from controllers, Livewire components, or Filament resources. Use `TransactionalNotificationService` when an on-demand mail route is required.

## Sender Settings

`MailSettings` stores the global sender and category-specific transactional sender addresses:

- Auth
- Booking
- Payment
- Tutor
- Wallet
- Support
- Admin alerts

These addresses must belong to a verified Resend sending domain in production.

## Logging

`email_logs` tracks:

- `pending` when Laravel begins sending a mail notification
- `sent` when Laravel/Resend accepts the message
- `delivered`, `failed`, `bounced`, `complained`, `delayed`, or `suppressed` from Resend webhooks

Admins can review logs in Filament under **System -> Email Logs**.

## Resend Webhooks

The Resend package registers:

```text
POST /resend/webhook
```

Set `RESEND_WEBHOOK_SECRET` in production and configure the same signing secret in Resend. The application listens to Resend email webhook events and reconciles provider status into `email_logs`.

## DNS And Domain Verification

Before setting `MAIL_MAILER=resend` in production:

1. Add the sending domain in Resend.
2. Add the DNS records Resend provides for the domain.
3. Verify SPF is present for the sender domain.
4. Verify DKIM records are present and passing.
5. Keep DMARC configured for the domain, even if the initial policy is monitoring-only.
6. Use only verified-domain addresses in Mail Settings.
7. Send a test email from the admin Mail Settings page and confirm the Email Logs row reaches `sent` or `delivered`.

Do not use personal mailbox addresses as production `from` addresses unless the domain is verified and aligned.
