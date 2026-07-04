@extends('layouts.frontend')

@section('title', config('app.name') . ' — Find Your Perfect 1-on-1 Tutor')

@section('content')

@php
// $appName, $logo, $supportEmail, $supportPhone, $address,
// $footerText, $footerCopyright, $recentPosts
// are passed from PageController::home()
$appName ??= config('app.name', 'App');
$logo ??= null;
$supportEmail ??= null;
$supportPhone ??= null;
$address ??= null;
$footerText ??= null;
$footerCopyright ??= null;
$recentPosts ??= collect();

$countries = ['🇮🇳 India', '🇺🇸 United States', '🇬🇧 United Kingdom', '🇨🇦 Canada', '🇦🇺 Australia', '🌐 All Regions'];

$subjects = ['All', 'Computer Science', 'Mathematics', 'English', 'Science', 'Languages', 'Competitive Exams'];

$features = [
['icon'=>'🎯', 'title'=>'K-12 All Subjects', 'desc'=>'Math, Science, English online tutoring for every grade'],
['icon'=>'📚', 'title'=>'Personalised Plans', 'desc'=>'Custom learning paths built around your goals & pace'],
['icon'=>'✍️', 'title'=>'English Excellence', 'desc'=>'Reading, writing, grammar and vocabulary coaching'],
['icon'=>'🔬', 'title'=>'Science Mastery', 'desc'=>'Physics, Chemistry, Biology at all academic levels'],
['icon'=>'🏆', 'title'=>'Exam Preparation', 'desc'=>'JEE, NEET, SAT, GRE, UPSC — expert coaching'],
['icon'=>'💰', 'title'=>'Affordable Pricing', 'desc'=>'Transparent plans with a free 30-minute demo session'],
];

$stats = [
['value'=>10000, 'suffix'=>'+', 'label'=>'Active Students', 'icon'=>'👨‍🎓', 'color'=>'from-indigo-500 to-violet-500'],
['value'=>200, 'suffix'=>'+', 'label'=>'Expert Tutors', 'icon'=>'👩‍🏫', 'color'=>'from-blue-500 to-indigo-500'],
['value'=>500, 'suffix'=>'+', 'label'=>'Courses', 'icon'=>'📖', 'color'=>'from-violet-500 to-purple-500'],
['value'=>40000, 'suffix'=>'+', 'label'=>'Hours Taught', 'icon'=>'⏱️', 'color'=>'from-amber-500 to-orange-500'],
];

$whys = [
['icon'=>'🚀', 'title'=>'Creating Future Leaders', 'desc'=>'We believe every student can achieve greatness. Our world-class tutors equip learners with skills for the challenges of the 21st century.', 'color'=>'from-indigo-500 to-violet-600'],
['icon'=>'🎓', 'title'=>'Student-Centred Learning', 'desc'=>'Every session is tailored to the individual learner. We adapt to your style, speed, and goals — not the other way around.', 'color'=>'from-blue-500 to-indigo-600'],
['icon'=>'✨', 'title'=>'Personalised Experience', 'desc'=>'Our innovative tutoring enables creativity and sharpens skills with learning tailored to each unique learner.', 'color'=>'from-violet-500 to-pink-600'],
];

$steps = [
['num'=>'01', 'icon'=>'🌍', 'title'=>'Choose Your Curriculum', 'desc'=>'Select your country and curriculum to access courses designed specifically for your board, syllabus, or competitive exam entrance goals.', 'side'=>'right'],
['num'=>'02', 'icon'=>'🔍', 'title'=>'Explore Courses Made For You', 'desc'=>'Browse expert-built courses aligned with your curriculum, academic schedule, and personal requirements.', 'side'=>'left'],
['num'=>'03', 'icon'=>'📅', 'title'=>'Book a Free Demo Class', 'desc'=>'Experience a free 30-minute demo to connect with your tutor, understand the teaching style, and ensure it fits your learning needs.', 'side'=>'right'],
['num'=>'04', 'icon'=>'📈', 'title'=>'Enroll & Start Learning', 'desc'=>'Join your chosen course and start learning with engaging lessons, smart assessments, and ongoing academic support.', 'side'=>'left'],
];

