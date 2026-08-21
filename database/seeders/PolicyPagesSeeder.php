<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Content\Redirects\Enums\RedirectType;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\Seeder;

/**
 * The four policy pages a payment gateway looks for during merchant
 * review: Terms and Conditions, Privacy Policy, Cancellation and Refund,
 * and Shipping and Exchange.
 *
 * ORDINARY CMS PAGES. Same Page model, same /{slug} route, same
 * SeoManager, same admin editor as About Us and Contact Us — these carry
 * prose rather than a block stack, so they follow AboutUsPageSeeder's
 * `content` shape rather than the country pages' block shape.
 *
 * NOT MARKED data-cms-structured-page ON PURPOSE. That marker makes
 * StructuredPageContentService revert any update whose HTML drops it,
 * which is right for a designed marketing page and wrong for a legal
 * document that counsel must be able to rewrite freely in the admin.
 *
 * CONTENT DISCIPLINE. The wording is deliberately general: it states the
 * rules that exist without promising a specific refund timeline, a fixed
 * cancellation window, or a processing speed the platform does not
 * control. The cancellation window is configurable
 * (BookingSettings::cancellation_window_hours), so the copy points at
 * "the window shown on your booking" rather than baking in a number that
 * an admin change would silently make false.
 *
 * OPERATED BY AN INDIVIDUAL. The copy names a natural person trading
 * under the platform's brand rather than a registered company, because
 * that is the current position. Payment gateways verify the merchant name
 * against the PAN/ID and bank account behind the payout, so an unregistered
 * operator must appear here under their own legal name — claiming a
 * company that does not exist fails review and misstates who the contract
 * is with. When a company is later incorporated, the entity sentences in
 * terms() and privacy() are the only places that need rewriting.
 *
 * PLACEHOLDERS. Every value that must match identity documents is written
 * as [REPLACE: ...] so it is impossible to publish by accident. Contact
 * details are injected from GeneralSettings at seed time.
 */
