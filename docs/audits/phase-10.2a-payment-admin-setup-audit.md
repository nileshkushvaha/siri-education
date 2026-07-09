# Phase 10.2A — Payment Admin Setup Audit

**Score: 95/100 — SAFE TO PROCEED.** One genuine, reproducible bug was
found, fixed, and covered by a permanent regression test during this
audit. No security issues (credential/secret leakage) were found.

## Method

Same discipline as the Phase 10.1 audit: fresh reads of every Phase
10.2A file with no reliance on the implementation summary, live
queries against the actual dev-DB settings row (not just re-reading
migration files), and a throwaway reproduction test written to prove
or disprove each suspected gap before deciding whether to log it as a
finding. The one confirmed bug's reproduction was promoted to a
permanent regression test; no other throwaway test remains in the
suite.

## 1. Live settings state — verified via tinker, not assumption

Queried `PaymentGatewaySettings`/`BookingSettings` directly against the
dev DB:

- `razorpay_enabled = true`, `razorpay_key_id = 'abc'` — this is
  exactly the "random text credential" scenario the user warned about
  from the start of Phase 10.2. **Verified live** (not just in tests)
  that `PaymentProviderResolver::current()` correctly throws
  `Payment provider "razorpay" is not enabled or its credentials are
  missing/invalid.` when `BookingSettings::payment_provider` is
  temporarily pointed at `razorpay` against this exact live row —
  confirmed and reverted, no lasting change to settings.
- `stripe_enabled = false`, all Stripe credential fields blank.
- `default_provider = null`, `payments_enabled = true`,
  `allowed_providers = []`, both `*_config_status = not_configured`
  (correctly stale — nobody has clicked "Validate Credentials" against
  this `key_id = 'abc'` row yet, which is expected: the persisted
  status only updates on demand, unlike the resolver's real-time
  `isConfigured()` check, which caught it correctly above).

## 2. Finding — "Validate Credentials" silently persisted every gateway's unsaved form state (CONFIRMED, FIXED)

**Severity: Moderate (data-integrity/UX correctness, not directly
exploitable by an external attacker — requires prior admin access to
the settings page). Reproduced, fixed, regression-tested.**

`PaymentSettingsPage::validateAndPersistGatewayReadiness()` (added this
phase) called `$this->saveGatewaySettings($this->data)` before running
the new `PaymentGatewayConfigurationService` check. `saveGatewaySettings()`
unconditionally writes **every** gateway's enabled flag, mode, URLs,
and encrypted secrets from `$this->data` — it is the same method the
page's main "Save Payment Settings" button uses, and is correct there
(one form, one save, everything committed together). It is wrong when
called from a single gateway's "Validate Credentials" action.

Reproduced directly: an admin opens Payment Settings, edits the
Razorpay `key_id` field, separately toggles Stripe's "Enable Gateway"
switch while still deciding whether to finish configuring it (not yet
clicking the page's main Save button), then clicks "Validate
Credentials" for Razorpay only. Before the fix, that action silently
persisted `stripe_enabled = true` to the database — an unrelated,
unintended, unsaved change committed as a side effect of validating a
different gateway. Reproduced via direct invocation of the (now
former) code path with a reflection-based test that set
`$page->data['stripe_enabled'] = true` and asserted it should **not**
be persisted by validating Razorpay; the pre-fix version failed this
assertion (`stripe_enabled` was `true` in the database afterward).

**Fix:** `validateAndPersistGatewayReadiness()` now calls a new
`persistCredentialFieldsForValidation(string $gateway)`, which writes
only that gateway's `{gateway}_enabled` flag and its own credential
fields (`key_id`/`key_secret`/`webhook_secret` for Razorpay;
`publishable_key`/`secret_key`/`webhook_secret` for Stripe) — nothing
else in `$this->data` is touched. Covered by
`tests/Feature/Booking/PaymentGatewaySettingsAdminTest.php`:
`test_validating_one_gateway_does_not_persist_another_gateways_unsaved_state`
(the regression for this bug) and
`test_validating_razorpay_does_persist_its_own_credential_fields`
(confirms the fix doesn't over-correct into validating against stale
data).

## 3. SDK adapter boundary — re-verified

Grep across `RazorpaySdkClient`/`StripeSdkClient`/`RazorpayPaymentProvider`/
`StripePaymentProvider` for direct SDK instantiation outside the two
adapter classes: none found — `\Razorpay\Api\Api` and
`\Stripe\StripeClient` are each instantiated in exactly one file.
`composer show | grep -i cashier` → no output, confirming Cashier was
never installed. Checked both SDKs' exception-message behavior for
secret leakage risk: Stripe's `ApiErrorException` messages come
directly from Stripe's own API response, which Stripe itself already
redacts (e.g. partial-mask on an invalid key, never the full secret);
Razorpay's `Errors\Error` messages are provider-side descriptions with
no key-echoing behavior. Neither adapter adds anything on top of the
provider's own message — no new leakage surface introduced.

## 4. Credential validation — re-confirmed against live data, not just fixtures

`PaymentProviderConfigValidator`'s regexes
(`rzp_(test|live)_[A-Za-z0-9_]+`, `sk_/pk_(test|live)_[A-Za-z0-9_]+`,
`whsec_[A-Za-z0-9_]+`) were re-checked against this environment's
actual stored value (`razorpay_key_id = 'abc'`) rather than only the
test suite's synthetic fixtures — confirmed rejected, both by
`RazorpayPaymentProvider::isConfigured()` and by
`PaymentProviderResolver::resolve()`, live, in §1 above.

## 5. Resolver routing order and gates — re-verified by reading, not re-testing

Re-read `PaymentProviderResolver::resolve()`/`resolveKey()` fresh: the
`payments_enabled` and `allowed_providers` gates are checked **before**
the fake/real-provider split, so neither gate can be bypassed by
selecting `fake`, and the environment check for `fake` still fires even
if `fake` were present in an `allowed_providers` list — confirmed by
tracing the method body line by line, no test gap found here beyond
what Phase 10.2A's own test suite already covers (16 tests in
`PaymentProviderResolverTest.php`).

