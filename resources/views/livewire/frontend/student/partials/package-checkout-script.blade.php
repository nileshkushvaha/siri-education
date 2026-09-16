<script>
    let packageRazorpayScriptPromise = null;

    function loadPackageRazorpayScript() {
        if (window.Razorpay) {
            return Promise.resolve();
        }

        if (! packageRazorpayScriptPromise) {
            packageRazorpayScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://checkout.razorpay.com/v1/checkout.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        return packageRazorpayScriptPromise;
    }

    $wire.on('package-checkout-ready', async (event) => {
        await loadPackageRazorpayScript();

        const checkout = new window.Razorpay({
            key: event.keyId,
            amount: event.amountMinor,
            currency: event.currency,
            order_id: event.orderId,
            name: @js(config('app.name')),
            description: 'Lesson package',
            prefill: { name: event.name, email: event.email },
            handler: function (response) {
                // Server-side: verifies the signature, asks Razorpay whether
                // the order is paid, and unlocks the lessons in the same
                // request when it is.
                $wire.verifyPackagePayment(
                    event.purchaseId,
                    response.razorpay_order_id,
                    response.razorpay_payment_id,
                    response.razorpay_signature,
                );
            },
            modal: {
                ondismiss: function () {
                    $wire.packageCheckoutDismissed(event.purchaseId);
                },
            },
        });

        checkout.open();
    });
</script>
