<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Editorial copy for the public country landing pages, keyed by ISO2.
 *
 * SEPARATED FROM THE SEEDER ON PURPOSE. CountryLandingPageSeeder owns
 * the "how" (which pages exist, which blocks they get, how re-running
 * behaves); this class owns the "what". The nine entries below are the
 * only country-specific prose in the codebase, so a copy change never
 * means touching seeding logic, and no country check leaks into the
 * application.
 *
 * TERMINOLOGY IS NEVER HARDCODED HERE. Every place a level name would
 * appear, the copy writes {term} / {terms} instead, and the seeder
 * substitutes whatever EducationSystem::levelTermSingular() currently
 * returns for that country. Renaming "Class" to "Standard" in the admin
 * and re-seeding updates the prose; no string in this file has to change.
 *
 * Available placeholders: {country}, {system}, {term}, {terms},
 * {levelRange} (e.g. "Class 6 to Class 12").
 *
 * CLAIMS DISCIPLINE. Nothing here promises curriculum or examination
 * coverage, instructor availability, results, pricing, accreditation, or
 * outcomes. Every factual statement maps to something the platform
 * actually does: one-to-one online lessons, a free demo booking type,
 * scheduling in the student's own timezone, homework with instructor
 * feedback, learning plans and goals, and a reviewed instructor
 * onboarding lifecycle.
 */
