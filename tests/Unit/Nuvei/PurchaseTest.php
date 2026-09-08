<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Nuvei\Purchase;

/*
| {@see Purchase} takes a payment outright, by either of the two routes Nuvei offers: an ordinary
| instrument goes to `payment.do` as a `Sale`, and a hosted payment produces the Cashier form the
| buyer's browser posts instead, with no REST call made at all.
|
| The operation is built here the way the gateway builds it — settings, infrastructure, command,
| resolved customer reference — through the shared `nuveiPurchaseOf()` helper.
*/

it('builds purchase data for token with ccTempToken', function () {
    $data = nuveiPurchaseOf(nuveiTestToken(), [
        'reference' => 'temp_token_abc',
        'sessionToken' => 'sess_123',
        'customerReference' => 'user@test.com',
    ])->payload();

    expect($data['amount'])->toBe('25.00')
        ->and($data['currency'])->toBe('USD')
        ->and($data['sessionToken'])->toBe('sess_123')
        ->and($data['userTokenId'])->toBe('user@test.com')
        ->and($data['transactionType'])->toBe('Sale')
        // A sale settles in the same message, so no settle type is declared and the acquirer's
        // default never comes into it.
        ->and($data)->not->toHaveKey('settleType')
        ->and($data['paymentOption']['card']['ccTempToken'])->toBe('temp_token_abc')
        ->and($data)->toHaveKey('deviceDetails')
        ->and($data)->toHaveKey('clientRequestId');
});

it('includes dynamicDescriptor.merchantName when statementDescription is set', function () {
    expect(nuveiPurchaseOf(nuveiTestToken(), [
        'reference' => 'temp_token_abc',
        'statementDescription' => 'ACME Trip 42',
    ])->payload()['dynamicDescriptor'])->toBe(['merchantName' => 'ACME Trip 42']);
});

it('omits dynamicDescriptor when statementDescription is null or empty', function () {
    expect(nuveiPurchaseOf(nuveiTestToken(), ['reference' => 'temp_token_abc', 'statementDescription' => ''])->payload())
        ->not->toHaveKey('dynamicDescriptor')
        ->and(nuveiPurchaseOf(nuveiTestToken(), ['reference' => 'temp_token_abc'])->payload())
        ->not->toHaveKey('dynamicDescriptor');
});

it('omits userTokenId when no customer reference is resolved', function () {
    // Omit, don't send '': Nuvei rejects an empty userTokenId outright, while a payment without
    // one is valid for non-stored instruments.
    expect(nuveiPurchaseOf(nuveiTestToken(), ['reference' => 'temp_token_abc'])->payload())
        ->not->toHaveKey('userTokenId');
});

it('uses one id for clientUniqueId and clientRequestId even when generated', function () {
    $data = nuveiPurchaseOf(nuveiTestToken(), ['reference' => 'temp_token_abc'])->payload();

    expect($data['clientUniqueId'])->toBe($data['clientRequestId']);
});

it('pays by UPO alone, sending no stored-credential marker of its own', function () {
    $data = nuveiPurchaseOf(nuveiTestPaymentMethod(), [
        'money' => new Money(1000, new Currency('EUR')),
        'reference' => 'upo_12345',
    ])->payload();

    // storedCredentialsMode used to ride along on every stored instrument, deriving a
    // stored-credential claim from the instrument's SHAPE. Their reference says
    // tokenization users should not send it, and what marks a chain is `isRebilling` —
    // which travels on authorizeRebilling, not here.
    expect($data['paymentOption']['userPaymentOptionId'])->toBe('upo_12345')
        ->and($data['paymentOption'])->not->toHaveKey('storedCredentials')
        ->and($data)->not->toHaveKey('isRebilling');
});

it('throws on credit card instrument', function () {
    nuveiPurchaseOf(nuveiTestCard())->payload();
})->throws(UnsupportedInstrument::class, 'does not accept a "card" instrument on the "purchase" operation');

it('throws on cash instrument', function () {
    nuveiPurchaseOf(new Cash)->payload();
})->throws(UnsupportedInstrument::class, 'does not accept a "cash" instrument on the "purchase" operation');

