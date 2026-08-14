<div class="space-y-6">
    @if (session('package-proposal-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">
            {{ session('package-proposal-status') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200">
            {{ $message }}
        </div>
    @enderror

    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Your package proposals</h2>
        @if (! $showForm)
            <button type="button" wire:click="openForm"
                class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition">
                Create Package Offer
            </button>
        @endif
    </div>

    @if ($showForm)
        <x-account.card>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Student</label>
                    <select wire:model.live="studentId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                        <option value="">Select a student…</option>
                        @foreach ($eligibleStudents as $student)
                            <option value="{{ $student->id }}">{{ $student->name }}</option>
                        @endforeach
                    </select>
                    @error('studentId') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                    @if ($eligibleStudents->isEmpty())
                        <p class="mt-1.5 text-xs text-slate-500">You have no existing paid students to offer a package to yet.</p>
                    @endif
                </div>

                {{-- Country is SERVER-RESOLVED from the student's profile and shown locked —
                     an instructor never chooses their student's country, and nothing here is submitted. --}}
                @if ($studentCountryName)
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Country</label>
                        <div class="w-full rounded-xl bg-white/[0.02] border border-white/[0.06] text-sm text-slate-300 px-3 py-2 flex items-center justify-between">
                            <span>{{ $studentCountryName }}</span>
                            <span class="text-xs text-slate-500">From student profile</span>
                        </div>
                    </div>
                @endif

                @if ($structuredFlow)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Education System</label>
                            <select wire:model.live="educationSystemId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                                <option value="">Select an education system…</option>
                                @foreach ($educationSystems as $system)
                                    <option value="{{ $system->id }}">{{ $system->name }}</option>
                                @endforeach
                            </select>
                            @error('educationSystemId') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                            @if ($educationSystems->isEmpty())
                                <p class="mt-1.5 text-xs text-slate-500">You are not approved to teach under any education system available in this student's country.</p>
                            @endif
                        </div>
                        <div>
                            {{-- Terminology comes from the Education System itself: Class / Grade / Year. --}}
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">{{ $levelTerm }}</label>
                            <select wire:model.live="educationSystemLevelId" @disabled($educationSystemId === '') class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40 disabled:opacity-50">
                                <option value="">Select a {{ strtolower($levelTerm) }}…</option>
                                @foreach ($educationSystemLevels as $level)
                                    <option value="{{ $level->id }}">{{ $level->display_label }}</option>
                                @endforeach
                            </select>
                            @error('educationSystemLevelId') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                            @if ($educationSystemId !== '' && $educationSystemLevels->isEmpty())
                                <p class="mt-1.5 text-xs text-slate-500">No {{ strtolower($levelTerm) }} options are configured for this education system yet.</p>
                            @endif
                        </div>
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Package Offer</label>
                    <select wire:model.live="packageBenefitRuleId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                        <option value="">Select a package offer…</option>
                        @foreach ($benefitRules as $rule)
                            <option value="{{ $rule->id }}">{{ $rule->name }} ({{ $rule->total_quantity }} lessons, pay for {{ $rule->paid_quantity }})</option>
                        @endforeach
                    </select>
                    @error('packageBenefitRuleId') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                    @if ($benefitRules->isEmpty())
                        <p class="mt-1.5 text-xs text-slate-500">No package offers are available yet. An administrator sets these up.</p>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Subject</label>
                        <select wire:model.live="subjectId" @disabled($structuredFlow && $educationSystemLevelId === '') class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40 disabled:opacity-50">
                            <option value="">Select a subject…</option>
                            @foreach ($subjects as $subject)
                                <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                            @endforeach
                        </select>
                        @error('subjectId') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    {{-- The broad AcademicLevel band is only an instructor choice on the legacy path.
                         In the structured flow it is DERIVED from the selected Class/Grade/Year. --}}
                    @unless ($structuredFlow)
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Academic Level <span class="font-normal text-slate-500">(optional)</span></label>
                            <select wire:model.live="academicLevelId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                                <option value="">Any level</option>
                                @foreach ($academicLevels as $level)
                                    <option value="{{ $level->id }}">{{ $level->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless
                </div>

                {{-- Resolved context, not another dropdown: the curriculum is determined by the
                     selections above whenever exactly one is valid. Names only, never internal ids. --}}
                @if ($contextPreview)
                    <div class="rounded-xl border border-indigo-400/20 bg-indigo-500/[0.06] p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-300 mb-2">Resolved academic context</p>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div class="flex justify-between sm:block"><dt class="text-slate-500">Country</dt><dd class="text-slate-200">{{ $contextPreview['country_name'] }}</dd></div>
                            <div class="flex justify-between sm:block"><dt class="text-slate-500">Education System</dt><dd class="text-slate-200">{{ $contextPreview['education_system_name'] }}</dd></div>
                            <div class="flex justify-between sm:block"><dt class="text-slate-500">{{ $contextPreview['level_term'] }}</dt><dd class="text-slate-200">{{ $contextPreview['level_display'] }}</dd></div>
                            <div class="flex justify-between sm:block"><dt class="text-slate-500">Subject</dt><dd class="text-slate-200">{{ $contextPreview['subject_name'] }}</dd></div>
                            <div class="flex justify-between sm:block sm:col-span-2"><dt class="text-slate-500">Curriculum</dt><dd class="text-slate-200">{{ $contextPreview['curriculum_name'] }} <span class="text-slate-500">(v{{ $contextPreview['curriculum_version_number'] }})</span></dd></div>
                        </dl>
                    </div>
                @endif

                {{-- Price is always read-only/server-computed — the instructor can only submit, never edit it. --}}
                <div class="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">Estimated price</p>
                    @if ($previewError)
                        <p class="text-sm text-amber-300">{{ $previewError }}</p>
                    @elseif ($preview)
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div><dt class="text-slate-500">Total lessons</dt><dd class="text-white font-semibold">{{ $preview['total_quantity'] }}</dd></div>
                            <div><dt class="text-slate-500">Pay for</dt><dd class="text-white font-semibold">{{ $preview['paid_quantity'] }}</dd></div>
                            <div><dt class="text-slate-500">Unit price</dt><dd class="text-white font-semibold">{{ \App\Support\MoneyFormatter::format($preview['unit_price_minor'], $preview['currency_code']) }}</dd></div>
                            <div><dt class="text-slate-500">Estimated total</dt><dd class="text-emerald-400 font-bold">{{ \App\Support\MoneyFormatter::format($preview['calculated_price_minor'], $preview['currency_code']) }}</dd></div>
                            @if ($preview['validity_days'])
                                <div><dt class="text-slate-500">Valid for</dt><dd class="text-white font-semibold">{{ $preview['validity_days'] }} days</dd></div>
                            @endif
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">Read-only — price, quantities and validity are all set by the admin-approved package offer and admin review, not by you.</p>
                    @else
                        <p class="text-sm text-slate-500">Select a student, package offer, and subject to see the estimated price.</p>
                    @endif
                </div>

                <div class="flex gap-3">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
                        <span wire:loading.remove wire:target="submit">Submit for approval</span>
                        <span wire:loading wire:target="submit">Submitting…</span>
                    </button>
                    <button type="button" wire:click="cancelForm" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 hover:text-white transition">
                        Cancel
                    </button>
                </div>
            </div>
        </x-account.card>
    @endif

    <x-account.card>
        <div class="divide-y divide-white/[0.06]">
            @forelse ($proposals as $proposal)
                @php($entitlement = $entitlements->get($proposal->id))
                @php($purchase = $purchases->get($proposal->id))
                <div class="py-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white truncate">{{ $proposal->student?->name }} — {{ $proposal->packageBenefitRule?->name }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ \App\Support\MoneyFormatter::format($proposal->final_price_minor ?? $proposal->calculated_price_minor, $proposal->currency_code ?? 'USD') }}
                            </p>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold
                            @class([
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200' => in_array($proposal->status->value, ['approved', 'accepted']),
                                'bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-200' => $proposal->status->value === 'submitted',
                                'bg-rose-100 text-rose-700 dark:bg-rose-400/15 dark:text-rose-200' => in_array($proposal->status->value, ['rejected', 'expired']),
                                'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300' => in_array($proposal->status->value, ['draft', 'cancelled']),
                            ])">
                            {{-- Once accepted, the payment state is the informative half of the story. --}}
                            {{ $purchase ? 'Accepted — '.$purchase->status->label() : $proposal->status->label() }}
                        </span>
                    </div>

                    {{-- Read-only lesson balance for a paid, activated package. Instructors can never modify an entitlement, settle a payment, or extend an expiry. --}}
                    @if ($entitlement)
                        <dl class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 text-sm">
                            <div><dt class="text-xs text-slate-500">Total lessons</dt><dd class="text-white font-semibold">{{ $entitlement->total_quantity }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Used</dt><dd class="text-white font-semibold">{{ $entitlement->used_quantity }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Remaining</dt><dd class="text-emerald-400 font-bold">{{ $entitlement->remaining_quantity }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Valid until</dt><dd class="text-white font-semibold">{{ $entitlement->expires_at?->format('j M Y') ?? 'No expiry' }}</dd></div>
                            <div class="col-span-2 sm:col-span-4"><dt class="text-xs text-slate-500">Package status</dt><dd class="text-white font-semibold">{{ $entitlement->status->label() }}</dd></div>
                        </dl>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <h3 class="text-slate-300 font-semibold mb-2">No package proposals yet</h3>
                    <p class="text-slate-400 text-sm max-w-xs">Offer a personalized lesson package to one of your existing students.</p>
                </div>
            @endforelse
        </div>

        @if ($proposals->hasPages())
            <div class="mt-4">{{ $proposals->links() }}</div>
        @endif
    </x-account.card>
</div>