## 6. Country routing — soft-delete/status interaction checked

`Country::countryRoutedProvider()` uses `Country::query()->where('iso2', ...)`,
which automatically excludes soft-deleted countries (the model uses
`SoftDeletes`) — no risk of routing against a deleted country's stale
`payment_routing`. Inactive-but-not-deleted countries (`status =
'inactive'`) are **not** excluded from routing lookup — this matches
the existing, already-documented precedent in
`docs/architecture/localization-foundation.md` ("Disabling a country
… does not delete or break relationships"), so this is consistent
behavior, not a new gap, but worth calling out explicitly: an admin
who deactivates a country for display purposes does not thereby
disable its payment routing override.

## 7. Duplicate-prevention / boundary re-check

`find app/Models -iname "*payment*"` → only `BookingPayment.php`, no
new model. `git diff` against the generic multi-gateway scaffold
(`PaymentWebhookController`/`PaymentWebhookProcessor`/
`PaymentGatewayConnectionService`) outside of the two
`success_url`/`failure_url` autofill lines and the `validateGatewayCredentials()`
routing addition: no other changes — the generic scaffold's actual
gateway-testing/webhook-processing logic is untouched. Grep for
`Wallet`/`WalletLedgerEntry`/`meeting_` across every Phase 10.2A file:
zero matches.

## 8. Verification commands

- `composer test` → **2029/2029 passing**, 4551 assertions (up from
  2027/2027 at Phase 10.2A's initial completion — 2 additional tests
  for the confirmed-and-fixed admin bug).
- `php artisan migrate:status` → the new settings migration `Ran`,
  nothing pending.
- `./vendor/bin/pint --test` → passed.
- `composer validate` → `./composer.json is valid`.
- `composer show razorpay/razorpay stripe/stripe-php` → 2.9.3 / v20.3.0
  confirmed installed; `composer show | grep -i cashier` → confirmed
  absent.

## Decision

**SAFE TO PROCEED.** One genuine bug was found and fixed with
regression coverage during this audit — an admin-facing data-integrity
issue (unrelated unsaved settings silently committed by a per-gateway
validation action), not an externally exploitable vulnerability. No
credential/secret leakage was found anywhere in the new SDK adapter
layer, the configuration service, or the admin UI changes. The live
dev-DB's pre-existing `razorpay_key_id = 'abc'` was used as a natural,
real (not synthetic) test of the random-credential-rejection path and
confirmed rejected correctly at the resolver level.

**Recommended next step:** wiring country-aware resolution into an
actual checkout entry point (`BookingPaymentService::initiate()`
currently never passes a country), and building the Stripe frontend
Elements/Checkout UI — both already flagged as deferred in
`docs/architecture/phase-10-razorpay-checkout-payment-capture.md`'s
Phase 10.2A section.
