<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Configuration for the provider-neutral AI platform layer.
 *
 * DELIBERATELY NOT the module's on/off switch. `FeatureSettings::
 * $ai_enabled` is the single master switch, following the rule that
 * FeatureSettings owns exactly one switch per feature module and a
 * domain settings class never redeclares its own `enabled` field. The
 * four `*_enabled` flags here are CAPABILITY flags one layer below
 * that: they can only ever narrow, never widen — the same relationship
 * `MeetingSettings::$zoom_recording_enabled` has with
 * `FeatureSettings::$recording_enabled`. Every one of them ships OFF,
 * and P0 implements none of the features behind them.
 *
 * `openai_api_key` is `Crypt::encryptString()`'d on save by
 * AiSettingsPage, never re-displayed afterwards, and decrypted in
 * exactly one place — AiCredentialStore. Nothing else in the
 * application reads this property.
 *
 * Models are stored as ROLES (generation/fast/embedding/moderation)
 * rather than being referenced by name in code, so switching a model is
 * a settings change; AiModelRole and AiModelResolver are the only path
 * from a role to a model string.
 */
class AiSettings extends Settings
{
    /** Provider key from AiProviderRegistry — 'openai' or 'fake'. */
    public string $provider;

    /** Encrypted at rest; never returned to Livewire after initial submission. */
    public ?string $openai_api_key;

    /** Optional OpenAI organization/project header value. Not a secret. */
    public ?string $openai_organization;

    public string $generation_model;

    public string $fast_model;

    public string $embedding_model;

    public string $moderation_model;

    /** Per-request ceiling handed to the provider adapter. */
    public int $request_timeout_seconds;

    // ── Capability flags (all OFF in P0) ──────────────────────────────

    /** P1 — Admin Quality Intelligence. */
    public bool $quality_insights_enabled;

    /** P2 — Instructor Homework Copilot. */
    public bool $homework_assistant_enabled;

    /** P3 — Lesson Summary Generation. */
    public bool $lesson_summary_enabled;

    /** P4 — Communication Safety & Moderation. */
    public bool $communication_moderation_enabled;

    // ── Cost control ──────────────────────────────────────────────────

    /**
     * Estimated-spend ceilings in $cost_currency. Null means "no
     * ceiling"; 0.0 means "block everything", which is a legitimate
     * emergency brake distinct from "unlimited" — hence nullable rather
     * than a magic zero.
     */
    public ?float $daily_cost_limit;

    public ?float $monthly_cost_limit;

    public string $cost_currency;

    /**
     * Fraction of a ceiling (0.0-1.0) at which an operational alert is
     * raised — a fraction rather than an amount so it survives a limit
     * being raised. Null disables budget alerting.
     */
    public ?float $budget_alert_threshold;

    /**
     * Model pricing for cost ESTIMATION, in $cost_currency per one
     * million tokens, as `model => "input/output"` (e.g. "2.0/8.0").
     * AiCostEstimator is the only reader and owns the parsing.
     *
     * Admin-maintained on purpose — provider list prices change without
     * a deploy, and a hardcoded price silently becomes a wrong number on
     * a finance report. An unpriced model estimates 0.0 and is surfaced
     * as unpriced rather than guessed.
     *
     * A flat string map rather than a nested array: Spatie Settings
     * derives a cast from the annotated generic below and has none for a
     * nested array shape. The flat form is also exactly what the admin
     * KeyValue field stores, so no lossy conversion sits between the UI
     * and the stored value.
     *
     * @var array<string, string>
     */
    public array $model_pricing;

    // ── Operational status (written by the page's health check) ───────

    /** 'healthy' | 'unhealthy' | 'unknown' */
    public string $last_health_status;

    public ?string $last_health_check_at;

    public static function group(): string
    {
        return 'ai';
    }
}
