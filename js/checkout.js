(async function($) {
    'use strict';

    // Disable submit button until ready
	$('input#terms').prop('checked', true);

    // Status variables
    var awaiting_transaction_signing = false;
    var nim_payment_completed = false;

    var use_redirect = function() {
        return CONFIG.RPC_BEHAVIOR === 'redirect';
    }

    var checkout_pay_order_hook = function(event) {
        if (nim_payment_completed) return true;

        event.preventDefault();

        if (awaiting_transaction_signing) return false;

        // Process NIM payment (async)
        do_payment();
    }

    var do_payment = async function() {
        awaiting_transaction_signing = true;
        $('button#place_order').prop('disabled', true);

        // Generate transaction object
        var request = {
            appName: CONFIG.SITE_TITLE,
            shopLogoUrl: CONFIG.SHOP_LOGO_URL || undefined,
            recipient: CONFIG.STORE_ADDRESS,
            value: parseFloat(CONFIG.ORDER_TOTAL),
            fee: parseFloat(CONFIG.TX_FEE),
            extraData: new Uint8Array(JSON.parse(CONFIG.TX_MESSAGE)),
        };

        // Start Accounts action
        try {
            var signed_transaction = await hubApi.checkout(request);
            if (use_redirect()) return;
            on_signed_transaction(signed_transaction);
        } catch (e) {
            on_signing_error(e);
            return;
        }
    }

    var on_signed_transaction = function(signed_transaction) {
        console.log("signed_transaction", signed_transaction);

        // Make sure payment button is disabled when receiving a redirect response
        $('button#place_order').prop('disabled', true);

        // Write transaction hash and sender address into the hidden inputs
        $('#transaction_hash').val(signed_transaction.hash);
        $('#customer_nim_address').val(signed_transaction.raw.sender);

        awaiting_transaction_signing = false;

        $('#nim_gateway_info_block').addClass('hidden');
        $('#nim_payment_complete_block').removeClass('hidden');

        nim_payment_completed = true;

        checkout_form.submit();
    }

    var on_signing_error = function(e) {
        console.error(e);
        if (e.message !== 'CANCELED' && e !== 'Connection was closed' && e.message !== 'Connection was closed') alert('Error: ' + e.message);
        awaiting_transaction_signing = false;
        // Reenable checkout button
        $('button#place_order').prop('disabled', false);
        jQuery('#order_review').unblock();
    }

    // Add submit event listener to form, preventDefault()
    var checkout_form = $('form#order_review');
    checkout_form.on('submit', checkout_pay_order_hook);

    let redirectBehavior = null;
    if (use_redirect()) {
        redirectBehavior = new HubApi.RedirectRequestBehavior(window.location.href);
    }

    // Initialize HubApi
    window.hubApi = new HubApi(CONFIG.HUB_URL, redirectBehavior);

    if (use_redirect()) {
        // Check for a redirect response
        hubApi.on(HubApi.RequestType.CHECKOUT, on_signed_transaction, on_signing_error);
        hubApi.checkRedirectResponse();
    }
})(jQuery);
