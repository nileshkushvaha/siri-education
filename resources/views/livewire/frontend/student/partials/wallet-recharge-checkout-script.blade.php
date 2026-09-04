<script>
    let walletRechargeRazorpayScriptPromise = null;

    function loadWalletRechargeRazorpayScript() {
        if (window.Razorpay) {
            return Promise.resolve();
        }

        if (! walletRechargeRazorpayScriptPromise) {
            walletRechargeRazorpayScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://checkout.razorpay.com/v1/checkout.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        return walletRechargeRazorpayScriptPromise;
    }

    $wire.on('wallet-recharge-checkout-ready', async (event) => {
        await loadWalletRechargeRazorpayScript();

        const checkout = new window.Razorpay({
            key: event.keyId,
            amount: event.amountMinor,
            currency: event.currency,
            order_id: event.orderId,
            name: @js(config('app.name')),
            description: 'Wallet recharge',
            prefill: { name: event.name, email: event.email },
            handler: function (response) {
                // Server-side: verifies the signature, then asks Razorpay
                // directly whether the order is paid and credits the
                // wallet in the same request when it is.
                $wire.verifyWalletRecharge(
                    response.razorpay_order_id,
                    response.razorpay_payment_id,
                    response.razorpay_signature,
                );
            },
            modal: {
                ondismiss: function () {
                    $wire.call('razorpayCheckoutDismissed');
                },
            },
        });

        checkout.open();
    });
</script>