class PolicyPagesSeeder extends Seeder
{
    public function run(GeneralSettings $settings): void
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->pages($settings) as $definition) {
            $page = Page::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'title' => $definition['title'],
                    'excerpt' => $definition['excerpt'],
                    'content' => $definition['content'],
                    'template' => 'default',
                    'layout' => 'default',
                    'status' => PageStatus::Published,
                    'visibility' => PageVisibility::Public,
                    'published_at' => now(),
                    'meta_title' => $definition['meta_title'],
                    'meta_description' => $definition['meta_description'],
                    'canonical_url' => url('/'.$definition['slug']),
                    // Inherit the global robots directive — see
                    // CountryLandingPageSeeder for why this is not forced.
                    'robots' => null,
                ],
            );

            $page->wasRecentlyCreated ? $created++ : $skipped++;

            cache()->forget('page-render:'.$page->getKey());
        }

        $this->legacyTermsRedirect();

        $this->command?->info("✓ Policy pages: {$created} created, {$skipped} already present and left untouched.");
        $this->command?->warn('  · Search the four pages for "[REPLACE:" and fill in your details before submitting for gateway review.');
    }

    /**
     * The site linked to /terms-of-service before this page set existed,
     * and an environment that already published one will have inbound
     * links to it. A managed 301 keeps those working.
     *
     * Inert where a real /terms-of-service page still exists: redirects
     * are resolved from the 404 handler, so a live page always wins.
     * That makes this safe to run before an old page is retired.
     */
    private function legacyTermsRedirect(): void
    {
        // redirects.created_by is NOT NULL by design — every managed
        // redirect is attributable to a person. A seeder has no
        // authenticated user, so it borrows the first super admin and
        // skips entirely rather than inventing an unattributed row.
        $actor = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->orderBy('id')
            ->first();

        if ($actor === null) {
            $this->command?->warn('  · Skipped the /terms-of-service redirect: no super admin exists to attribute it to.');

            return;
        }

        Redirect::query()->firstOrCreate(
            ['source_path' => '/terms-of-service'],
            [
                'target_path' => '/terms-and-conditions',
                'type' => RedirectType::Permanent,
                'is_active' => true,
                'description' => 'Terms of Service was renamed to Terms and Conditions.',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ],
        );
    }

    /** @return list<array<string, string>> */
    private function pages(GeneralSettings $settings): array
    {
        $app = $settings->app_name ?: config('app.name');
        $email = $settings->support_email ?: '[REPLACE: support email address]';
        $phone = $settings->support_phone ?: '[REPLACE: support phone number]';
        $address = $settings->address ?: '[REPLACE: registered business address]';

        return [
            [
                'slug' => 'terms-and-conditions',
                'title' => 'Terms and Conditions',
                'excerpt' => 'The terms that govern your use of the platform, your account, bookings, lessons and payments.',
                'meta_title' => 'Terms and Conditions',
                'meta_description' => 'The terms governing accounts, bookings, lessons, payments and acceptable use on the platform.',
                'content' => $this->terms($app, $email, $address, $phone),
            ],
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'excerpt' => 'What personal information we collect, why we collect it, who it is shared with, and the choices you have.',
                'meta_title' => 'Privacy Policy',
                'meta_description' => 'What personal information we collect, how it is used and shared, how long it is kept, and your choices.',
                'content' => $this->privacy($app, $email, $address),
            ],
            [
                'slug' => 'cancellation-and-refund-policy',
                'title' => 'Cancellation and Refund Policy',
                'excerpt' => 'How to cancel or reschedule a lesson, when a cancellation qualifies for a refund, and how refunds are returned.',
                'meta_title' => 'Cancellation and Refund Policy',
                'meta_description' => 'How to cancel or reschedule a lesson, when a cancellation qualifies for a refund, and how refunds are returned.',
                'content' => $this->cancellation($app, $email),
            ],
            [
                'slug' => 'shipping-and-exchange-policy',
                'title' => 'Shipping and Exchange Policy',
                'excerpt' => 'How our services are delivered. We provide online tutoring only and do not ship physical goods.',
                'meta_title' => 'Shipping and Exchange Policy',
                'meta_description' => 'How services are delivered on the platform. Online tutoring only, delivered digitally, with no physical shipment.',
                'content' => $this->shipping($app, $email),
            ],
        ];
    }

    private function terms(string $app, string $email, string $address, string $phone): string
    {
        return <<<HTML
        <div class="policy-document">
            <p class="policy-meta">These terms apply to everyone who uses {$app}. Please read them before creating an account or booking a lesson.</p>

            <h2>1. About these terms</h2>
            <p>These Terms and Conditions form an agreement between you and <strong>[REPLACE: your full legal name, exactly as it appears on your ID and bank account]</strong>, an individual operating {$app} as a sole proprietor (referred to as "we", "us" or "the platform"). By creating an account, booking a lesson, or otherwise using the platform, you agree to these terms. If you do not agree with them, please do not use the platform.</p>

            <h2>2. Who may use the platform</h2>
            <p>You may hold an account if you are able to enter into a binding agreement in your country of residence. Where a student is a minor, the account must be created and supervised by a parent or legal guardian, who accepts these terms on the student's behalf and is responsible for all activity on that account.</p>
            <p>You are responsible for keeping your login credentials confidential and for all activity that takes place under your account. Tell us promptly if you believe your account has been accessed without your permission.</p>

            <h2>3. What the platform provides</h2>
            <p>{$app} provides live, one-to-one online tutoring, together with the supporting tools around it: instructor discovery, scheduling, lesson delivery, homework and feedback, learning plans, progress records, messaging and payment records. All services are delivered online. We do not sell or ship physical goods.</p>
            <p>We do not guarantee any particular academic result, examination outcome, grade, or level of improvement. Tutoring is a service, and outcomes depend on many factors outside our control.</p>

            <h2>4. Instructors</h2>
            <p>Instructors apply to teach on the platform and are reviewed before they are able to accept students. Registering as an instructor does not by itself grant teaching access.</p>
            <p>Instructors are responsible for the teaching they deliver. Information shown on an instructor's public profile is provided by that instructor, and we present only the information they have submitted and we have approved for public display.</p>

            <h2>5. Bookings and scheduling</h2>
            <p>Lessons are booked through your account against the availability an instructor has published. Times are shown in the timezone recorded on your account. Once a booking is confirmed, both the student and the instructor are expected to attend at the scheduled time.</p>
            <p>Rescheduling is available within the limits shown on your booking. Cancellations and refunds are governed by our <a href="/cancellation-and-refund-policy">Cancellation and Refund Policy</a>, which forms part of these terms.</p>

            <h2>6. Fees and payment</h2>
            <p>Prices applicable to a booking are shown before you confirm it. Payment is taken through third-party payment providers; we do not store your full card or banking credentials. Where your account holds wallet credit, it may be applied to eligible bookings in line with the rules shown at the time of booking.</p>
            <p>You are responsible for any taxes, bank charges or currency conversion costs your own bank or payment provider applies.</p>

            <h2>7. Acceptable use</h2>
            <p>When using the platform you agree not to:</p>
            <ul>
                <li>misrepresent your identity, age or academic context;</li>
                <li>harass, abuse, threaten or discriminate against any other user;</li>
                <li>share account access with anyone else, or resell access to lessons;</li>
                <li>attempt to move a tutoring relationship off the platform to avoid fees;</li>
                <li>record, copy or redistribute a lesson or its materials without permission;</li>
                <li>upload unlawful, infringing or harmful content; or</li>
                <li>interfere with, probe or attempt to gain unauthorised access to the platform.</li>
            </ul>

            <h2>8. Content and intellectual property</h2>
            <p>The platform, its software, design and branding remain our property or that of our licensors. Teaching materials remain the property of whoever created them. When you upload content — including homework submissions and messages — you keep ownership of it and grant us the limited permission needed to host it, show it to the people it is intended for, and operate the service.</p>

            <h2>9. Privacy</h2>
            <p>Our handling of personal information is described in our <a href="/privacy-policy">Privacy Policy</a>, which forms part of these terms.</p>

            <h2>10. Suspension and closure</h2>
            <p>We may suspend or close an account that breaches these terms, that presents a risk to another user, or where we are required to do so by law. You may stop using the platform at any time and may ask us to close your account by contacting support. Closing an account does not remove records we are required to keep, such as financial and audit records.</p>

            <h2>11. Availability and disclaimers</h2>
            <p>We work to keep the platform available and functioning, but we do not promise uninterrupted or error-free service. Access may be interrupted by maintenance, third-party outages, or events outside our reasonable control. Live lessons depend on your own internet connection and equipment.</p>

            <h2>12. Limitation of liability</h2>
            <p>To the extent permitted by law, we are not liable for indirect or consequential loss, loss of profits, or loss of opportunity arising from your use of the platform. Nothing in these terms excludes liability that cannot lawfully be excluded, including liability for fraud, death or personal injury caused by negligence, or your rights as a consumer under the law that applies to you.</p>

            <h2>13. Changes to these terms</h2>
            <p>We may update these terms from time to time. The current version is always published on this page, and the date it last changed is shown below. Continuing to use the platform after an update means you accept the revised terms.</p>

            <h2>14. Governing law</h2>
            <p>These terms are governed by the laws of <strong>[REPLACE: your country, and state or city]</strong>, whose courts have jurisdiction over any dispute — without affecting any mandatory consumer rights available to you where you live.</p>

            <h2>15. Contact</h2>
            <p>Questions about these terms can be sent to <a href="mailto:{$email}">{$email}</a>, or through our <a href="/contact-us">contact page</a>.</p>
            <p><strong>[REPLACE: your full legal name]</strong>, sole proprietor, trading as {$app}<br>{$address}<br>Telephone: {$phone}<br><strong>[REPLACE: tax registration number — delete this line entirely if you are not registered for one]</strong></p>
        </div>
        HTML;
    }

    private function privacy(string $app, string $email, string $address): string
    {
        return <<<HTML
        <div class="policy-document">
            <p class="policy-meta">This policy explains what personal information {$app} collects, why we collect it, who we share it with, and the choices available to you.</p>

            <h2>1. Who is responsible for your information</h2>
            <p>{$app} is operated by <strong>[REPLACE: your full legal name]</strong>, an individual trading as {$app}, at {$address}. This person is responsible for the personal information described in this policy. If you have a question about how your information is handled, contact <a href="mailto:{$email}">{$email}</a>.</p>

            <h2>2. Information we collect</h2>
            <ul>
                <li><strong>Account information</strong> — name, email address, contact number, timezone, preferred language, and the academic context a student selects.</li>
                <li><strong>Instructor information</strong> — for instructor applicants, the professional and verification details required by the application process.</li>
                <li><strong>Learning information</strong> — bookings, lesson records, attendance, homework submissions, instructor feedback, learning goals and progress records.</li>
                <li><strong>Communications</strong> — messages sent through the platform, contact form submissions, and support requests.</li>
                <li><strong>Payment information</strong> — the record of a transaction, its status and amount. Card and banking credentials are handled by our payment providers, not stored by us.</li>
                <li><strong>Technical information</strong> — device, browser and log data generated when you use the platform, used for security, troubleshooting and abuse prevention.</li>
            </ul>

            <h2>3. Why we use it</h2>
            <p>We use personal information to operate accounts, deliver and schedule lessons, process payments and refunds, provide support, keep the platform safe and prevent misuse, meet our legal and financial record-keeping obligations, and send service messages about your bookings and account.</p>
            <p>Some platform features use automated processing to assist instructors and staff — for example, drafting or summarising material for a person to review. Where such a feature is enabled, the output is advisory and is reviewed by a person before it is acted on.</p>

            <h2>4. Who we share it with</h2>
            <p>We do not sell personal information. We share it only where necessary:</p>
            <ul>
                <li><strong>Between the people in a lesson</strong> — an instructor sees the information needed to teach the student they are booked with, and nothing more.</li>
                <li><strong>Service providers</strong> — payment providers, video meeting providers, email delivery providers and hosting providers, each acting on our instructions.</li>
                <li><strong>Legal and safety</strong> — where we are required by law, or where sharing is necessary to protect the rights or safety of a user.</li>
            </ul>

            <h2>5. Public and private information</h2>
            <p>Approved instructor profile information is public. Student information is not public. Verification documents and financial records are restricted to the staff whose role requires access to them.</p>

            <h2>6. Cookies</h2>
            <p>We use cookies and similar technologies to keep you signed in, remember preferences, keep the platform secure, and understand how it is used. You can control cookies through your browser, though disabling some of them will prevent parts of the platform from working.</p>

            <h2>7. How long we keep it</h2>
            <p>We keep personal information for as long as your account is active, and afterwards only for as long as we need it for the purpose it was collected for or to meet a legal, tax, accounting or dispute-resolution obligation. Records connected to payments and audit trails are retained for the periods those obligations require.</p>

            <h2>8. Security</h2>
            <p>We use access controls, permissions, encryption in transit and audit logging to protect personal information. No system is completely secure, so we also rely on you keeping your credentials confidential and telling us promptly about anything suspicious.</p>

            <h2>9. Children</h2>
            <p>Where a student is a minor, the account is created and supervised by a parent or legal guardian, who exercises the choices in this policy on the student's behalf.</p>

            <h2>10. International transfers</h2>
            <p>We support students and instructors in several countries, so information may be processed outside the country where you live. Where that happens, we take steps to ensure it remains protected to a standard consistent with this policy.</p>

            <h2>11. Your choices</h2>
            <p>Depending on where you live, you may have the right to access the personal information we hold about you, correct it, request its deletion, object to or restrict certain processing, or receive a copy of it. You can also opt out of marketing messages at any time; service messages relating to your bookings and account will still be sent. To exercise any of these, contact <a href="mailto:{$email}">{$email}</a>.</p>

            <h2>12. Changes to this policy</h2>
            <p>We may update this policy from time to time. The current version is always published on this page, and the date it last changed is shown below.</p>

            <h2>13. Contact</h2>
            <p>Questions or complaints about privacy can be sent to <a href="mailto:{$email}">{$email}</a> or through our <a href="/contact-us">contact page</a>.</p>
        </div>
        HTML;
    }

    private function cancellation(string $app, string $email): string
    {
        return <<<HTML
        <div class="policy-document">
            <p class="policy-meta">This policy explains how to cancel or reschedule a lesson on {$app}, when a cancellation qualifies for a refund, and how a refund is returned.</p>

            <h2>1. Cancelling a lesson</h2>
            <p>You can cancel a booked lesson from your account at any time before it starts. Each booking shows its own cancellation window — the point before the lesson start time up to which a cancellation qualifies for a refund. Please check that window on the booking itself, as it is set by the platform and may change over time.</p>

            <h2>2. When a cancellation qualifies for a refund</h2>
            <ul>
                <li><strong>Cancelled by the student within the window</strong> — a cancellation made before the cutoff shown on the booking qualifies for a refund.</li>
                <li><strong>Cancelled by the student after the window</strong> — a late cancellation does not qualify for a refund, because the time has been reserved and the instructor has held it.</li>
                <li><strong>Cancelled by the instructor or by the platform</strong> — always refunded in full, regardless of timing. You are never charged for a lesson you did not cause the cancellation of.</li>
                <li><strong>Instructor does not attend</strong> — refunded in full.</li>
            </ul>

            <h2>3. Rescheduling</h2>
            <p>Where you would rather move a lesson than cancel it, rescheduling is available within the limit shown on your booking. A rescheduled lesson keeps its original payment, so no refund arises.</p>

            <h2>4. How refunds are returned</h2>
            <p>An approved refund is returned by the same route the payment arrived: a payment made through a payment provider is refunded to the original payment method, and a lesson funded from wallet credit is returned to your wallet balance.</p>
            <p>Once we have issued a refund, the time it takes to appear depends on your bank or payment provider, and is outside our control.</p>

            <h2>5. Problems during a lesson</h2>
            <p>Technical problems, quality concerns and disputes are not handled as ordinary cancellations. Raise them with our support team, who will review what happened and decide the appropriate outcome, which may include a refund or a replacement lesson.</p>

            <h2>6. How to request a refund</h2>
            <p>Cancel the booking from your account where the cancellation window still allows it. For anything else — a late cancellation you believe deserves review, a lesson that did not go ahead, or a payment you do not recognise — contact <a href="mailto:{$email}">{$email}</a> or use our <a href="/contact-us">contact page</a>, quoting the booking reference.</p>

            <h2>7. Changes to this policy</h2>
            <p>We may update this policy from time to time. The version published on this page at the time you make a booking is the one that applies to it.</p>
        </div>
        HTML;
    }

    private function shipping(string $app, string $email): string
    {
        return <<<HTML
        <div class="policy-document">
            <p class="policy-meta">{$app} provides online tutoring services only. We do not sell, ship or deliver physical goods, so no shipping charges, delivery times or courier arrangements apply.</p>

            <h2>1. How our services are delivered</h2>
            <p>Everything we provide is delivered digitally through your account on this platform. A confirmed booking is delivered as a live, one-to-one online lesson held at the scheduled time, together with the supporting material connected to it — homework, instructor feedback, learning plans and progress records — which remain available in your account.</p>

            <h2>2. When access is provided</h2>
            <p>Access is provided as soon as a booking is confirmed. The lesson itself is delivered at the time you selected, and joining details are available from your account before it starts. There is no dispatch, no shipment, and nothing to wait for in the post.</p>

            <h2>3. What you need</h2>
            <p>Because lessons are delivered online, you need a device with a working internet connection, a browser, and a microphone. A camera is recommended. We do not provide hardware.</p>

            <h2>4. Exchanges</h2>
            <p>As no physical goods are supplied, there is nothing to exchange or return. Where a lesson does not go ahead as booked, the outcome is handled under our <a href="/cancellation-and-refund-policy">Cancellation and Refund Policy</a> rather than as an exchange.</p>
            <p>If you would prefer to continue with a different instructor, you are free to book one at any time; the platform does not tie you to a single instructor.</p>

            <h2>5. Problems with delivery</h2>
            <p>If you cannot access a lesson you have booked, contact <a href="mailto:{$email}">{$email}</a> or use our <a href="/contact-us">contact page</a> as soon as possible, quoting the booking reference, and we will look into it.</p>
        </div>
        HTML;
    }
}