$courses = [
['emoji'=>'💻','color'=>'from-blue-500 to-indigo-600','category'=>'Computer Science','title'=>'Data Structures & Algorithms','tutor'=>'Dr. Rahul Verma','students'=>'2.4K','rating'=>'4.9','reviews'=>'128','price'=>'₹1,499','duration'=>'40 hrs','level'=>'Intermediate','tag'=>'Bestseller'],
['emoji'=>'📐','color'=>'from-violet-500 to-purple-600','category'=>'Mathematics','title'=>'Calculus & Linear Algebra Mastery','tutor'=>'Prof. Priya Sharma','students'=>'1.8K','rating'=>'4.8','reviews'=>'96','price'=>'₹1,299','duration'=>'35 hrs','level'=>'Advanced','tag'=>'Top Rated'],
['emoji'=>'🧪','color'=>'from-emerald-500 to-teal-600','category'=>'Science','title'=>'Physics for JEE & NEET 2025','tutor'=>'Dr. Amit Patel','students'=>'3.1K','rating'=>'4.9','reviews'=>'205','price'=>'₹1,799','duration'=>'50 hrs','level'=>'Advanced','tag'=>'Hot 🔥'],
];

$tutors = [
['name'=>'Dr. Priya Sharma', 'subject'=>'Mathematics & Statistics', 'exp'=>'8 yrs', 'rating'=>'4.9', 'students'=>'1.2K', 'emoji'=>'👩', 'color'=>'from-violet-500 to-purple-600'],
['name'=>'Rahul Verma', 'subject'=>'Computer Science & AI/ML', 'exp'=>'6 yrs', 'rating'=>'4.8', 'students'=>'980', 'emoji'=>'👨‍💻','color'=>'from-blue-500 to-indigo-600'],
['name'=>'Dr. Anjali Singh', 'subject'=>'Physics & Chemistry', 'exp'=>'10 yrs', 'rating'=>'5.0', 'students'=>'2.1K', 'emoji'=>'👩‍🔬','color'=>'from-emerald-500 to-teal-600'],
['name'=>'Arjun Mehta', 'subject'=>'English & Communication', 'exp'=>'5 yrs', 'rating'=>'4.9', 'students'=>'756', 'emoji'=>'📝', 'color'=>'from-amber-500 to-orange-600'],
];

$testimonials = [
['name'=>'Elena Rodriguez', 'role'=>'Grade 10 Student', 'emoji'=>'👩', 'rating'=>5, 'text'=>'My grades improved dramatically in just 3 months! The personalised approach made all the difference. My tutor explains concepts in a way that just clicks. Truly transformative experience.'],
['name'=>'Michael O\'Connor','role'=>'Engineering Aspirant', 'emoji'=>'👨', 'rating'=>5, 'text'=>'Excellent tutors and a structured learning plan. The regular assessments helped me stay on track and I cracked my entrance exam on the very first attempt!'],
['name'=>'Priya Gupta', 'role'=>'Working Professional', 'emoji'=>'👩‍💼', 'rating'=>5, 'text'=>'Flexible scheduling is a game-changer for professionals like me. I learned Python from scratch and earned a promotion within 6 months. The quality here is outstanding.'],
];

$faqs = [
['q'=>'How does online tutoring work?', 'a'=>'Our platform connects you with expert tutors via live interactive video sessions. After selecting your course, you schedule sessions at your convenience and learn with screen sharing, a digital whiteboard, and real-time problem solving.'],
['q'=>'What subjects do you offer tutoring for?', 'a'=>'We cover Mathematics, Science (Physics, Chemistry, Biology), Computer Science, English, Languages, and competitive exam prep (JEE, NEET, SAT, GRE, UPSC). New courses are added every month.'],
['q'=>'How do I schedule a tutoring session?', 'a'=>'Browse tutors, pick one that matches your needs, view their availability calendar, and book a session with a few clicks. Sessions can be booked up to 24 hours in advance.'],
['q'=>'Can tutoring sessions be customised to my needs?', 'a'=>'Absolutely. Every tutor creates a personalised learning plan based on your goals, current level, and learning style. Sessions are adaptive and designed around your specific requirements.'],
['q'=>'How are tutors selected and verified?', 'a'=>'All tutors undergo a rigorous vetting process — credential verification, background checks, demo sessions, and ongoing performance reviews. Only the top 5% of applicants are accepted.'],
['q'=>'What technology do I need for online tutoring?', 'a'=>'You need a stable internet connection and a device with a camera and microphone. No special software is required — everything runs in your modern web browser.'],
];
@endphp