it('includes externalMpi in card paymentOption when threeDS is present', function () {
    $data = nuveiPurchaseOf(nuveiTestToken(), [
        'money' => new Money(5000, new Currency('USD')),
        'reference' => 'temp_token_xyz',
        'customerReference' => 'user@test.com',
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-value-123',
            ECICode::VisaSuccessful,
            'ds-txn-abc',
            'acs-txn-def',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    expect($data['paymentOption']['card']['threeD']['externalMpi'])->toBe([
        'eci' => '05',
        'cavv' => 'cavv-value-123',
        'dsTransID' => 'ds-txn-abc',
        'challengePreference' => 'NoPreference',
    ]);
});

it('includes externalMpi at top level for stored PM when threeDS is present', function () {
    $data = nuveiPurchaseOf(nuveiTestPaymentMethod(), [
        'money' => new Money(2000, new Currency('EUR')),
        'reference' => 'upo_99999',
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-pm-value',
            ECICode::MastercardSuccessful,
            'ds-txn-pm',
            'acs-txn-pm',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    // PM paymentOption has no 'card' key, so threeD goes at paymentOption level
    expect($data['paymentOption']['threeD']['externalMpi'])->toBe([
        'eci' => '02',
        'cavv' => 'cavv-pm-value',
        'dsTransID' => 'ds-txn-pm',
        'challengePreference' => 'NoPreference',
    ])
        ->and($data['paymentOption'])->not->toHaveKey('card');
});

it('excludes threeD block when threeDS is null', function () {
    $data = nuveiPurchaseOf(nuveiTestToken(), [
        'money' => new Money(3000, new Currency('USD')),
        'reference' => 'temp_token_no3ds',
        'customerReference' => 'user@test.com',
    ])->payload();

    expect($data['paymentOption']['card'])->not->toHaveKey('threeD')
        ->and($data['paymentOption'])->not->toHaveKey('threeD');
});

// ──────────────────────────────────────────────
//  Incomplete attestations are refused, not posted
//
//  Nuvei marks eci, cavv and dsTransID all Required inside externalMpi. Before
//  this guard a result without an ECI dereferenced null and died with a PHP
//  Error mid-request; dropping the key instead would have posted a body Nuvei
//  rejects, and the rejection would have been recorded as an issuer decline.
// ──────────────────────────────────────────────

it('refuses a 3DS attestation with no ECI rather than posting an invalid externalMpi', function () {
    nuveiPurchaseOf(nuveiTestToken(), [
        'money' => new Money(5000, new Currency('USD')),
        'reference' => 'temp_token_xyz',
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-value-123',
            null,
            'ds-txn-abc',
            'acs-txn-def',
            ThreeDSVersion::V220,
        ),
    ])->payload();
})->throws(IncompleteAuthentication::class, 'missing eci');

it('refuses an attestation that carries neither cavv nor eci, naming both', function () {
    nuveiPurchaseOf(nuveiTestToken(), [
        'money' => new Money(5000, new Currency('USD')),
        'reference' => 'temp_token_xyz',
        // NotAuthenticated carries no authentication value and no ECI — the
        // shape an app hands in when it forwards a failed authentication.
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::NotAuthenticated,
            null,
            null,
            'ds-txn-abc',
            'acs-txn-def',
            ThreeDSVersion::V220,
        ),
    ])->payload();
})->throws(IncompleteAuthentication::class, 'missing eci, cavv');

it('marks an incomplete attestation as a wiring error, not an acquirer decline', function () {
    expect(is_subclass_of(IncompleteAuthentication::class, UnsupportedByGateway::class))->toBeTrue();
});

// ──────────────────────────────────────────────
//  the hosted Cashier route
//
//  No REST call is made at all: the browser POSTs the returned form to purchase.do, Nuvei collects
//  the card data server-side, and the outcome arrives asynchronously through the DMN webhook.
// ──────────────────────────────────────────────

it('builds hosted Cashier form data for HostedPayment instrument', function () {
    $piId = '550e8400-e29b-41d4-a716-446655440000';

    $data = nuveiPurchaseOf(
        new HostedPayment(
            successUrl: 'https://merchant.example/success',
            cancelUrl: 'https://merchant.example/cancel',
        ),
        [
            'money' => new Money(1050, new Currency('USD')),
            'merchantId' => 'mid_123',
            'merchantSiteId' => 'sid_456',
            'secretKey' => 'sek_789',
            'environment' => 'int',
            'clientUniqueId' => $piId,
        ],
    )->payload();

    expect($data['cashier_url'])->toBe('https://ppp-test.nuvei.com/ppp/purchase.do')
        ->and($data['reference'])->toBe($piId);

    $form = $data['form_fields'];
    expect($form['merchant_id'])->toBe('mid_123')
        ->and($form['merchant_site_id'])->toBe('sid_456')
        ->and($form['total_amount'])->toBe('10.50')
        ->and($form['currency'])->toBe('USD')
        ->and($form['version'])->toBe('4.0.0')
        ->and($form['success_url'])->toBe('https://merchant.example/success')
        ->and($form['error_url'])->toBe('https://merchant.example/cancel')
        ->and($form['back_url'])->toBe('https://merchant.example/cancel')
        ->and($form['clientUniqueId'])->toBe($piId)
        ->and($form)->toHaveKey('checksum')
        ->and($form)->toHaveKey('time_stamp');

    $expectedChecksum = hash('sha256', implode('', [
        'mid_123', 'sid_456', '10.50', 'USD', $form['time_stamp'], 'sek_789',
    ]));
    expect($form['checksum'])->toBe($expectedChecksum);
});

it('uses production Cashier URL when environment is live', function () {
    expect(nuveiPurchaseOf(new HostedPayment('https://m/s', 'https://m/c'), [
        'money' => new Money(100, new Currency('USD')),
        'merchantId' => 'm',
        'merchantSiteId' => 's',
        'secretKey' => 'k',
        'environment' => 'live',
        'clientUniqueId' => '550e8400-e29b-41d4-a716-446655440000',
    ])->payload()['cashier_url'])->toBe('https://secure.safecharge.com/ppp/purchase.do');
});

it('charging a hosted payment bypasses the Nuvei API and answers with a RedirectChallenge', function () {
    // A hosted payment is `requires_action` by construction: nothing has been asked of an acquirer
    // yet, the buyer has merely been sent somewhere. The reference is our own clientUniqueId,
    // because Nuvei has issued no transaction id at this point — the DMN supplies one later and
    // correlates by exactly this value, which is also why it is recorded as the opening reference.
    $piId = '550e8400-e29b-41d4-a716-446655440000';

    $result = nuveiPurchaseOf(new HostedPayment('https://m/s', 'https://m/c'), [
        'money' => new Money(100, new Currency('USD')),
        'merchantId' => 'm',
        'merchantSiteId' => 's',
        'secretKey' => 'k',
        'environment' => 'int',
        'clientUniqueId' => $piId,
        // Unreachable on purpose: a hosted charge must not touch the REST API at all.
        'restClient' => nuveiSuiteUnreachableClient(),
    ])->charge();

    expect($result->isRequiresAction())->toBeTrue()
        ->and($result->reference)->toBe($piId)
        ->and($result->challenge)->toBeInstanceOf(RedirectChallenge::class)
        ->and($result->challenge->url)->toBe('https://ppp-test.nuvei.com/ppp/purchase.do')
        ->and($result->challenge->formFields)->toHaveKey('merchant_id')
        ->and($result->challenge->formFields)->toHaveKey('checksum')
        ->and($result->metadata)->toBe(['opening_transaction_reference' => $piId]);
});

it('sends the sale to payment.do and maps what came back', function () {
    $calls = [];

    $result = nuveiPurchaseOf(nuveiTestToken(), [
        'reference' => 'temp_token_abc',
        'sessionToken' => 'sess_send',
        'restClient' => nuveiSuiteRecordingClient($calls, [
            'status' => 'SUCCESS',
            'transactionStatus' => 'APPROVED',
            'transactionId' => 'txn-sale',
        ]),
    ])->charge();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/payment.do')
        ->and($calls[0]['params']['transactionType'])->toBe('Sale')
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe('txn-sale');
});