final class CountryLandingPageContent
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'IN' => [
                'excerpt' => 'One-to-one online tutoring for students studying {terms} 6 to 12 in India, scheduled around school and in your own timezone.',
                'meta_title' => 'Online Tutoring in India — One-to-One {terms} 6-12',
                'meta_description' => 'One-to-one online tutoring for students in India, {levelRange}. Book a free demo lesson and learn live with a reviewed instructor.',
                'image_alt' => 'Secondary-school student in India studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Learn one-to-one with an instructor who teaches to the {term} you are actually in — not a recorded course, and not a room of forty students.',
                'availability_heading' => 'Online tutoring for students across India',
                'availability_body' => "<p>Every lesson on SIRI Education is live, one-to-one, and online, which means a student in a metro and a student in a smaller town book from the same catalogue and the same instructors. There is no travel to a centre and no fixed batch timing to fit into.</p><p>Times are shown to you in your own timezone when you book, so an evening slot after school is an evening slot — not something you have to convert in your head. You choose the time from the instructor's published availability rather than being assigned one.</p>",
                'system_heading' => 'Built around the {term} a student is actually studying in',
                'system_body' => "<p>The platform models Indian schooling as {system}, where a student's level is described as a {term} — the same word used at school and at home. When a student sets up an account they pick their {term}, and that context follows them into every booking, learning plan and homework task.</p><p>That matters because tutoring is only useful when it starts at the right place. An instructor preparing for a session with a {term} 9 student sees that context before the lesson rather than spending the first session working out where the student is.</p>",
                'benefits_title' => 'Why families in India choose one-to-one online lessons',
                'benefits' => [
                    ['icon' => '👤', 'title' => "The whole hour is the student's", 'description' => 'In a one-to-one lesson there is nowhere to hide and no need to. Questions get asked and answered as they come up instead of waiting for a doubt-clearing session later.'],
                    ['icon' => '🕗', 'title' => 'Fits around the school day', 'description' => "You pick times from the instructor's published availability, so lessons sit around school hours, activities and travel rather than replacing them."],
                    ['icon' => '📝', 'title' => 'Homework does not end at the lesson', 'description' => 'Instructors can set homework, students submit it on the platform, and feedback comes back attached to the work rather than as a passing comment.'],
                    ['icon' => '🎯', 'title' => 'Goals you can actually see', 'description' => 'Learning goals, plans and progress milestones sit in the student dashboard, so "is this working?" has an answer you can look at.'],
                    ['icon' => '✅', 'title' => 'Instructors are reviewed before they teach', 'description' => 'Registering as an instructor does not grant teaching access. Every instructor goes through the platform review process before appearing publicly.'],
                    ['icon' => '💬', 'title' => 'Communication stays on the record', 'description' => 'Student-instructor messaging happens inside the platform, so context stays attached to the learning relationship instead of scattering across personal chats.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} do you support for students in India?', 'answer' => 'The platform currently covers {levelRange} under {system}. You select your {term} when you set up the student account, and it can be updated as the student moves up.'],
                    ['question' => 'Do I have to pay before trying an instructor?', 'answer' => 'No. The platform has a free demo lesson type, and you may take one free demo with each instructor. It is a normal one-to-one lesson, so you can judge the teaching before committing to anything paid.'],
                    ['question' => 'What time will lessons be?', 'answer' => "You choose. Available slots come from each instructor's published availability and are shown in your own timezone when you book, so there is no mental conversion and no assigned batch time."],
                    ['question' => 'Do you teach the exact syllabus my school follows?', 'answer' => "Tell the instructor what your school is working through and share it in the lesson. The platform organises tutoring by subject and {term} rather than reproducing any particular board's official syllabus, so bring your school material to the session."],
                    ['question' => 'Can one account cover more than one subject?', 'answer' => 'Yes. A student can book different instructors for different subjects, and each subject keeps its own homework, feedback and progress history under the same account.'],
                ],
            ],

            'US' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in the United States, scheduled in your own time zone.',
                'meta_title' => 'Online Tutoring in the United States — 1-to-1 {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring in the United States, {levelRange}. Free demo lesson, times shown in your own time zone.',
                'image_alt' => 'High-school student in the United States working at a laptop during a one-to-one online tutoring session',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — booked at times that work across your own time zone.',
                'availability_heading' => 'Online tutoring for students across the United States',
                'availability_body' => "<p>Lessons are live and one-to-one over video, so where a student lives stops deciding which instructors they can reach. The same catalogue is available whether you are on the coast, in the Midwest, or somewhere without a tutoring centre nearby.</p><p>The United States spans several time zones, and that is exactly the problem the platform's scheduling is built to avoid. Availability is displayed in the time zone on your own account, so a 5pm slot means 5pm where you are.</p>",
                'system_heading' => 'Organised by {term}, the way US schools are',
                'system_body' => "<p>The platform models American schooling as {system}, where a student's level is a {term}. Students set their {term} on their account, and instructors see that context before a lesson rather than working it out during one.</p><p>Tutoring that starts at the wrong level wastes the session. Carrying {term} context into bookings, learning plans and homework is how the first lesson starts somewhere useful.</p>",
                'benefits_title' => 'Why one-to-one online tutoring works for US students',
                'benefits' => [
                    ['icon' => '👤', 'title' => 'Undivided attention', 'description' => 'One student, one instructor, one hour. The pace follows the student rather than the middle of a classroom.'],
                    ['icon' => '🌎', 'title' => 'Time zones handled for you', 'description' => 'Slots are shown in your account time zone, so booking across the country never turns into arithmetic.'],
                    ['icon' => '📝', 'title' => 'Homework with real feedback', 'description' => 'Instructors assign work, students submit it on the platform, and feedback comes back attached to the submission.'],
                    ['icon' => '📈', 'title' => 'Progress you can point at', 'description' => 'Goals, learning plans and milestones live in the student dashboard instead of in someone\'s memory.'],
                    ['icon' => '✅', 'title' => 'Reviewed instructors', 'description' => 'Instructor sign-up does not grant teaching access — the platform review process comes first.'],
                    ['icon' => '🔁', 'title' => 'Change instructors without starting over', 'description' => 'Try a free demo with more than one instructor. Homework and progress stay on the student account either way.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} are covered?', 'answer' => '{levelRange} under {system}. You set the {term} on the student account and update it as the student moves up.'],
                    ['question' => 'Is there a free trial?', 'answer' => 'There is a free demo lesson, and you may take one with each instructor. It is a full one-to-one session, not a sales call.'],
                    ['question' => 'How do you handle different US time zones?', 'answer' => 'Your account carries your time zone, and every available slot is displayed in it. You never book against someone else\'s clock.'],
                    ['question' => 'Do you follow my state\'s standards?', 'answer' => 'The platform organises tutoring by subject and {term} rather than reproducing any particular state\'s standards. Bring your school\'s material to the lesson and the instructor will work from it.'],
                    ['question' => 'Can we book more than one subject?', 'answer' => 'Yes. Different subjects can use different instructors, and each keeps its own homework and feedback history on the same student account.'],
                ],
            ],

            'GB' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in the United Kingdom, booked around the school timetable.',
                'meta_title' => 'Online Tutoring in the United Kingdom — 1-to-1 {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring in the United Kingdom, {levelRange}. Free demo lesson, booked around the school timetable.',
                'image_alt' => 'Secondary-school student in the United Kingdom studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term}, booked around the school timetable rather than on top of it.',
                'availability_heading' => 'Online tutoring for students across the United Kingdom',
                'availability_body' => "<p>Every lesson is live, one-to-one and online, so the choice of instructor does not depend on what happens to be within travelling distance. A student in a city and a student in a rural area book from the same catalogue.</p><p>You pick lesson times from each instructor's published availability, which makes it practical to slot tutoring into the gaps that actually exist — after school, or at the weekend — instead of committing to a fixed weekly centre booking.</p>",
                'system_heading' => 'Organised by {term}, the way UK schools are',
                'system_body' => "<p>The platform models British schooling as {system}, where a student's level is described as a {term}. Students set their {term} when the account is created, and it travels with them into bookings, learning plans and homework.</p><p>An instructor preparing for a {term} 10 student knows that before the session begins. That is the difference between a first lesson spent teaching and a first lesson spent diagnosing.</p>",
                'benefits_title' => 'Why UK families choose one-to-one online lessons',
                'benefits' => [
                    ['icon' => '👤', 'title' => 'One student, one instructor', 'description' => 'The lesson moves at the student\'s pace, and questions get asked the moment they come up.'],
                    ['icon' => '🗓️', 'title' => 'Fits the school timetable', 'description' => "Book from the instructor's published availability rather than accepting a fixed slot decided for you."],
                    ['icon' => '📝', 'title' => 'Homework and feedback in one place', 'description' => 'Work set, submitted and returned on the platform, with the instructor\'s feedback attached to the submission.'],
                    ['icon' => '🎯', 'title' => 'Visible learning goals', 'description' => 'Goals, plans and progress milestones sit in the student dashboard where a parent can actually see them.'],
                    ['icon' => '✅', 'title' => 'Instructors reviewed first', 'description' => 'Signing up as an instructor does not grant teaching access; the platform review process comes first.'],
                    ['icon' => '💬', 'title' => 'Messaging that stays on the platform', 'description' => 'Student-instructor communication stays attached to the learning relationship rather than moving to personal accounts.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} do you cover?', 'answer' => '{levelRange} under {system}. The {term} is set on the student account and updated as the student moves up.'],
                    ['question' => 'Can we try before paying?', 'answer' => 'Yes — there is a free demo lesson, one per instructor. It runs as a normal one-to-one lesson so you can judge the teaching first.'],
                    ['question' => 'When can lessons be scheduled?', 'answer' => 'Whenever the instructor has published availability. Times appear in your own timezone, so after-school and weekend slots are straightforward to book.'],
                    ['question' => 'Do you teach a specific exam board syllabus?', 'answer' => 'The platform organises tutoring by subject and {term} rather than reproducing a particular exam board specification. Share the material your school is using and the instructor will work from it.'],
                    ['question' => 'Can a student work with more than one instructor?', 'answer' => 'Yes. Different subjects can have different instructors, and homework, feedback and progress all stay on the same student account.'],
                ],
            ],

            'AU' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in Australia, wherever you are and in your own timezone.',
                'meta_title' => 'Online Tutoring in Australia — One-to-One {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring anywhere in Australia, {levelRange}. Free demo lesson, times shown in your own timezone.',
                'image_alt' => 'Secondary-school student in Australia studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — from anywhere in Australia, in your own timezone.',
                'availability_heading' => 'Online tutoring anywhere in Australia',
                'availability_body' => "<p>Distance decides a lot about tutoring in Australia. Online one-to-one lessons remove that: a student in a regional town books from exactly the same instructor catalogue as a student in a capital city, with no travel at either end.</p><p>Australia also spans several time zones. Availability is shown in the timezone on your own account, so an east-coast student and a west-coast student both see honest local times rather than converting from someone else's clock.</p>",
                'system_heading' => 'Organised by {term}, the way Australian schools are',
                'system_body' => "<p>The platform models Australian schooling as {system}, where a student's level is described as a {term}. The {term} is set on the student account and carries into bookings, learning plans and homework.</p><p>That context is what lets an instructor start a first lesson in the right place instead of spending it working out where the student is up to.</p>",
                'benefits_title' => 'Why one-to-one online lessons suit Australian students',
                'benefits' => [
                    ['icon' => '📍', 'title' => 'Location stops being a filter', 'description' => 'Regional and remote students reach the same instructors as students in capital cities, with no travel involved.'],
                    ['icon' => '🕗', 'title' => 'Every timezone handled', 'description' => 'Slots display in your account timezone, so booking across the country does not become a maths problem.'],
                    ['icon' => '👤', 'title' => 'Attention that is genuinely undivided', 'description' => 'One student, one instructor — the pace follows the student rather than a class average.'],
                    ['icon' => '📝', 'title' => 'Homework that gets a response', 'description' => 'Work is set and submitted on the platform, and instructor feedback comes back attached to it.'],
                    ['icon' => '🎯', 'title' => 'Progress you can review', 'description' => 'Goals, learning plans and milestones live in the dashboard rather than in end-of-term guesswork.'],
                    ['icon' => '✅', 'title' => 'Instructors reviewed before teaching', 'description' => 'Instructor registration does not grant teaching access; the platform review process comes first.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} do you support?', 'answer' => '{levelRange} under {system}. The {term} is chosen on the student account and can be updated each year.'],
                    ['question' => 'Is there a free trial lesson?', 'answer' => 'Yes — a free demo lesson, one per instructor, run as a normal one-to-one session.'],
                    ['question' => 'I am in a regional area. Does that limit my options?', 'answer' => 'No. Every lesson is delivered online, so the instructor catalogue is the same regardless of where in Australia you are.'],
                    ['question' => 'How do you deal with the time difference between states?', 'answer' => 'Your account holds your timezone and all availability is displayed in it, so you always book against your own local clock.'],
                    ['question' => 'Do you follow the official curriculum for my state?', 'answer' => 'The platform organises tutoring by subject and {term} rather than reproducing any official state or national curriculum document. Bring your school material to the lesson.'],
                ],
            ],

            'CA' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in Canada, booked in your own time zone.',
                'meta_title' => 'Online Tutoring in Canada — One-to-One {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring across Canada, {levelRange}. Free demo lesson, times shown in your own time zone.',
                'image_alt' => 'Secondary-school student in Canada studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — from anywhere in Canada, in your own time zone.',
                'availability_heading' => 'Online tutoring across Canada',
                'availability_body' => '<p>Lessons are live, one-to-one and delivered online, so a student in a smaller community reaches the same instructors as a student in a large city. Nothing depends on what is within driving distance, which matters more in some months than others.</p><p>Canada covers a wide span of time zones. Availability is always displayed in the time zone recorded on your account, so a slot that reads 6pm is 6pm where the student actually is.</p>',
                'system_heading' => 'Organised by {term}, the way Canadian schools are',
                'system_body' => "<p>The platform models Canadian schooling as {system}, where a student's level is described as a {term}. Students set their {term} on the account, and it follows them into bookings, learning plans and homework tasks.</p><p>Instructors see that context before the session, so a first lesson can start with teaching rather than with diagnosis.</p>",
                'benefits_title' => 'Why Canadian students choose one-to-one online tutoring',
                'benefits' => [
                    ['icon' => '👤', 'title' => 'A full hour of attention', 'description' => 'One student and one instructor, with the pace set by the student rather than a classroom.'],
                    ['icon' => '🌎', 'title' => 'Time zones taken care of', 'description' => 'Slots are shown in your account time zone, coast to coast, so booking is never guesswork.'],
                    ['icon' => '🏠', 'title' => 'No travel in any season', 'description' => 'Lessons happen wherever the student already is, which keeps a routine going through the months when travel is least appealing.'],
                    ['icon' => '📝', 'title' => 'Homework with attached feedback', 'description' => 'Work is set and submitted on the platform, and the instructor\'s response stays with the submission.'],
                    ['icon' => '📈', 'title' => 'Progress that is visible', 'description' => 'Goals, learning plans and milestones sit in the student dashboard where families can see them.'],
                    ['icon' => '✅', 'title' => 'Reviewed instructors', 'description' => 'Instructor registration does not grant teaching access — platform review comes first.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} are supported?', 'answer' => '{levelRange} under {system}. The {term} is set on the student account and updated as the student advances.'],
                    ['question' => 'Can we try an instructor first?', 'answer' => 'Yes. A free demo lesson is available, one per instructor, and it runs as a normal one-to-one session.'],
                    ['question' => 'How do lessons work across Canadian time zones?', 'answer' => 'Availability is displayed in your own account time zone, so you always book against your local clock regardless of where the instructor is.'],
                    ['question' => 'Do you follow my province\'s curriculum?', 'answer' => 'Tutoring is organised by subject and {term} rather than by any particular provincial curriculum document. Share your school\'s material with the instructor and they will work from it.'],
                    ['question' => 'Are lessons available in French?', 'answer' => 'Instructors list the languages they teach in on their public profiles. Check an instructor\'s profile before booking to confirm what they offer.'],
                ],
            ],

            'AE' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in the United Arab Emirates, scheduled in your own timezone.',
                'meta_title' => 'Online Tutoring in the UAE — One-to-One {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring in the United Arab Emirates, {levelRange}. Free demo lesson, booked in your own timezone.',
                'image_alt' => 'Secondary-school student in the United Arab Emirates studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — booked around your schedule, in your own timezone.',
                'availability_heading' => 'Online tutoring for students in the United Arab Emirates',
                'availability_body' => "<p>Families in the UAE move, and schooling often continues across more than one country. Because every lesson here is online and one-to-one, a tutoring relationship does not have to restart when an address does — the instructor, the homework history and the progress record all stay on the same account.</p><p>Lesson times come from each instructor's published availability and are displayed in your own timezone, so booking an instructor in another country is still a decision about your evening, not about arithmetic.</p>",
                'system_heading' => 'Organised by {term}',
                'system_body' => "<p>The platform models schooling in the Emirates as {system}, where a student's level is described as a {term}. The {term} is set on the student account and travels into bookings, learning plans and homework.</p><p>Because the platform records the level rather than assuming a single national syllabus, a student can describe exactly what their own school is working through and have the instructor start from there.</p>",
                'benefits_title' => 'Why one-to-one online tutoring suits UAE students',
                'benefits' => [
                    ['icon' => '👤', 'title' => 'One-to-one, every lesson', 'description' => 'The whole session belongs to one student, at that student\'s pace.'],
                    ['icon' => '✈️', 'title' => 'Continuity through a move', 'description' => 'Lessons, homework and progress stay on the student account, so relocating does not mean starting over.'],
                    ['icon' => '🕗', 'title' => 'Times in your own timezone', 'description' => 'Availability is displayed in your account timezone, so booking an instructor abroad stays simple.'],
                    ['icon' => '📝', 'title' => 'Homework and feedback together', 'description' => 'Work is set and submitted on the platform, and instructor feedback stays attached to it.'],
                    ['icon' => '🎯', 'title' => 'Goals kept in view', 'description' => 'Learning goals, plans and milestones sit in the student dashboard rather than in memory.'],
                    ['icon' => '✅', 'title' => 'Instructors reviewed before teaching', 'description' => 'Registration does not grant teaching access; the platform review process comes first.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} do you cover?', 'answer' => '{levelRange} under {system}. You select the {term} on the student account and update it as the student moves up.'],
                    ['question' => 'My school follows an international curriculum. Does that work?', 'answer' => 'The platform organises tutoring by subject and {term} rather than by a specific curriculum. Tell the instructor what your school is working through and share the material in the lesson.'],
                    ['question' => 'Is there a free demo?', 'answer' => 'Yes — one free demo lesson per instructor, delivered as a normal one-to-one session.'],
                    ['question' => 'What if we relocate?', 'answer' => 'Lessons continue as long as there is an internet connection. Homework, feedback and progress remain on the same student account, and you can update the timezone on the account after a move.'],
                    ['question' => 'Can we book several subjects?', 'answer' => 'Yes. Each subject can have a different instructor while everything stays under one student account.'],
                ],
            ],

            'SG' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in Singapore, scheduled around a full week.',
                'meta_title' => 'Online Tutoring in Singapore — One-to-One {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring in Singapore, {levelRange}. Free demo lesson, no commute, times you choose.',
                'image_alt' => 'Secondary-school student in Singapore studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — no commute, and no fixed batch timing.',
                'availability_heading' => 'Online tutoring for students in Singapore',
                'availability_body' => "<p>Student weeks in Singapore are full. Removing the journey to and from a tuition centre gives back time that was never spent learning, and an online one-to-one lesson can sit in a gap that a travel-based session simply could not.</p><p>You choose the slot from the instructor's published availability rather than joining a fixed class timing, which makes it realistic to keep tutoring alongside school and everything else in the week.</p>",
                'system_heading' => 'Organised by {term}',
                'system_body' => "<p>The platform models Singaporean schooling as {system}, where a student's level is described as a {term}. The {term} is recorded on the student account and carried into bookings, learning plans and homework.</p><p>An instructor therefore knows the level before the session starts, which is what keeps a first lesson from being spent on discovery.</p>",
                'benefits_title' => 'Why Singapore students choose one-to-one online lessons',
                'benefits' => [
                    ['icon' => '⏱️', 'title' => 'No commute in the middle of the evening', 'description' => 'Time that went into travelling to a centre goes back into the week.'],
                    ['icon' => '👤', 'title' => 'The session is not shared', 'description' => 'One student, one instructor, and a pace set by the student rather than the group.'],
                    ['icon' => '🗓️', 'title' => 'Booked around a full schedule', 'description' => "Choose from the instructor's published availability instead of fitting into a fixed batch timing."],
                    ['icon' => '📝', 'title' => 'Homework that gets a response', 'description' => 'Assigned and submitted on the platform, with instructor feedback attached to the submission.'],
                    ['icon' => '📈', 'title' => 'Progress in one place', 'description' => 'Goals, learning plans and milestones live in the student dashboard.'],
                    ['icon' => '✅', 'title' => 'Reviewed instructors', 'description' => 'Instructor registration does not grant teaching access — review comes first.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} are covered?', 'answer' => '{levelRange} under {system}. The {term} is set on the student account.'],
                    ['question' => 'Can we try an instructor before paying?', 'answer' => 'Yes — one free demo lesson per instructor, run as a full one-to-one session.'],
                    ['question' => 'How is this different from a tuition centre?', 'answer' => 'Lessons are one-to-one rather than in a group, they happen online with no travel, and you choose the time from the instructor\'s published availability rather than joining a fixed timing.'],
                    ['question' => 'Do you teach the official school syllabus?', 'answer' => 'Tutoring is organised by subject and {term} rather than reproducing an official syllabus. Bring your school\'s material to the lesson and the instructor will work from it.'],
                    ['question' => 'Can a student take more than one subject?', 'answer' => 'Yes, with a different instructor per subject if that suits, all under one student account.'],
                ],
            ],

            'NZ' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in New Zealand, wherever you are.',
                'meta_title' => 'Online Tutoring in New Zealand — 1-to-1 {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring anywhere in New Zealand, {levelRange}. Free demo lesson, times shown in your own timezone.',
                'image_alt' => 'Secondary-school student in New Zealand studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — from anywhere in New Zealand.',
                'availability_heading' => 'Online tutoring anywhere in New Zealand',
                'availability_body' => "<p>Outside the main centres, the choice of local tutors can be thin — particularly in a specific subject at a specific level. Because lessons are delivered online, the instructor catalogue is the same whether a student is in a city or a small town.</p><p>New Zealand also sits a long way ahead of most of the world's clocks, so times are always displayed in the timezone on your own account. You book what your evening actually is, not what it would be somewhere else.</p>",
                'system_heading' => 'Organised by {term}, the way New Zealand schools are',
                'system_body' => "<p>The platform models New Zealand schooling as {system}, where a student's level is described as a {term}. Students set their {term} on the account, and that context carries into bookings, learning plans and homework.</p><p>Instructors see it before the lesson, so the first session can start with teaching rather than working out where the student is up to.</p>",
                'benefits_title' => 'Why one-to-one online tutoring works in New Zealand',
                'benefits' => [
                    ['icon' => '📍', 'title' => 'Subject choice without the drive', 'description' => 'A student outside a main centre reaches the same instructors as one inside it.'],
                    ['icon' => '🕗', 'title' => 'Local times, always', 'description' => 'Availability is displayed in your account timezone, which matters when booking instructors far away.'],
                    ['icon' => '👤', 'title' => 'One-to-one attention', 'description' => 'The whole lesson belongs to one student and moves at their pace.'],
                    ['icon' => '📝', 'title' => 'Homework with feedback attached', 'description' => 'Set and submitted on the platform, with the instructor\'s response kept alongside the work.'],
                    ['icon' => '🎯', 'title' => 'Goals you can review', 'description' => 'Learning goals, plans and milestones sit in the student dashboard.'],
                    ['icon' => '✅', 'title' => 'Instructors reviewed first', 'description' => 'Registering as an instructor does not grant teaching access; review comes first.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} do you support?', 'answer' => '{levelRange} under {system}. The {term} is set on the student account and updated as the student moves up.'],
                    ['question' => 'Is there a free trial?', 'answer' => 'Yes — a free demo lesson, one per instructor, delivered as a normal one-to-one session.'],
                    ['question' => 'We are not near a main centre. Does that matter?', 'answer' => 'No. Lessons are online, so the same instructors are available regardless of where in New Zealand you are.'],
                    ['question' => 'How does the time difference work with overseas instructors?', 'answer' => 'Every available slot is displayed in your own timezone, so you always book against your local clock.'],
                    ['question' => 'Do you follow the official curriculum?', 'answer' => 'Tutoring is organised by subject and {term} rather than reproducing an official curriculum document. Share what your school is working through and the instructor will work from it.'],
                ],
            ],

            'SA' => [
                'excerpt' => 'One-to-one online tutoring for students in {terms} 6 to 12 in Saudi Arabia, scheduled in your own timezone.',
                'meta_title' => 'Online Tutoring in Saudi Arabia — 1-to-1 {terms} 6-12',
                'meta_description' => 'Live one-to-one online tutoring in Saudi Arabia, {levelRange}. Free demo lesson, booked in your own timezone.',
                'image_alt' => 'Secondary-school student in Saudi Arabia studying at a laptop during an online one-to-one tutoring lesson',
                'hero_subtitle' => 'Live one-to-one lessons with an instructor who teaches to your {term} — booked at times you choose, in your own timezone.',
                'availability_heading' => 'Online tutoring for students in Saudi Arabia',
                'availability_body' => '<p>Lessons are live, one-to-one and delivered online, which means the instructor a student works with is not limited to whoever is available nearby. The same catalogue is open to students in Riyadh, Jeddah, Dammam and everywhere between.</p><p>Times are shown in the timezone recorded on your own account, so scheduling with an instructor in another country stays a straightforward decision about your own day.</p>',
                'system_heading' => 'Organised by {term}',
                'system_body' => "<p>The platform models schooling in the Kingdom as {system}, where a student's level is described as a {term}. The {term} is set on the student account and carried into bookings, learning plans and homework.</p><p>Because the level is recorded explicitly, an instructor knows where to begin before the first session rather than after it.</p>",
                'benefits_title' => 'Why one-to-one online tutoring suits students in Saudi Arabia',
                'benefits' => [
                    ['icon' => '👤', 'title' => 'Individual attention', 'description' => 'One student and one instructor for the whole session, at the student\'s own pace.'],
                    ['icon' => '🏠', 'title' => 'Learning from home', 'description' => 'No travel to a centre, and lessons happen wherever the student already studies.'],
                    ['icon' => '🕗', 'title' => 'Times in your own timezone', 'description' => 'Availability is displayed in your account timezone, so international instructors are still easy to schedule.'],
                    ['icon' => '📝', 'title' => 'Homework with instructor feedback', 'description' => 'Work is set and submitted on the platform, and feedback stays attached to the submission.'],
                    ['icon' => '📈', 'title' => 'Progress kept in view', 'description' => 'Goals, learning plans and milestones sit in the student dashboard.'],
                    ['icon' => '✅', 'title' => 'Instructors reviewed before teaching', 'description' => 'Instructor registration does not grant teaching access; platform review comes first.'],
                ],
                'faqs' => [
                    ['question' => 'Which {terms} do you cover?', 'answer' => '{levelRange} under {system}. The {term} is selected on the student account.'],
                    ['question' => 'Is there a free demo lesson?', 'answer' => 'Yes — one free demo per instructor, delivered as a normal one-to-one session.'],
                    ['question' => 'Which language are lessons taught in?', 'answer' => 'Instructors list the languages they teach in on their public profiles. Check the profile before booking to confirm.'],
                    ['question' => 'Do you follow the national curriculum?', 'answer' => 'Tutoring is organised by subject and {term} rather than reproducing the official national curriculum. Bring your school material to the lesson and the instructor will work from it.'],
                    ['question' => 'Can one account cover several subjects?', 'answer' => 'Yes. A student can work with different instructors for different subjects while everything stays on one account.'],
                ],
            ],
        ];
    }
}
