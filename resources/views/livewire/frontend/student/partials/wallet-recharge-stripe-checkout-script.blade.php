<script>
    let walletRechargeStripeScriptPromise = null;

    function loadWalletRechargeStripeScript() {
        if (window.Stripe) {
            return Promise.resolve();
        }

        if (! walletRechargeStripeScriptPromise) {
            walletRechargeStripeScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://js.stripe.com/v3/';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        return walletRechargeStripeScriptPromise;
    }

    $wire.on('wallet-recharge-stripe-checkout-ready', async (event) => {
        await loadWalletRechargeStripeScript();

        const mountEl = document.getElementById('wallet-recharge-stripe-payment-element');
        const confirmButton = document.getElementById('wallet-recharge-stripe-confirm-button');
        const errorEl = document.getElementById('wallet-recharge-stripe-payment-errors');

        if (! mountEl || ! confirmButton) {
            return;
        }

        const stripe = window.Stripe(event.publishableKey);
        const elements = stripe.elements({ clientSecret: event.clientSecret });
        const paymentElement = elements.create('payment');
        paymentElement.mount('#wallet-recharge-stripe-payment-element');

        let confirming = false;
        let pollHandle = null;
        let pollAttempts = 0;

        const stopPolling = () => {
            if (pollHandle) {
                clearInterval(pollHandle);
                pollHandle = null;
            }
        };

        const startPolling = () => {
            stopPolling();
            pollAttempts = 0;
            pollHandle = setInterval(async () => {
                pollAttempts++;

                // pollWalletRechargeStatus() only ever re-reads server
                // state — it never marks anything succeeded. A terminal
                // outcome re-renders the Livewire view and removes this
                // button/container, at which point polling stops itself;
                // a ~2 minute cap is the fallback if the outcome never
                // arrives.
                await $wire.call('pollWalletRechargeStatus');

                if (! document.body.contains(confirmButton) || pollAttempts >= 40) {
                    stopPolling();
                }
            }, 3000);
        };

        confirmButton.disabled = false;

        confirmButton.addEventListener('click', async () => {
            // Double-submission guard — a second click while a confirm is
            // already in flight (or already succeeded and awaiting webhook
            // settlement) is a no-op.
            if (confirming) {
                return;
            }

            confirming = true;
            confirmButton.disabled = true;
            if (errorEl) {
                errorEl.textContent = '';
            }

            const { error, paymentIntent } = await stripe.confirmPayment({
                elements,
                redirect: 'if_required',
            });

            if (error) {
                // Stripe.js's own safe, user-facing message only — never
                // raw gateway/internal detail. Re-enables the button so
                // the same attempt can be retried without re-initiating.
                // This is checkout PROGRESS only — it never marks the
                // recharge terminally failed; only a verified webhook does.
                if (errorEl) {
                    errorEl.textContent = error.message ?? 'Payment could not be completed. Please try again.';
                }
                confirming = false;
                confirmButton.disabled = false;

                return;
            }

            // succeeded/processing here only reflects Stripe's own
            // client-side view — it settles nothing on its own. The
            // wallet stays uncredited until a verified webhook settles
            // it; this just starts polling the server's own record.
            if (paymentIntent && (paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing')) {
                startPolling();
            } else {
                confirming = false;
                confirmButton.disabled = false;
            }
        });
    });
</script>