@include('partials.home-banner')

{{-- ============================================================
     MAIN WRAPPER — Alpine.js root
     ============================================================ --}}
<div
    class="bg-surface-dark"
    x-data="{
        activeFaq: null,
    }"
    x-init="
        /* ---- Counter animation triggered by Intersection Observer ---- */
        const animateCounters = () => {
            document.querySelectorAll('[data-counter]').forEach(el => {
                if (el.dataset.done) return;
                el.dataset.done = '1';
                const target   = parseInt(el.dataset.counter);
                const duration = 2200;
                const start    = Date.now();
                const tick = () => {
                    const elapsed  = Date.now() - start;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased    = 1 - Math.pow(1 - progress, 3);
                    el.textContent = Math.floor(target * eased).toLocaleString();
                    if (progress < 1) requestAnimationFrame(tick);
                    else el.textContent = target.toLocaleString();
                };
                requestAnimationFrame(tick);
            });
        };
        const statsEl = document.getElementById('stats-section');
        if (statsEl) {
            new IntersectionObserver(entries => {
                entries.forEach(e => { if (e.isIntersecting) animateCounters(); });
            }, { threshold: 0.3 }).observe(statsEl);
        }
    ">

{{-- ============================================================
     ALL-IN-ONE FEATURES SECTION
     ============================================================ --}}
<section class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4">
        <div class="text-center mb-16">
            <span class="inline-block bg-indigo-50 text-indigo-600 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">All-in-One Platform</span>
            <h2 class="text-4xl font-bold text-slate-900">Online Tutoring for School Subjects<br class="hidden sm:block"> & Competitive Exams</h2>
            <div class="section-accent"></div>
            <p class="mt-4 text-slate-400 max-w-xl mx-auto">From K-12 academics to entrance exam coaching — one platform for every learning need.</p>
        </div>

        <div class="grid lg:grid-cols-2 gap-16 items-center">
            {{-- Features Grid --}}
            <div class="grid sm:grid-cols-2 gap-4">
                @foreach($features as $f)
                <div class="flex items-start gap-3 p-4 rounded-2xl bg-slate-50 hover:bg-indigo-50/60 transition-colors border border-transparent hover:border-indigo-100 group">
                    <div class="w-10 h-10 rounded-xl bg-white shadow-sm border border-slate-100 flex items-center justify-center text-xl flex-shrink-0 group-hover:scale-110 transition-transform">{{ $f['icon'] }}</div>
                    <div>
                        <h3 class="font-semibold text-slate-900 text-sm mb-0.5">{{ $f['title'] }}</h3>
                        <p class="text-slate-400 text-xs leading-relaxed">{{ $f['desc'] }}</p>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Visual Dashboard Card --}}
            <div class="relative">
                <div class="bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 rounded-3xl p-8 text-white shadow-2xl shadow-indigo-500/30">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold">Learning Dashboard</h3>
                        <div class="flex items-center gap-1.5 bg-white/15 rounded-full px-3 py-1 text-xs">
                            <div class="badge-dot"></div>
                            <span>Live</span>
                        </div>
                    </div>

                    {{-- Progress Items --}}
                    @foreach([['Mathematics','78%','w-[78%]'],['Physics','55%','w-[55%]'],['English','91%','w-[91%]']] as [$subj,$pct,$w])
                    <div class="mb-4">
                        <div class="flex justify-between text-sm mb-1.5">
                            <span class="text-white/80">{{ $subj }}</span>
                            <span class="font-bold">{{ $pct }}</span>
                        </div>
                        <div class="h-2 bg-white/20 rounded-full overflow-hidden">
                            <div class="h-full {{ $w }} bg-white/70 rounded-full"></div>
                        </div>
                    </div>
                    @endforeach

                    <div class="mt-6 grid grid-cols-3 gap-3 pt-4 border-t border-white/20">
                        <div class="text-center">
                            <div class="text-2xl font-bold">24</div>
                            <div class="text-white/60 text-xs mt-0.5">Sessions</div>
                        </div>
                        <div class="text-center border-x border-white/20">
                            <div class="text-2xl font-bold">48h</div>
                            <div class="text-white/60 text-xs mt-0.5">Learned</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold">A+</div>
                            <div class="text-white/60 text-xs mt-0.5">Grade</div>
                        </div>
                    </div>
                </div>

                {{-- Floating badge --}}
                <div class="absolute -bottom-5 -right-5 glass-light rounded-2xl px-5 py-4 shadow-xl">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">📈</span>
                        <div>
                            <div class="text-xs text-slate-400">Improvement</div>
                            <div class="font-bold text-slate-900 text-sm text-grad">+42% this month</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     ANIMATED STATS
     ============================================================ --}}
