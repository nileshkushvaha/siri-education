# Apple Pay Domain Verification (Razorpay Standard Checkout)

Apple Pay reaches this platform as a **payment method inside Razorpay
Standard Checkout**, not as a separate gateway — the same arrangement
as PayPal (see
[payment-collection-and-payout-provider-routing.md](../payment-collection-and-payout-provider-routing.md)
§8.1). `RazorpayPaymentProvider` already serves it end to end: same
order creation, same webhook settlement, same reconciliation, same
refund policy.

**No application code is required.** The checkout config in
`resources/views/livewire/frontend/booking/partials/razorpay-checkout-script.blade.php`
passes no `method` restriction, so Apple Pay appears automatically on
eligible devices once the domain is verified. The `applepay_*` fields
in `PaymentGatewaySettings` are NOT used for this — they were scaffolded
for a direct Apple Pay integration that does not exist and should not be
configured (see §5).

## 1. What Razorpay is asking for

Apple requires proof that you control the domain the payment sheet will
be presented on. Razorpay's own domains (`razorpay.com`,
`api.razorpay.com`, `pages.razorpay.com`, …) are pre-verified and need
no action. Only domains YOU serve need verifying.

Apple Pay through Razorpay is **International only** — it cannot collect
INR, exactly like PayPal. It will serve the non-INR launch markets and
never India.

## 2. Steps

1. Razorpay Dashboard → Apple Pay → **Verification file**. This
   downloads a file named `apple-developer-merchantid-domain-association`
   with **no file extension**. Do not rename it, do not add `.txt`.
2. Place it at `public/.well-known/apple-developer-merchantid-domain-association`
   and **commit it**. Apple re-checks the file periodically, so a file
   that exists only on one server or only until the next deploy will
   silently un-verify later.
3. Deploy, then confirm each domain answers correctly (see §3).
4. Razorpay Dashboard → **Verify domains**.

## 3. Verify before clicking "Verify domains"

```bash
curl -sSI https://sirieducation.com/.well-known/apple-developer-merchantid-domain-association
curl -sSI https://www.sirieducation.com/.well-known/apple-developer-merchantid-domain-association
```

Every one of these must hold, on **each** domain independently:

- **HTTP 200** — not 301, not 302. Apple does not follow redirects for
  this file. A www→apex redirect (or apex→www) will fail verification
  for the redirected host even though the file is reachable "in a
  browser". If you redirect one to the other, verify only the domain you
  actually serve checkout from, and remove the other from Razorpay.
- **HTTPS with a valid certificate.** A self-signed or expired cert
  fails.
- **Publicly reachable.** No basic auth, no IP allowlist, no
  Cloudflare "Under Attack" challenge, no maintenance mode.
- **`Content-Type: text/plain`** (or at least not `text/html`). Some
  servers guess `text/html` for an extensionless file; Apple rejects
  that.

## 4. Server configuration

**Apache** — already works. The project `.htaccess` sends a request to
`index.php` only when the path is not an existing file
(`RewriteCond %{REQUEST_FILENAME} !-f`), so a real file in
`public/.well-known/` is served directly.

**Nginx** — this is the usual cause of failure. Many hardened configs
carry a dotfile deny rule:

```nginx
location ~ /\. { deny all; }     # <- this blocks /.well-known
```

Add an explicit allow BEFORE it:

```nginx
location ^~ /.well-known/ {
    allow all;
    default_type text/plain;
    try_files $uri =404;
}
```

`default_type text/plain` also fixes the content-type point in §3.

**Do not** serve this through a Laravel route. If the file is missing,
the request falls through to Laravel's 404 handler, which runs the
managed SEO redirect resolver in `bootstrap/app.php` — that can answer
with a **301 instead of a 404**, which reads to Apple as a redirect and
fails verification in a way that is genuinely confusing to debug.

## 5. Clean-up in the Razorpay dashboard

- **Remove `127.0.0.1`.** It can never verify — Apple must reach the
  domain from the public internet. It will sit "Unverified" forever.
- Decide between `sirieducation.com` and `www.sirieducation.com`. Keep
  whichever actually serves the booking pages; a redirected host cannot
  pass (§3).
- Leave the `razorpay.com` / `razorpay.me` entries alone. They are
  Razorpay's own and are already verified.

## 6. Testing

Apple Pay renders only on Safari on iOS/iPadOS/macOS with a card already
added to Wallet, and only for a currency Apple Pay and Razorpay both
support — which excludes INR. It will not appear in Chrome on a desktop,
and its absence there is not a bug.

Place one test booking in a non-INR currency from a real Apple device
and confirm the sheet appears in Razorpay Checkout and that the booking
settles through the existing webhook route
(`api/webhooks/bookings/payments/razorpay`).

## 7. Do not configure the `applepay_*` settings

`PaymentGatewaySettings` carries `applepay_enabled`,
`applepay_merchant_id`, `applepay_merchant_certificate`,
`applepay_merchant_key` and an `applepay_webhook_url`. These belong to a
DIRECT Apple Pay integration that this codebase does not have — there is
no `PaymentProviderInterface` adapter for `applepay`, and its webhook URL
points at the generic path that only logs and settles nothing.

Configuring them achieves nothing and is actively misleading: it implies
a settlement route exists. Apple Pay via Razorpay needs no merchant
certificate from you at all — Razorpay holds the merchant identity, which
is why its own domains appear pre-verified in the dashboard.
