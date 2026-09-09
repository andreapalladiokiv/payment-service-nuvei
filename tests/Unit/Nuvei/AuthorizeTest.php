<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\Authorize;

/*
| {@see Authorize} serves both authorize operations. `authorize` places a standalone hold;
| `authorizeRebilling` places one inside a series, and the only difference on the wire is the
| rebilling block the shared payment body adds for a {@see RebillingCommand}.
|
| Which operation the caller chose used to reach a shared request class as a `rebilling` flag in a
| parameter bag — the only way omnipay gave a distinct operation to announce itself. It is the
| command's TYPE now, so a payment cannot be a series member by accident and cannot fail to be one
| because a flag was not set.
*/

function nuveiPlacement(): PlacementCommand
{
    return new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiTestToken(),
        amount: new Money(2500, new Currency('USD')),
    );
}

it('authorizes as an Auth that settles later', function () {
    // transactionType 'Auth' with settleType 0 is what makes this a hold rather
    // than a sale. Either half alone is wrong: 'Auth' with the default settle type
    // captures immediately, which would take the customer's money on a booking
    // that is only being held.
    //
    // And 0 is falsy, so the body's `!== null` check is the only thing keeping the key on the
    // request. A truthiness test there would drop it and the acquirer would apply its account
    // default — a capture on some accounts.
    $data = nuveiAuthorizationOf(nuveiPlacement(), ['reference' => 'temp_token_auth'])->payload();

    expect($data['transactionType'])->toBe('Auth')
        ->and($data)->toHaveKey('settleType', 0);
});

it('sends the authorization to payment.do and maps what came back', function () {
    // Driven through the transport rather than asserted on the payload alone: the operation folds
    // every Throwable into a failed result, so one that never got out would still answer in the
    // right shape. The recorded call is what rules that out.
    $calls = [];

    $result = nuveiAuthorizationOf(nuveiPlacement(), [
        'reference' => 'temp_token_auth',
        'sessionToken' => 'sess_auth',
        'restClient' => nuveiSuiteRecordingClient($calls, [
            'status' => 'SUCCESS',
            'transactionStatus' => 'APPROVED',
            'transactionId' => 'txn-auth',
        ]),
    ])->authorize();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/payment.do')
        ->and($calls[0]['params']['transactionType'])->toBe('Auth')
        ->and($calls[0]['params']['settleType'])->toBe(0)
        ->and($calls[0]['params']['sessionToken'])->toBe('sess_auth')
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe('txn-auth')
        // The authorization is what opens the intent, and a later settle overwrites `reference`,
        // so the anchor is recorded alongside it.
        ->and($result->metadata)->toBe(['opening_transaction_reference' => 'txn-auth']);
});

it('answers with a failed authorization when the call never leaves', function () {
    // Not an exception out of the operation: the gateway stack folds a raw Throwable into what
    // reads as an acquirer decline for a request no acquirer ever saw.
    $result = nuveiAuthorizationOf(nuveiPlacement(), [
        'restClient' => nuveiSuiteUnreachableClient(),
    ])->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Connection timed out');
});

// ──────────────────────────────────────────────
//  the rebilling block
//
//  Their reference words isRebilling on POSITION in the series — "0 – For the first
//  rebilling payment. 1 – For all subsequent rebilling transactions" — not on
//  CIT/MIT, and the two axes come apart: a series opened without the cardholder
//  present is still the first of its series. Position is derived rather than
//  declared: recurring is never first, and an unscheduled payment with nothing
//  before it is.
//
//  At the request ROOT, per their own SDK: paymentCC() lists isRebilling in the same
//  mandatoryFields array as merchantId and amount, and validate() checks that array
//  against array_keys($params). The sandbox cannot corroborate the level — it
//  approves the block anywhere, and a nonsense field too (NuveiRebillingProbeTest).
// ──────────────────────────────────────────────

function nuveiRebillingPayload(PaymentInitiation $initiation, ?string $anchor = null): array
{
    return nuveiAuthorizationOf(new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiTestAttachedPaymentMethod(),
        amount: new Money(5000, new Currency('USD')),
        initiation: $initiation,
        genesisReference: $anchor,
    ))->payload();
}

it('marks the payment that opens a series as the first, whoever initiated it', function (PaymentInitiation $initiation) {
    // Inside a series the absent anchor is unambiguous: nothing precedes this
    // payment. Keying the position on CIT/MIT got the second of these wrong — a
    // subscription the merchant opens is still the FIRST of its series.
    $data = nuveiRebillingPayload($initiation);

    expect($data['isRebilling'])->toBe('0')
        // Their MIT page asks for the sub-type only "for subsequent MIT payments",
        // and there is nothing before the first for an anchor to name.
        ->and($data)->not->toHaveKey('rebillingType')
        ->and($data)->not->toHaveKey('relatedTransactionId');
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantUnscheduled,
]);

it('declares a recurring payment at the root, with its anchor', function () {
    $data = nuveiRebillingPayload(PaymentInitiation::MerchantRecurring, '1110000000123456');

    expect($data['isRebilling'])->toBe('1')
        ->and($data['rebillingType'])->toBe('Recurring')
        ->and($data['relatedTransactionId'])->toBe('1110000000123456')
        // Not nested: paymentOption carries the instrument, not the chain.
        ->and($data['paymentOption'])->not->toHaveKey('isRebilling');
});

it('calls an unscheduled payment that continues a series MIT rather than Recurring', function () {
    // Same initiation as an opening payment; the anchor is what makes it subsequent.
    // On a subsequent payment the initiation decides the sub-type — whether the
    // series bills on a schedule or on demand.
    $data = nuveiRebillingPayload(PaymentInitiation::MerchantUnscheduled, '1110000000123456');

    expect($data['isRebilling'])->toBe('1')
        ->and($data['rebillingType'])->toBe('MIT')
        ->and($data['relatedTransactionId'])->toBe('1110000000123456');
});

it('sends no rebilling block on a payment outside any series', function () {
    // Outside a series there is no position to declare, and "0" would promise the
    // acquirer renewals of a standalone sale. A PlacementCommand is exactly that, and it is now
    // the type rather than an unset flag that says so — an ordinary authorization has nowhere to
    // put an anchor at all.
    $data = nuveiAuthorizationOf(new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiTestAttachedPaymentMethod(),
        amount: new Money(5000, new Currency('USD')),
        initiation: PaymentInitiation::CardholderInitiated,
    ))->payload();

    expect($data)->not->toHaveKey('isRebilling')
        ->and($data)->not->toHaveKey('rebillingType')
        ->and($data)->not->toHaveKey('relatedTransactionId');
});

it('never lets a renewal call itself the first payment of its series', function () {
    // Every subscription created before the genesis was carried reaches its next
    // renewal in exactly this state: in a series, no anchor. Inside a series a
    // missing anchor otherwise means "this opens it", and a renewal cannot — so it
    // stays subsequent with the reference simply absent, rather than telling the
    // acquirer a fresh series begins on every renewal.
    $data = nuveiRebillingPayload(PaymentInitiation::MerchantRecurring);

    expect($data['isRebilling'])->toBe('1')
        ->and($data['rebillingType'])->toBe('Recurring')
        ->and($data)->not->toHaveKey('relatedTransactionId');
});