<section id="stats-section" class="py-20 bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-950 relative overflow-hidden">
    <div class="bg-orb w-[500px] h-[500px] top-[-200px] left-[10%]" style="background:radial-gradient(circle,#4F46E5,transparent)"></div>
    <div class="bg-orb w-[400px] h-[400px] bottom-[-150px] right-[10%]" style="background:radial-gradient(circle,#7C3AED,transparent);animation-delay:4s;"></div>

    <div class="max-w-7xl mx-auto px-4 relative z-10">
        <div class="text-center mb-14">
            <h2 class="text-3xl font-bold text-white">Why Thousands Choose <span class="text-grad">Us</span></h2>
            <div class="section-accent"></div>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
            @foreach($stats as $stat)
            <div class="glass rounded-3xl p-8 text-center card-lift group">
                <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br {{ $stat['color'] }} flex items-center justify-center text-2xl shadow-lg group-hover:scale-110 transition-transform">{{ $stat['icon'] }}</div>
                <div class="text-4xl font-bold text-white mb-1">
                    <span data-counter="{{ $stat['value'] }}">0</span>{{ $stat['suffix'] }}
                </div>
                <div class="text-gray-400 text-sm">{{ $stat['label'] }}</div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================
     WHY CHOOSE US
     ============================================================ --}}
<section class="py-24 bg-slate-50">
    <div class="max-w-7xl mx-auto px-4">
        <div class="text-center mb-16">
            <span class="inline-block bg-indigo-50 text-indigo-600 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">Why Us</span>
            <h2 class="text-4xl font-bold text-slate-900">Why Choose <span class="text-grad">Our Platform</span></h2>
            <div class="section-accent"></div>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
            @foreach($whys as $i => $why)
            <div class="card-lift bg-white rounded-3xl p-8 shadow-sm border border-slate-100 group">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br {{ $why['color'] }} flex items-center justify-center text-3xl mb-6 shadow-lg group-hover:scale-110 transition-transform">{{ $why['icon'] }}</div>
                <h3 class="text-xl font-bold text-slate-900 mb-3">{{ $why['title'] }}</h3>
                <p class="text-slate-400 leading-relaxed text-sm">{{ $why['desc'] }}</p>
                <div class="mt-6 flex items-center gap-2 text-indigo-600 text-sm font-semibold group-hover:gap-3 transition-all">
                    Learn More <span>→</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================
     HOW IT WORKS
     ============================================================ --}}
