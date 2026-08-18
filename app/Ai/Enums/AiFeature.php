<?php

declare(strict_types=1);

namespace App\Ai\Enums;

/**
 * Every AI-consuming capability the platform will ever have, and the
 * AiSettings flag that gates it. Persisted as ai_runs.feature_key, so
 * usage and cost are always attributable to a feature rather than to
 * "AI" as an undifferentiated line item.
 *
 * The four product cases exist HERE in P0 while remaining entirely
 * unimplemented: the enum is the registry the flag, the cost report and
 * the run row all key off, and shipping it now is what lets P1-P4 add a
 * capability without touching this module. Their flags ship OFF and no
 * prompt is registered for them — see AiPromptCatalog.
 *
 * PlatformDiagnostics is the one case with no product feature behind
 * it: the admin "test connection" action. It has no flag of its own
 * because it is already gated by the module switch plus the
 * TestConnection:AiPlatform permission, and an operator must be able to
 * verify credentials precisely when every product flag is still off.
 */
enum AiFeature: string
{
    case PlatformDiagnostics = 'platform_diagnostics';

    /** P1 — Admin Quality Intelligence. */
    case QualityInsights = 'quality_insights';

    /** P2 — Instructor Homework Copilot. */
    case HomeworkAssistant = 'homework_assistant';

    /** P3 — Lesson Summary Generation. */
    case LessonSummary = 'lesson_summary';

    /** P4 — Communication Safety & Moderation. */
    case CommunicationModeration = 'communication_moderation';

    /**
     * The AiSettings property that must be true for this feature to
     * run. Null means "no per-feature flag" — the module switch alone
     * decides, which is only ever true for PlatformDiagnostics.
     */
    public function settingsFlag(): ?string
    {
        return match ($this) {
            self::PlatformDiagnostics => null,
            self::QualityInsights => 'quality_insights_enabled',
            self::HomeworkAssistant => 'homework_assistant_enabled',
            self::LessonSummary => 'lesson_summary_enabled',
            self::CommunicationModeration => 'communication_moderation_enabled',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PlatformDiagnostics => 'Platform diagnostics',
            self::QualityInsights => 'Quality insights',
            self::HomeworkAssistant => 'Homework assistant',
            self::LessonSummary => 'Lesson summary',
            self::CommunicationModeration => 'Communication moderation',
        };
    }

    /** The phase that will implement the feature — documentation only. */
    public function phase(): string
    {
        return match ($this) {
            self::PlatformDiagnostics => 'P0',
            self::QualityInsights => 'P1',
            self::HomeworkAssistant => 'P2',
            self::LessonSummary => 'P3',
            self::CommunicationModeration => 'P4',
        };
    }
}
