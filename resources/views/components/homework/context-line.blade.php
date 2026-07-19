@props(['assignment'])

{{-- Phase 24J — GAP-021: safe educational-context labels. Historical
     context stays visible even when the plan is archived/removed. --}}
@if ($assignment->booking !== null || $assignment->learningPlan !== null)
    <p class="text-xs text-slate-500 mt-1">
        @if ($assignment->booking !== null)
            Lesson: {{ $assignment->booking->starts_at?->timezone($assignment->booking->timezone ?? 'UTC')->format('M j, Y g:i A') }}@if ($assignment->booking->type !== null) ({{ $assignment->booking->type->name }})@endif
        @endif
        @if ($assignment->booking !== null && $assignment->learningPlan !== null)
            &middot;
        @endif
        @if ($assignment->learningPlan !== null)
            Plan: {{ $assignment->learningPlan->title }}@if ($assignment->learningPlan->trashed() || ! $assignment->learningPlan->status->isWritable()) ({{ $assignment->learningPlan->status->label() }})@endif
        @endif
    </p>
@endif