<section id="how-it-works" class="py-24 bg-white">
    <div class="max-w-5xl mx-auto px-4">
        <div class="text-center mb-16">
            <span class="inline-block bg-violet-50 text-violet-600 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">Process</span>
            <h2 class="text-4xl font-bold text-slate-900">How It <span class="text-grad">Works</span></h2>
            <div class="section-accent"></div>
            <p class="mt-4 text-slate-400">Get started in 4 simple steps</p>
        </div>

        <div class="relative">
            {{-- Center connecting line (desktop) --}}
            <div class="hidden lg:block absolute left-1/2 top-6 bottom-6 w-px" style="background:linear-gradient(to bottom,#6366F1,rgba(99,102,241,0))"></div>

            <div class="space-y-12">
                @foreach($steps as $step)
                <div class="grid lg:grid-cols-2 gap-8 items-center {{ $step['side'] === 'left' ? '' : '' }}">
                    @if($step['side'] === 'right')
                    {{-- Spacer left --}}
                    <div class="hidden lg:flex justify-end pr-10">
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-2xl shadow-xl shadow-indigo-500/30 z-10 relative">{{ $step['icon'] }}</div>
                    </div>
                    {{-- Content right --}}
                    <div class="glass-light rounded-3xl p-6 shadow-sm border border-slate-100 card-lift">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="text-xs font-bold text-indigo-400 bg-indigo-50 px-2.5 py-1 rounded-full">Step {{ $step['num'] }}</span>
                            <span class="text-xl lg:hidden">{{ $step['icon'] }}</span>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2">{{ $step['title'] }}</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">{{ $step['desc'] }}</p>
                    </div>
                    @else
                    {{-- Content left --}}
                    <div class="glass-light rounded-3xl p-6 shadow-sm border border-slate-100 card-lift">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="text-xs font-bold text-violet-400 bg-violet-50 px-2.5 py-1 rounded-full">Step {{ $step['num'] }}</span>
                            <span class="text-xl lg:hidden">{{ $step['icon'] }}</span>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2">{{ $step['title'] }}</h3>
                        <p class="text-slate-400 text-sm leading-relaxed">{{ $step['desc'] }}</p>
                    </div>
                    {{-- Icon right --}}
                    <div class="hidden lg:flex justify-start pl-10">
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-violet-500 to-pink-600 flex items-center justify-center text-2xl shadow-xl shadow-violet-500/30 z-10 relative">{{ $step['icon'] }}</div>
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     COURSES
     ============================================================ --}}
<section id="courses" class="py-24 bg-slate-50">
    <div class="max-w-7xl mx-auto px-4">
        <div class="flex flex-col sm:flex-row items-start sm:items-end justify-between mb-12 gap-4">
            <div>
                <span class="inline-block bg-blue-50 text-blue-600 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">Popular Courses</span>
                <h2 class="text-4xl font-bold text-slate-900">Explore Top <span class="text-grad">Courses</span></h2>
                <div class="section-accent" style="margin:12px 0 0;"></div>
            </div>
            <a href="#" class="text-indigo-600 font-semibold text-sm hover:text-indigo-700 flex items-center gap-1 flex-shrink-0">View All Courses →</a>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($courses as $course)
            <div class="card-lift bg-white rounded-3xl overflow-hidden shadow-sm border border-slate-100 flex flex-col">
                {{-- Thumbnail --}}
                <div class="relative h-44 bg-gradient-to-br {{ $course['color'] }} overflow-hidden flex items-center justify-center">
                    <span class="text-8xl thumb-zoom select-none">{{ $course['emoji'] }}</span>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent"></div>
                    <div class="absolute top-3 left-3">
                        <span class="bg-white/20 backdrop-blur text-white text-[11px] font-semibold px-3 py-1 rounded-full">{{ $course['category'] }}</span>
                    </div>
                    <div class="absolute top-3 right-3">
                        <span class="bg-amber-400 text-amber-900 text-[11px] font-bold px-3 py-1 rounded-full">{{ $course['tag'] }}</span>
                    </div>
                    <div class="absolute bottom-3 right-3">
                        <span class="bg-white/20 backdrop-blur text-white text-[11px] px-2.5 py-1 rounded-full">{{ $course['level'] }}</span>
                    </div>
                </div>

                {{-- Body --}}
                <div class="p-6 flex flex-col flex-1">
                    <h3 class="font-bold text-slate-900 text-base mb-1 leading-snug">{{ $course['title'] }}</h3>
                    <p class="text-slate-400 text-xs mb-3">by {{ $course['tutor'] }}</p>

                    <div class="flex items-center gap-3 text-xs text-slate-400 mb-3">
                        <span class="flex items-center gap-1">⏱ {{ $course['duration'] }}</span>
                        <span>·</span>
                        <span class="flex items-center gap-1">👥 {{ $course['students'] }} students</span>
                    </div>

                    <div class="flex items-center gap-1.5 mb-4">
                        <span class="text-amber-500 font-bold text-sm">{{ $course['rating'] }}</span>
                        <div class="flex text-amber-400 text-xs">★★★★★</div>
                        <span class="text-slate-400 text-xs">({{ $course['reviews'] }})</span>
                    </div>

                    <div class="flex items-center justify-between mt-auto pt-4 border-t border-slate-100">
                        <span class="text-xl font-bold text-slate-900">{{ $course['price'] }}</span>
                        <a href="/register" class="btn-indigo px-5 py-2.5 rounded-xl text-white text-sm font-bold">Enroll Now</a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================
     MEET OUR TUTORS
     ============================================================ --}}
