{{--
    Checkout for "pay for all classes" — a WALLET RECHARGE, not a booking
    payment, so it has its own events and verifiers. The booking checkout
    scripts beside this one must never handle these events: the recharge's
    order id can never match a booking's, and once did exactly that.
--}}
<script>
    let seriesPrepaymentRazorpayPromise = null;
    let seriesPrepaymentStripePromise = null;

    function loadSeriesPrepaymentScript(src, ready) {
        if (ready()) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    $wire.on('series-prepayment-checkout-ready', async (event) => {
        seriesPrepaymentRazorpayPromise ??= loadSeriesPrepaymentScript('https://checkout.razorpay.com/v1/checkout.js', () => !! window.Razorpay);
        await seriesPrepaymentRazorpayPromise;

        const checkout = new window.Razorpay({
            key: event.keyId,
            amount: event.amountMinor,
            currency: event.currency,
            order_id: event.orderId,
            name: @js(config('app.name')),
            description: 'Payment for your class schedule',
            prefill: { name: event.name, email: event.email },
            handler: function (response) {
                // Server-side: verifies the signature, asks Razorpay whether
                // the order is paid, credits the wallet and confirms the
                // classes in the same request when it is.
                $wire.verifySeriesPrepayment(
                    response.razorpay_order_id,
                    response.razorpay_payment_id,
                    response.razorpay_signature,
                );
            },
            modal: {
                ondismiss: function () {
                    $wire.call('seriesCheckoutDismissed');
                },
            },
        });

        checkout.open();
    });

    $wire.on('series-prepayment-stripe-checkout-ready', async (event) => {
        seriesPrepaymentStripePromise ??= loadSeriesPrepaymentScript('https://js.stripe.com/v3/', () => !! window.Stripe);
        await seriesPrepaymentStripePromise;

        const mountEl = document.getElementById('series-stripe-payment-element');
        const confirmButton = document.getElementById('series-stripe-confirm-button');
        const errorEl = document.getElementById('series-stripe-payment-errors');

        if (! mountEl || ! confirmButton) {
            return;
        }

        const stripe = window.Stripe(event.publishableKey);
        const elements = stripe.elements({ clientSecret: event.clientSecret });
        elements.create('payment').mount('#series-stripe-payment-element');

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

                // pollSeriesPaymentStatus() re-reads the server's record and,
                // throttled, asks Stripe through the reconciliation path the
                // sweep uses; the credit and the classes settle server-side.
                // A terminal outcome re-renders and removes this container.
                await $wire.call('pollSeriesPaymentStatus');

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
