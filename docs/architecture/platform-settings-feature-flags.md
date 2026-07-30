# Platform Settings and Feature Flags

## Executive Summary

The platform uses Spatie Laravel Settings as the only settings system — one settings table (`settings`), one migration path (`database/settings/`), one `app(XSettings::class)` resolution pattern. No parallel key-value store or second settings mechanism exists or should be introduced.

This document is the current-state record of the platform's settings groups. Historically, two duplication bugs existed and were corrected: two names for the same booking-window fields, and a couple of features that briefly had two competing on/off switches each — see "Design Clarifications" below for what to watch for so these don't reappear.

Business logic is not wired to every setting yet (full feature-flag enforcement across every route/nav/UI check, for example). These are typed, validated, admin-editable defaults that services consume — check the specific consuming service before assuming a setting is enforced everywhere its name implies.

## The 12 Required Groups

| # | Group | Status | Implementation |
|---|---|---|---|
| 1 | GeneralSettings | Existing, enhanced | `app/Settings/GeneralSettings.php` (`general` group) |
| 2 | SeoSettings | Existing, unchanged | `app/Settings/SeoSettings.php` (`seo` group) |
| 3 | MailSettings | Existing, unchanged | `app/Settings/MailSettings.php` (`mail` group) |
| 4 | PaymentSettings | Existing split, kept split | `PaymentGatewaySettings`, `PaymentConfigurationSettings`, `PaymentAdvancedSettings`, `BankSettings` |
| 5 | BookingSettings | Existing, fields renamed | `app/Settings/BookingSettings.php` (`booking` group) |
| 6 | WalletSettings | Existing, `enabled` removed | `app/Settings/WalletSettings.php` (`wallet` group) |
| 7 | MeetingSettings | Existing, unchanged | `app/Settings/MeetingSettings.php` (`meeting` group) |
| 8 | InstructorSettings | Existing, unchanged | `app/Settings/InstructorSettings.php` (`instructor` group) |
| 9 | Referral configuration | Per-campaign, not a global settings class | `ReferralCampaign` model (reward type/value, eligibility, timing) — there is no `ReferralSettings` class; only the platform-wide on/off switch lives in `FeatureSettings::$referral_enabled` |
| 10 | LocalizationSettings | Recreated, thin | `app/Settings/LocalizationSettings.php` (`localization` group) |
| 11 | SecuritySettings | Existing split, kept split | `AuthenticationSettings`, `PasswordPolicySettings`, `LoginSecuritySettings`, `SessionSettings`, `RegistrationSettings`, `AccountProtectionSettings` |
| 12 | FeatureSettings | Existing, restored as master switch | `app/Settings/FeatureSettings.php` (`features` group) |

**Groups 4 and 11 are intentionally not single classes.** Payment and Security are each split into focused classes, each with its own Filament page, its own tests, and its own permission gates (`security.authentication.*`, `security.password_policy.*`, `security.login_security.*`, `security.session.*`, `security.registration.*`, `security.account_protection.*`). Collapsing six well-tested, independently-authorized classes into one monolithic `SecuritySettings` (or four into one `PaymentSettings`) would be a regression, not standardization — don't do it without a deliberate, separately-approved decision.

## Design Clarifications

### 1. BookingSettings — one pair of fields, not two

`BookingWindowRule` (the only code that enforces the booking window) reads two fields. An earlier pass added a *second* pair with the spec's preferred names but never pointed the admin UI or the rule at them — so admins editing "Minimum Notice" / "Advance Window" in Platform Foundation Settings were changing fields nothing read. Fixed by **renaming**, not duplicating:

- `min_lead_hours` → `minimum_booking_notice_minutes` (value converted ×60, hours → minutes, via a settings migration)
- `max_advance_days` → `maximum_advance_booking_days` (rename only, same unit)

`BookingWindowRule` and the Platform Foundation form now both read the single renamed field. See `database/settings/2026_07_05_160000_rename_booking_window_fields_to_spec_names.php`.

Full current field set: `demo_duration_minutes`, `reservation_expiry_minutes`, `minimum_booking_notice_minutes`, `maximum_advance_booking_days`, `cancellation_window_hours`, `reschedule_limit`, `no_show_grace_minutes`, `auto_completion_delay_minutes`, plus pre-existing fields (`max_daily_bookings_per_teacher`, `assignment_strategy`, captcha/Turnstile fields, payment provider/reservation fields, notification channel toggles) — all preserved.

### 2. LocalizationSettings — a real group, but not a duplicate of GeneralSettings

`GeneralSettings` already owned timezone, language, date/time format, and currency under its own "Localization"/"Application" sections, each with a working admin page. `LocalizationSettings` exists as its own group (per this spec) but holds **only** the fields that don't already exist elsewhere:

- `default_country` (ISO 3166-1 alpha-2)
- `country_detection_enabled`
- `allow_user_locale_switching`

It does **not** declare `fallback_currency` / `fallback_language` / `fallback_timezone` — those would be the same value as `GeneralSettings::$default_currency` / `$default_language` / `$default_timezone` under a different name, with no code anywhere reading the "fallback" copy. If a real fallback-resolution chain (e.g., per-country pricing overriding a platform default) is built later, add it then, against a proven need — don't pre-duplicate it now.

Managed in the **Localization** section of `PlatformFoundationSettingsPage`; `GeneralSettings`' own timezone/language/currency fields stay on the General Settings page.

### 3. FeatureSettings — one on/off switch per feature, always in the same place

