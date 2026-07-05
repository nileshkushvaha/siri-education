# Localization Foundation

## Executive Summary

The Localization Foundation reuses the existing `countries` and `states` masters and adds only the missing Phase 1 primitives required for country-aware behavior. It does not build pricing, payment routing, wallet, or booking business logic.

Implemented foundation pieces:

- `Currency` master with ISO 4217 codes.
- `Language` master with language tags and text direction.
- Country defaults for currency, language, timezone, support contacts, date/time/number formats, feature flags, and payment routing metadata.
- Filament admin resources for currencies and languages.
- Enhanced country admin form/table for localization defaults.
- Shield-style policies and permissions for country/state/currency/language masters.
- Seeders for India, USA, UK, Canada, Australia and INR, USD, GBP, CAD, AUD.
- Relationship tests for country/currency/language defaults.

## Existing Assets Reused

| Concern | Existing source |
|---|---|
| Country master | `app/Models/Country.php`, `database/migrations/2026_06_26_183253_create_countries_table.php` |
| State master | `app/Models/State.php`, `database/migrations/2026_07_02_004421_create_states_table.php` |
| User country fields | `user_profiles.country_id`, `user_profiles.state_id` |
| Timezone fields | `user_profiles.timezone`, `bookings.timezone`, booking DTO timezone fields |
| General settings | `app/Settings/GeneralSettings.php` |
| Payment settings | `app/Settings/PaymentConfigurationSettings.php`, payment gateway settings pages |
| Booking currency snapshots | `bookings.currency`, `booking_types.currency` |
| Filament master UI | `app/Filament/Resources/Countries`, `app/Filament/Resources/States` |

## New Assets

| Concern | New source |
|---|---|
| Currency model | `app/Models/Currency.php` |
| Language model | `app/Models/Language.php` |
| Currency migration | `database/migrations/2026_07_05_130000_create_currencies_table.php` |
| Language migration | `database/migrations/2026_07_05_130100_create_languages_table.php` |
| Country localization migration | `database/migrations/2026_07_05_130200_add_localization_fields_to_countries_table.php` |
| Filament resources | `app/Filament/Resources/Currencies`, `app/Filament/Resources/Languages` |
| Policies | `app/Policies/CurrencyPolicy.php`, `app/Policies/LanguagePolicy.php` |
| Seeders | `CurrencySeeder`, `LanguageSeeder`, `LocalizationPermissionSeeder`; enhanced `CountrySeeder` |
| Tests | `tests/Feature/Localization/LocalizationFoundationTest.php` |

## Country Defaults

`countries` now supports nullable defaults:

- `default_currency_id`
- `default_language_id`
- `default_timezone`
- `support_email`
- `support_phone`
- `date_format`
- `time_format`
- `number_format`
- `feature_flags`
- `payment_routing`

These fields are nullable to preserve compatibility with existing countries and historical data. Disabling a country with `status = inactive` hides it from active dropdowns but does not delete or break relationships.

## Boundaries

This phase deliberately does not implement:

- Country-specific pricing rules.
- Payment gateway selection logic.
- Wallet ledger or wallet balance currency behavior.
- Booking price calculation by country.
- Runtime feature-flag evaluation.

Future phases should consume this foundation through services that resolve country context, then pass snapshots into booking/payment/wallet records.

## Duplicate Prevention

Do not create parallel tables for:

- `countries`
- `states`
- `currencies`
- `languages`
- user country/profile location fields
- payment gateway settings

Booking/payment records should continue to store currency snapshots for historical correctness. Country default currency should be treated as a defaulting source, not as a mutable join that rewrites old financial records.

## Seeded Defaults

| Country | ISO2 | Currency | Timezone | Language |
|---|---|---|---|---|
| India | IN | INR | Asia/Kolkata | en |
| United States | US | USD | America/New_York | en |
| United Kingdom | GB | GBP | Europe/London | en |
| Canada | CA | CAD | America/Toronto | en |
| Australia | AU | AUD | Australia/Sydney | en |

## Next Phase Guidance

Add a `LocalizationContextService` only when a runtime feature needs it. That service should resolve country from authenticated profile, request, booking guest, or explicit admin selection, then return immutable defaults for currency, timezone, language, support contact, and feature flags.