<section id="tutors" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4">
        <div class="text-center mb-16">
            <span class="inline-block bg-emerald-50 text-emerald-600 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">Expert Faculty</span>
            <h2 class="text-4xl font-bold text-slate-900">Meet Our <span class="text-grad">Top Tutors</span></h2>
            <div class="section-accent"></div>
            <p class="mt-4 text-slate-400 max-w-md mx-auto">Handpicked experts with proven track records and a passion for teaching.</p>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
            @foreach($tutors as $tutor)
            <div class="tutor-card card-lift bg-white rounded-3xl p-6 text-center shadow-sm border border-slate-100 group">
                {{-- Avatar --}}
                <div class="relative inline-block mb-4">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br {{ $tutor['color'] }} flex items-center justify-center text-4xl shadow-xl tutor-avatar border-4 border-white ring-4 ring-indigo-50">{{ $tutor['emoji'] }}</div>
                    <div class="absolute bottom-0 right-0 w-5 h-5 bg-emerald-500 rounded-full border-2 border-white"></div>
                </div>

                <h3 class="font-bold text-slate-900 text-sm mb-0.5">{{ $tutor['name'] }}</h3>
                <p class="text-slate-400 text-xs mb-3">{{ $tutor['subject'] }}</p>

                <div class="flex justify-center items-center gap-1 mb-3">
                    <div class="text-amber-400 text-xs">★★★★★</div>
                    <span class="text-xs font-bold text-slate-700">{{ $tutor['rating'] }}</span>
                </div>

                <div class="flex justify-center gap-4 text-xs text-slate-400 mb-4">
                    <span>🎓 {{ $tutor['exp'] }} exp</span>
                    <span>👥 {{ $tutor['students'] }}</span>
                </div>

                <a href="/register" class="block btn-indigo px-4 py-2.5 rounded-xl text-white text-xs font-bold w-full opacity-90 group-hover:opacity-100 transition-opacity">Book Session</a>
            </div>
            @endforeach
        </div>

        <div class="text-center mt-10">
            <a href="#" class="inline-flex items-center gap-2 border border-indigo-200 text-indigo-600 font-semibold px-8 py-3 rounded-xl hover:bg-indigo-50 transition-colors text-sm">
                View All 200+ Tutors →
            </a>
        </div>
    </div>
</section>

{{-- ============================================================
     TESTIMONIALS
     ============================================================ --}}
