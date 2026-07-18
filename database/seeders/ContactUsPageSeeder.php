<?php

namespace Database\Seeders;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use Illuminate\Database\Seeder;

class ContactUsPageSeeder extends Seeder
{
    public function run(): void
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'contact-us'],
            [
                'title' => 'Contact Us',
                'excerpt' => 'Get the right help for student accounts, instructor applications, bookings, lessons, payments, or general questions.',
                'content' => $this->content(),
                'template' => 'default',
                'status' => PageStatus::Published,
                'visibility' => PageVisibility::Public,
                'published_at' => now(),
                'meta_title' => 'Contact SIRI Education — Get the Right Help',
                'meta_description' => 'Contact SIRI Education about accounts, instructor applications, bookings, lessons, payments, partnerships, or general platform questions.',
            ],
        );

        ContentBlock::query()->updateOrCreate(
            [
                'blockable_type' => $page->getMorphClass(),
                'blockable_id' => $page->getKey(),
                'name' => 'Contact Us — Primary Form',
            ],
            [
                'block_type' => BlockType::ContactForm,
                'content' => [
                    'title' => 'Send us a message',
                    'description' => 'Share enough context for the team to route your request. Please never include passwords, one-time codes, or full payment credentials.',
                    'fields' => [
                        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'placeholder' => 'Your full name', 'required' => true, 'options' => ''],
                        ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'placeholder' => 'you@example.com', 'required' => true, 'options' => ''],
                        ['name' => 'phone', 'label' => 'Mobile number', 'type' => 'phone', 'placeholder' => 'Include your country code', 'required' => false, 'options' => ''],
                        ['name' => 'request_type', 'label' => 'How can we help?', 'type' => 'select', 'placeholder' => '', 'required' => true, 'options' => 'Student account,Instructor application,Booking or lesson,Payment or wallet,Partnership or institution,Privacy or safety,General question'],
                        ['name' => 'reference', 'label' => 'Reference number', 'type' => 'text', 'placeholder' => 'Optional booking, payment, or application reference', 'required' => false, 'options' => ''],
                        ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'placeholder' => 'Tell us what happened, what you expected, and what help you need.', 'required' => true, 'options' => ''],
                    ],
                    'button_text' => 'Send message securely',
                    'success_message' => 'Thank you. Your message has been received and routed to the support team.',
                ],
                'settings' => [],
                'sort_order' => 10,
                'position' => 'after_content',
                'is_active' => true,
            ],
        );

        cache()->forget('page-render:'.$page->getKey());
    }

    private function content(): string
    {
        return <<<'HTML'
<div class="contact-page" data-contact-page data-cms-structured-page>
    <section class="contact-intro cms-section" data-contact-reveal>
        <div class="contact-intro-copy">
            <p class="contact-eyebrow">Let’s solve the right problem</p>
            <h2>Clear help starts with the right context</h2>
            <p class="contact-lead">Whether you are learning, teaching, booking a lesson, managing a payment, or exploring a partnership, we will help route your question to the right workflow.</p>
            <p>Choose a direct help option below or send the team a message. Include relevant references and dates, but never share a password, verification code, or complete card or bank details.</p>
            <div class="contact-actions">
                <a class="contact-button contact-button-primary" href="#contact-form">Send a message</a>
                <a class="contact-button contact-button-secondary" href="/faqs">Explore FAQs</a>
            </div>
        </div>
        <aside class="contact-response-card">
            <p class="contact-card-label">A better support request</p>
            <h3>Help us understand the situation without exposing sensitive information.</h3>
            <ul>
                <li><span>1</span><div><strong>Identify the workflow</strong><small>Account, application, booking, lesson, payment, wallet, or another area.</small></div></li>
                <li><span>2</span><div><strong>Add a safe reference</strong><small>Include the relevant ID, date, or instructor name when available.</small></div></li>
                <li><span>3</span><div><strong>Describe the outcome</strong><small>Tell us what happened, what you expected, and the help you need.</small></div></li>
            </ul>
        </aside>
    </section>

    <section class="contact-routes cms-section" data-contact-reveal>
        <div class="contact-section-heading">
            <p class="contact-eyebrow">Choose the right path</p>
            <h2>Faster help for common needs</h2>
            <p>Dedicated workflows collect the information needed for that request and reduce unnecessary back-and-forth.</p>
        </div>
        <div class="contact-route-grid">
            <a href="/support"><span class="contact-route-icon contact-icon-cyan">?</span><h3>Account or platform support</h3><p>Report a problem affecting your account, dashboard, booking, lesson, homework, payment, or wallet.</p><b>Open support form →</b></a>
            <a href="/callback"><span class="contact-route-icon contact-icon-violet">↗</span><h3>Request a callback</h3><p>Ask the team to contact you when a conversation would be more useful than a written reply.</p><b>Request a callback →</b></a>
            <a href="/feedback"><span class="contact-route-icon contact-icon-amber">✦</span><h3>Share product feedback</h3><p>Suggest an improvement or tell us about an experience that could work better for students or instructors.</p><b>Share feedback →</b></a>
            <a href="/inquiry"><span class="contact-route-icon contact-icon-emerald">◇</span><h3>General or partnership inquiry</h3><p>Contact us about institutions, partnerships, platform information, or another non-support question.</p><b>Start an inquiry →</b></a>
        </div>
    </section>

    <section class="contact-context cms-section" data-contact-reveal>
        <div class="contact-section-heading contact-section-heading-dark">
            <p class="contact-eyebrow">Before you send</p>
            <h2>Include what helps. Protect what matters.</h2>
            <p>Useful context lets the team investigate responsibly while privacy-aware communication protects your account and financial information.</p>
        </div>
        <div class="contact-context-grid">
            <article class="contact-context-good"><p class="contact-card-label">Helpful to include</p><ul><li>Account email or safe contact email</li><li>Booking, lesson, application, or transaction reference</li><li>Relevant date, timezone, subject, or instructor</li><li>A short description of what you already tried</li></ul></article>
            <article class="contact-context-private"><p class="contact-card-label">Never send</p><ul><li>Your password or one-time verification code</li><li>Complete card, bank, or wallet credentials</li><li>Private identity documents in a general message</li><li>Sensitive student information unrelated to the request</li></ul></article>
        </div>
    </section>

    <section class="contact-expectations cms-section" data-contact-reveal>
        <div><p class="contact-eyebrow">What happens next</p><h2>Your message enters an accountable support workflow</h2></div>
        <div class="contact-expectation-grid">
            <article><b>01</b><h3>Secure submission</h3><p>Validation, throttling, bot protection, and controlled fields help protect the public form.</p></article>
            <article><b>02</b><h3>Responsible routing</h3><p>Your request is delivered to the configured support recipient with its page and submission context.</p></article>
            <article><b>03</b><h3>Traceable receipt</h3><p>The submission event is recorded through the platform audit pipeline for operational accountability.</p></article>
        </div>
    </section>
</div>
HTML;
    }
}
