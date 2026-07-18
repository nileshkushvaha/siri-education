<?php

namespace Database\Seeders;

use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use Illuminate\Database\Seeder;

class AboutUsPageSeeder extends Seeder
{
    public function run(): void
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'about-us'],
            [
                'title' => 'About Us',
                'excerpt' => 'A student-first education platform connecting trusted instructors, structured learning, and accountable operations.',
                'content' => $this->content(),
                'template' => 'default',
                'status' => PageStatus::Published,
                'visibility' => PageVisibility::Public,
                'published_at' => now(),
                'meta_title' => 'About SIRI Education — Learn, Teach, and Grow',
                'meta_description' => 'See how SIRI Education connects students and verified instructors through structured lessons, progress tracking, secure payments, and trusted operations.',
            ],
        );

        cache()->forget('page-render:'.$page->getKey());
    }

    private function content(): string
    {
        return <<<'HTML'
<div class="about-page" data-about-page data-cms-structured-page>
    <section class="about-intro cms-section" data-about-reveal>
        <div class="about-intro-copy">
            <p class="about-eyebrow">Why we exist</p>
            <h2>Learning works better when every step stays connected</h2>
            <p class="about-lead">SIRI Education is designed around a simple belief: students make stronger progress when trusted instructors, flexible scheduling, live lessons, homework, feedback, payments, and learning plans work as one coherent experience.</p>
            <p>We are building more than an instructor directory. Our platform supports the full relationship—from discovery and booking to lesson delivery, progress review, financial records, and long-term learning continuity.</p>
            <div class="about-actions">
                <a class="about-button about-button-primary" href="/instructors">Explore instructors</a>
                <a class="about-button about-button-secondary" href="/student-registration">Create a student account</a>
            </div>
        </div>
        <div class="about-purpose-card">
            <p class="about-card-label">Our purpose</p>
            <h3>Make high-quality, personalised education easier to access and easier to manage.</h3>
            <ul>
                <li><span>01</span><div><strong>Clear discovery</strong><small>Compare approved public profiles using relevant teaching information.</small></div></li>
                <li><span>02</span><div><strong>Structured learning</strong><small>Keep lessons, homework, goals, feedback, and plans connected.</small></div></li>
                <li><span>03</span><div><strong>Accountable operations</strong><small>Protect identity, privacy, access, and financial traceability.</small></div></li>
            </ul>
        </div>
    </section>

    <section class="about-dark cms-section" data-about-reveal>
        <div class="about-section-heading">
            <p class="about-eyebrow">One education workspace</p>
            <h2>Built for the complete learning relationship</h2>
            <p>Every major workflow in the SRS contributes to a single student and instructor experience instead of becoming another disconnected tool.</p>
        </div>
        <div class="about-dark-grid">
            <article><span class="about-accent about-accent-cyan"></span><h3>Discover with confidence</h3><p>Search by subject and learning context, then review only approved, publicly visible instructor information.</p></article>
            <article><span class="about-accent about-accent-violet"></span><h3>Book with clarity</h3><p>Choose eligible lesson types and available times in the student's timezone, with a complete review before confirmation.</p></article>
            <article><span class="about-accent about-accent-amber"></span><h3>Learn with continuity</h3><p>Connect live lessons to homework, instructor feedback, goals, milestones, and personal learning plans.</p></article>
            <article><span class="about-accent about-accent-emerald"></span><h3>Operate with trust</h3><p>Keep verification, permission controls, notifications, payments, wallet records, and audit trails accountable.</p></article>
        </div>
    </section>

    <section class="about-lifecycle cms-section" data-about-reveal>
        <div class="about-section-heading about-section-heading-dark">
            <p class="about-eyebrow">Student-first by design</p>
            <h2>A clearer path from the first goal to visible progress</h2>
            <p>The platform supports the decisions and follow-through that happen before, during, and after every lesson.</p>
        </div>
        <div class="about-lifecycle-grid">
            <article class="about-tone-indigo"><b>01</b><h3>Set the direction</h3><p>Create an account, define learning needs, and establish the right subject and academic context.</p></article>
            <article class="about-tone-cyan"><b>02</b><h3>Find the right match</h3><p>Compare instructor expertise, teaching background, language, timezone, and visible availability.</p></article>
            <article class="about-tone-violet"><b>03</b><h3>Plan the lesson</h3><p>Select a valid session type, schedule, and instructor through a guided booking workflow.</p></article>
            <article class="about-tone-amber"><b>04</b><h3>Stay prepared</h3><p>Use reminders, meeting readiness, attendance, and lesson information to reduce avoidable friction.</p></article>
            <article class="about-tone-rose"><b>05</b><h3>Continue the work</h3><p>Keep assignments, submissions, due dates, feedback, and lesson outcomes organised.</p></article>
            <article class="about-tone-emerald"><b>06</b><h3>See meaningful progress</h3><p>Connect goals, learning plans, instructor guidance, reviews, and progress milestones over time.</p></article>
        </div>
    </section>

    <section class="about-audiences cms-section" data-about-reveal>
        <article class="about-audience-student">
            <p class="about-card-label">For students</p>
            <h2>Learning that feels organised, personal, and transparent</h2>
            <p>Students receive a clear workspace for instructors, bookings, lessons, homework, progress, notifications, wallet activity, and support—without exposing private information publicly.</p>
            <a href="/student-registration">Start your learning journey →</a>
        </article>
        <article class="about-audience-instructor">
            <p class="about-card-label">For instructors</p>
            <h2>Teaching operations that support quality and sustainable growth</h2>
            <p>Instructors move through a controlled application and verification lifecycle before managing availability, lessons, students, homework, feedback, earnings, settlements, and performance insights.</p>
            <a href="/become-instructor">Learn about teaching with us →</a>
        </article>
    </section>

    <section class="about-trust cms-section" data-about-reveal>
        <div class="about-section-heading">
            <p class="about-eyebrow">Trust is operational</p>
            <h2>Safety is more than a badge</h2>
            <p>Our SRS treats trust as a system of controls, records, and accountable decisions across identity, learning, and money.</p>
        </div>
        <div class="about-trust-grid">
            <article><strong>Verified teaching access</strong><p>Instructor registration does not automatically grant teaching privileges; approval follows the configured review lifecycle.</p></article>
            <article><strong>Privacy-aware visibility</strong><p>Public profiles are separated from private student records and sensitive verification documents.</p></article>
            <article><strong>Traceable financial activity</strong><p>Booking payments, wallet entries, refunds, earnings, settlements, and withdrawals retain durable records.</p></article>
            <article><strong>Auditable platform actions</strong><p>Important administrative and lifecycle events flow through controlled permissions and the audit trail.</p></article>
        </div>
    </section>

    <section class="about-closing cms-section" data-about-reveal>
        <p class="about-eyebrow">Learn · Teach · Grow</p>
        <h2>Education becomes more powerful when the whole journey works together</h2>
        <p>Explore approved instructors, start a student account, or learn how to bring your expertise to the platform.</p>
        <div class="about-actions about-actions-center">
            <a class="about-button about-button-light" href="/instructors">Find an instructor</a>
            <a class="about-button about-button-glass" href="/become-instructor">Become an instructor</a>
        </div>
    </section>
</div>
HTML;
    }
}