`FeatureSettings` is the single master switch for every feature module; domain classes hold configuration only, never their own enabled flag:

- `WalletSettings` — no `enabled` field. Use `FeatureSettings::$wallet_enabled`.
- Referral has no global settings class at all (see the table above) — per-campaign fields live on `ReferralCampaign`; the platform-wide switch is `FeatureSettings::$referral_enabled`.
- `MeetingSettings::$recording_enabled` — **kept alongside** `FeatureSettings::$recording_enabled`. This is the one deliberate exception: the two represent different layers, not a duplicate. `FeatureSettings::$recording_enabled` gates whether the Recording capability exists on the platform at all; `MeetingSettings::$recording_enabled` is the default recording behavior for a session once that capability is on (paired with `recording_retention_days`, which only means anything if recording is happening). Same name, different question — documented in both classes' docblocks so it isn't "fixed" back into a duplicate by mistake.

Full `FeatureSettings` field set: `demo_lessons_enabled`, `wallet_enabled`, `referral_enabled`, `waitlist_enabled`, `homework_enabled`, `reviews_enabled`, `recording_enabled`.

## Admin Management

`PlatformFoundationSettingsPage` (`/admin/settings/platform-foundation`) manages the settings groups that don't yet have (and don't yet need) their own dedicated navigation page:

- Booking (window/lifecycle fields only — booking's payment/captcha/channel fields aren't on this page)
- Wallet
- Meeting
- Instructor
- Referral
- Localization
- Feature Flags

Access is gated by the existing `HasSettingsAccess` trait (super_admin, or any `settings.*` permission) plus the specific permissions seeded by `PlatformSettingsPermissionSeeder`:

- `settings.platform_foundation.view`
- `settings.platform_foundation.update`

(granted to the `manager` role; super_admin always passes via `Gate::before()`).

`GeneralSettings`, `SeoSettings`, `MailSettings`, the four Payment classes, and the six Security classes each keep their own existing, already-authorized Filament pages — that structure is unchanged aside from the 3 Localization fields having moved from `GeneralSettingsPage` to Platform Foundation's Localization section.

## Boundaries — Not Yet Implemented

- Wallet ledger or balance calculations.
- Meeting provider API integrations.
- Referral reward issuance.
- Full feature-flag enforcement across business workflows (routes/nav/UI don't yet check `FeatureSettings` before rendering).
- Country-specific pricing/payment routing logic.

Future business logic must consume these settings through services/actions — never directly from Livewire components, Blade views, or Filament resources.

## Settings Migrations

| File | Purpose |
|---|---|
| `2026_07_05_140000_add_platform_foundation_settings.php` | Original pass — added the settings groups. Left in place; never edit an applied migration. |
| `2026_07_05_150000_deduplicate_platform_foundation_settings.php` | First correction pass — removed the dead Booking duplicate fields and the `Wallet/Referral::$enabled` fields (later partially reversed by the next file once the master-switch design was clarified). |
| `2026_07_05_160000_rename_booking_window_fields_to_spec_names.php` | Renames `min_lead_hours`/`max_advance_days` to the spec names (with unit conversion), rather than re-adding them as a duplicate. |
| `2026_07_05_160100_move_country_fields_to_localization_settings.php` | Moves `default_country`/`country_detection_enabled`/`allow_user_locale_switching` from `general.*` to `localization.*`. |
| `2026_07_05_160200_restore_feature_settings_as_master_switches.php` | Restores `features.wallet_enabled`/`referral_enabled`/`recording_enabled`, removes `wallet.enabled`/`referral.enabled`. |

## Tests

| File | Covers |
|---|---|
| `tests/Feature/Settings/PlatformSettingsFeatureFlagsTest.php` | Booking/Wallet/Meeting/Instructor/Referral/Localization/Feature defaults load; Platform Foundation page persists updates for all seven sections; the single-switch design (no `enabled` on Wallet/Referral); the renamed Booking fields round-trip through the page into the fields `BookingWindowRule` reads. |
| `tests/Feature/Settings/GeneralSettingsTest.php` | GeneralSettings defaults load; General Settings page persists updates. |
| `tests/Feature/Settings/SeoSettingsTest.php` | SeoSettings defaults load; SEO Settings page persists updates. |
| `tests/Feature/Settings/MailSettingsLoadUpdateTest.php` | MailSettings defaults load and can be updated (direct settings-class level; the Filament page has additional per-sender-area and encrypted-password logic outside this phase's scope). |
| `tests/Feature/Settings/PaymentSettingsLoadUpdateTest.php` | `PaymentConfigurationSettings` (representative of the 4-class Payment split) defaults load and can be updated. |
| `tests/Feature/Security/*SettingsTest.php` | Pre-existing — full coverage of the 6-class Security split, unchanged by this phase. |

## Rules for the Next Change

Restated from `docs/architecture/duplicate-prevention-rules.md`, applied specifically to settings:

1. Before adding a field, grep for its concept under a different name across every `app/Settings/*.php` file — not just the class you're editing.
2. Before adding a class, check whether an existing class (or split of classes) already covers the concern. Enhance it; don't parallel it.
3. A feature's on/off switch lives in exactly one place. If a domain class needs configuration once a feature is on, that's fine — it doesn't need its own enabled flag too.
4. Settings migrations are forward-only. To fix a mistake in an already-applied migration, write a new migration that renames/deletes/adds — never edit the old file.
5. If two fields must legitimately share a name across two classes (like `recording_enabled`), document *why* in both classes' docblocks so the next person doesn't "fix" it into a duplicate or, worse, a silent divergence.