<section class="py-24 bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-950 relative overflow-hidden">
    <div class="bg-orb w-[400px] h-[400px] top-[-100px] right-[-50px]" style="background:radial-gradient(circle,#6366F1,transparent)"></div>

    <div class="max-w-7xl mx-auto px-4 relative z-10">
        <div class="text-center mb-16">
            <span class="inline-block glass text-indigo-300 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">Student Stories</span>
            <h2 class="text-4xl font-bold text-white">What <span class="text-grad">Parents & Students</span> Say</h2>
            <div class="section-accent"></div>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            @foreach($testimonials as $t)
            <div class="quote-card glass card-lift rounded-3xl p-7 relative">
                {{-- Stars --}}
                <div class="flex text-amber-400 text-sm mb-4 gap-0.5">
                    @for($i=0;$i<$t['rating'];$i++)★@endfor
                        </div>

                        <p class="text-gray-300 text-sm leading-relaxed mb-6">"{{ $t['text'] }}"</p>

                        <div class="flex items-center gap-3 pt-4 border-t border-white/10">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-xl border-2 border-white/20">{{ $t['emoji'] }}</div>
                            <div>
                                <div class="text-white font-semibold text-sm">{{ $t['name'] }}</div>
                                <div class="text-gray-500 text-xs">{{ $t['role'] }}</div>
                            </div>
                        </div>
                </div>
                @endforeach
            </div>

            {{-- Trust indicators --}}
            <div class="mt-12 flex flex-wrap justify-center gap-8 items-center">
                @foreach([['⭐','4.9/5','Average Rating'],['👍','98%','Satisfaction Rate'],['🔄','86%','Students Return'],['🏆','#1','Tutoring Platform']] as [$icon,$val,$lbl])
                <div class="text-center">
                    <div class="text-2xl mb-1">{{ $icon }}</div>
                    <div class="text-white font-bold text-lg">{{ $val }}</div>
                    <div class="text-gray-500 text-xs">{{ $lbl }}</div>
                </div>
                @endforeach
            </div>
        </div>
</section>

{{-- ============================================================
     LATEST BLOG POSTS — dynamic from $recentPosts
     ============================================================ --}}
@if($recentPosts->isNotEmpty())
<section class="py-24" style="background: linear-gradient(180deg, #06080f 0%, #0b0d1a 100%)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-14">
            <span class="inline-block bg-indigo-500/10 text-indigo-400 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4 border border-indigo-500/20">Blog</span>
            <h2 class="text-4xl font-bold text-white">Latest <span class="gradient-text">Insights</span></h2>
            <p class="mt-4 text-slate-400 max-w-xl mx-auto">Tips, strategies, and stories from our learning community.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($recentPosts as $index => $post)
            <article class="group bg-white/[0.02] border border-white/[0.06] rounded-2xl overflow-hidden card-glow transition-all duration-300 animate-fade-in-up"
                style="animation-delay: {{ $index * 0.08 }}s">
                {{-- Thumbnail --}}
                @php $thumb = $post->getFirstMediaUrl('thumbnail') ?: $post->getFirstMediaUrl(); @endphp
                @if($thumb)
                <a href="{{ route('blog.show', $post->slug) }}" class="block aspect-video overflow-hidden">
                    <img src="{{ $thumb }}" alt="{{ $post->title }}"
                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                </a>
                @else
                <div class="aspect-video bg-gradient-to-br from-indigo-900/30 to-violet-900/20 flex items-center justify-center">
                    <svg class="w-12 h-12 text-indigo-500/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </div>
                @endif

                <div class="p-6">
                    {{-- Category --}}
                    @if($post->categories->isNotEmpty())
                    <span class="inline-block text-xs font-semibold text-indigo-400 uppercase tracking-wider mb-3">
                        {{ $post->categories->first()->name }}
                    </span>
                    @endif

                    <h3 class="text-lg font-bold text-white mb-2 leading-snug group-hover:text-indigo-300 transition-colors">
                        <a href="{{ route('blog.show', $post->slug) }}">{{ $post->title }}</a>
                    </h3>

                    @if($post->excerpt)
                    <p class="text-slate-400 text-sm leading-relaxed mb-4 line-clamp-2">{{ $post->excerpt }}</p>
                    @endif

                    <div class="flex items-center justify-between mt-4 pt-4 border-t border-white/[0.05]">
                        <div class="flex items-center gap-2">
                            @if($post->author)
                            <div class="w-7 h-7 rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                {{ strtoupper(substr($post->author->name ?? 'A', 0, 1)) }}
                            </div>
                            <span class="text-xs text-slate-400">{{ $post->author->name }}</span>
                            @endif
                        </div>
                        @if($post->published_at)
                        <span class="text-xs text-slate-400">{{ $post->published_at->format('M j, Y') }}</span>
                        @endif
                    </div>
                </div>
            </article>
            @endforeach
        </div>

        <div class="text-center mt-10">
            <a href="{{ route('blog.index') }}"
                class="inline-flex items-center gap-2 px-7 py-3 rounded-xl bg-white/[0.04] border border-white/[0.08] text-slate-300 hover:text-white hover:border-indigo-500/40 text-sm font-semibold transition-all duration-200">
                View all posts
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- ============================================================
     FAQ
     ============================================================ --}}
