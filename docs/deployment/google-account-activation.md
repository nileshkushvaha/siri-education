# Deploy runbook — Google account activation, student Google sign-up, profile-before-booking

Covers the Google account activation change set committed on `master`. Read the
ordering section first: one step (the Google Cloud redirect URI) must be
right *before* the feature toggle is switched on, or every sign-in attempt
fails with "Sign-in with Google was interrupted".

## What ships

| Area | Change |
|---|---|
| Login page | "Continue with Google" button (students & instructors) below the password form, shown only when the toggle is on and `GOOGLE_CLIENT_ID` is set |
| Auth | Google verifies identity only. Existing user → Google subject linked → signed in through `LoginService` → forced "Create your password" if they never set one. Activation-only: once a password exists, Google is refused with a clear message |
| Registration | Unknown Google email → **student** account (never instructor/admin), honouring Self-registration and Require-approval settings |
| Booking | New precondition: students must have country, mobile number and accepted terms. `/account/complete-profile` collects them; `/book` and the wizard's server-side submit enforce it. Dashboard shows an amber "Complete your profile" card until done |
| Email | Stored trimmed + lowercase on every write (`User::email()` mutator); one-off data migration normalises existing rows |
| User portal | Mobile OTP verification UI removed (sender was a stub); profile checklist rewards "Mobile number" on file instead of "Verified mobile number" |
| Admin | Security → Authentication → new "Google Account Activation" toggle (replaces the disabled "Social Login" roadmap toggle) |
| Schema | `users.google_subject` (unique), `google_email`, `google_linked_at`; email lowercase data fix |
| Dependency | `laravel/socialite` |

## Verified before deploy

- 22 feature-test directories re-run after the change (Booking, Auth, Account,
  Security, Profile, Student, Settings, Payments, Wallet, Lessons, …). See the
  test summary in the PR/commit message for the exact count.
- Manually exercised locally end-to-end: Google sign-in → student created →
  create password → dashboard card → complete profile → booking wizard.

## Not verified before deploy

- Google Cloud **production** OAuth client and consent screen. Only the
  local client has been used. Production needs its own redirect URI added.
- Sign-in with a Google **Workspace** account that has `hd` set. Not
  restricted by us; should behave like any Google account.

## Ordering that matters

1. **Redirect URI before the toggle.** Add the production callback to the
   OAuth client in Google Cloud *before* switching on the admin toggle.
   Google requires an exact match; a mismatch fails every attempt.
2. **Migrate before traffic.** `GoogleActivationService` reads the new
   `users.google_*` columns on every callback.
3. **`optimize:clear` before caching.** Two new routes (`/auth/google*`),
   two new account routes, a new middleware alias in `bootstrap/app.php`.
4. **`npm run build` is required.** New Blade files (`account/complete-profile`,
   the dashboard card, the Google button) use Tailwind class combinations
   the old build never scanned.
5. **Toggle last.** Nothing is visible to users until Security →
   Authentication → "Google Account Activation" is on.

## Google Cloud (once per environment)

1. Google Auth Platform → Branding: app name, support email, authorised
   domain (`sirieducation.com`), privacy/terms URLs.
2. Data access: scopes `openid`, `userinfo.email`, `userinfo.profile` only.
3. Clients → the **Web application** client (not the Meet/Drive service
   account): add the authorised JavaScript origin and redirect URI
   ```
   https://<domain>/auth/google/callback
   ```
4. Audience → Publish app (Testing → In production) so non-test-users can sign in.

## Server steps

```bash
# 1. Environment — production values from the Google Cloud Web client
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

# 2. Code + dependencies
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. Database (adds users.google_* columns; lowercases existing emails)
php artisan down --secret=deploy
php artisan migrate --force
php artisan migrate --force --path=database/settings

# 4. Caches
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up

# 5. Workers (UserRegistered → welcome mail, login history, notifications)
php artisan queue:restart
```

## Post-deploy checks (5 minutes)

1. `/login` shows **no** Google button yet (toggle off).
2. Admin → Security → Authentication → enable "Google Account Activation" → Save.
3. `/login` shows "Continue with Google". Click it with a Google account that
   has **no** account → land on "Create your password" → dashboard shows the
   amber card → Book → complete-profile → wizard opens.
4. Same Google account again after setting a password → "already activated"
   message on `/login`.
5. Admin → Activity Log filter events `google_student_registered`,
   `google_account_linked`, `account_activated`, `profile_completed`.
6. An existing form-registered student can still open `/book` directly (no
   redirect).

## Rollback

- **Fastest:** switch the toggle off. The button disappears and both
  `/auth/google*` routes answer "Google sign-in is currently turned off".
  Nothing else changes; already-linked users keep signing in with email +
  password.
- **Code rollback:** `git checkout <previous>` + `composer install` +
  `npm run build` + `optimize:clear`. The added `users.google_*` columns are
  harmless to old code. **Do not** roll back the email-lowercase migration
  (its `down()` is a no-op by design; lowercase emails are correct for the
  old code too).
- The booking precondition is code, not data. Rolling code back removes it.

## Support notes

- "No student or instructor account is registered for x@y.com, and new
  registrations are currently closed" → Self-registration is off in
  Security → Registration; the student must be pre-created or the toggle enabled.
- "awaiting administrator approval" → Require-approval is on; activate the
  user in Admin → Users.
- "Sign-in with Google was interrupted" → redirect URI mismatch, or the user
  started on one host (`localhost`) and returned to another (`127.0.0.1`).
- Every refusal is recorded as `google_login_rejected` with the exact `reason`.
