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
                $wire.verifyWalletRecharge(
                    response.razorpay_order_id,
                    response.razorpay_payment_id,
                    response.razorpay_signature,
                );
            },
        });

        checkout.open();
    });
</script>