<section class="py-24 bg-white">
    <div class="max-w-3xl mx-auto px-4">
        <div class="text-center mb-16">
            <span class="inline-block bg-amber-50 text-amber-600 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-4">FAQ</span>
            <h2 class="text-4xl font-bold text-slate-900">Frequently Asked <span class="text-grad">Questions</span></h2>
            <div class="section-accent"></div>
            <p class="mt-4 text-slate-400">Find answers to the most common questions about our service.</p>
        </div>

        <div class="space-y-3">
            @foreach($faqs as $i => $faq)
            <div
                class="border border-slate-200 rounded-2xl overflow-hidden hover:border-indigo-200 transition-colors"
                x-data="{ open: false }">
                <button
                    @click="open = !open"
                    class="w-full flex items-center justify-between px-6 py-5 text-left hover:bg-slate-50 transition-colors"
                    :aria-expanded="open">
                    <span class="font-semibold text-slate-900 text-sm pr-4">{{ $faq['q'] }}</span>
                    <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0 transition-all duration-300" :class="open ? 'bg-indigo-100 rotate-45' : ''">
                        <svg class="w-4 h-4 text-slate-400 transition-colors" :class="open ? 'text-indigo-600' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                </button>
                <div x-show="open" x-collapse class="px-6 pb-5">
                    <p class="text-slate-400 text-sm leading-relaxed">{{ $faq['a'] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================
     CTA BANNER
     ============================================================ --}}
<section class="py-24 bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 relative overflow-hidden">
    <div class="bg-orb w-[500px] h-[500px] top-[-200px] right-[-100px] opacity-20" style="background:radial-gradient(circle,#fff,transparent)"></div>
    <div class="bg-orb w-[300px] h-[300px] bottom-[-100px] left-[-50px] opacity-15" style="background:radial-gradient(circle,#F59E0B,transparent);animation-delay:3s"></div>

    {{-- Dot grid --}}
    <div class="absolute inset-0 opacity-10" style="background-image:radial-gradient(circle,rgba(255,255,255,.4) 1px,transparent 1px);background-size:30px 30px;"></div>

    <div class="max-w-4xl mx-auto px-4 text-center relative z-10">
        <div class="inline-flex items-center gap-2 bg-white/15 rounded-full px-4 py-2 mb-6">
            <span class="text-amber-300">🎯</span>
            <span class="text-white/80 text-sm">Limited Seats Available</span>
        </div>
        <h2 class="text-4xl sm:text-5xl font-bold text-white mb-6 leading-tight">
            Ready to Transform<br>Your Learning?
        </h2>
        <p class="text-white/70 text-lg mb-10 max-w-lg mx-auto">
            Join thousands of students already learning with our expert tutors. Start your personalised journey today.
        </p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="/register" class="btn-amber px-10 py-4 rounded-2xl text-white font-bold text-base shadow-2xl">
                Get Started Free →
            </a>
            <a href="#" class="bg-white/15 border border-white/30 px-10 py-4 rounded-2xl text-white font-semibold text-base hover:bg-white/25 transition-colors backdrop-blur">
                Book Free Demo
            </a>
        </div>
        <p class="text-white/40 text-xs mt-6">No credit card required · Free 30-min demo · Cancel anytime</p>
    </div>
</section>


</div>{{-- /x-data --}}

@endsection