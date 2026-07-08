<script>
    let razorpayScriptPromise = null;

    function loadRazorpayScript() {
        if (window.Razorpay) {
            return Promise.resolve();
        }

        if (! razorpayScriptPromise) {
            razorpayScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://checkout.razorpay.com/v1/checkout.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        return razorpayScriptPromise;
    }

    $wire.on('razorpay-checkout-ready', async (event) => {
        await loadRazorpayScript();

        const checkout = new window.Razorpay({
            key: event.keyId,
            amount: event.amountMinor,
            currency: event.currency,
            order_id: event.orderId,
            name: @js(config('app.name')),
            description: 'Booking payment',
            prefill: { name: event.name, email: event.email },
            handler: function (response) {
                $wire.verifyPayment(
                    response.razorpay_order_id,
                    response.razorpay_payment_id,
                    response.razorpay_signature,
                );
            },
        });

        checkout.open();
    });
</script>
