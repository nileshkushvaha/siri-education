<script>
    let packageStripeScriptPromise = null;

    function loadPackageStripeScript() {
        if (window.Stripe) {
            return Promise.resolve();
        }

        if (! packageStripeScriptPromise) {
            packageStripeScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://js.stripe.com/v3/';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        return packageStripeScriptPromise;
    }

    $wire.on('package-stripe-checkout-ready', async (event) => {
        await loadPackageStripeScript();

        const mountEl = document.getElementById('package-stripe-payment-element');
        const confirmButton = document.getElementById('package-stripe-confirm-button');
        const errorEl = document.getElementById('package-stripe-payment-errors');

        if (! mountEl || ! confirmButton) {
            return;
        }

        const stripe = window.Stripe(event.publishableKey);
        const elements = stripe.elements({ clientSecret: event.clientSecret });
        elements.create('payment').mount('#package-stripe-payment-element');

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

                // pollPackagePaymentStatus() re-reads the server's record and,
                // throttled, asks Stripe through the reconciliation path the
                // sweep uses; settlement happens server-side only.
                await $wire.call('pollPackagePaymentStatus');

                if (! document.body.contains(confirmButton) || pollAttempts >= 40) {
                    stopPolling();
                }
            }, 3000);
        };

        confirmButton.disabled = false;

        confirmButton.addEventListener('click', async () => {
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
                if (errorEl) {
                    errorEl.textContent = error.message ?? 'Payment could not be completed. Please try again.';
                }
                confirming = false;
                confirmButton.disabled = false;

                return;
            }

            if (paymentIntent && (paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing')) {
                startPolling();
            } else {
                confirming = false;
                confirmButton.disabled = false;
            }
        });
    });
</script>
