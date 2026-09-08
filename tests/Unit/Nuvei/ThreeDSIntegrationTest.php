<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;

/**
 * Where an externally obtained attestation lands in the body, and that it lands in the right place
 * for each instrument shape — Nuvei nests `threeD` under `paymentOption.card` for a temp token and
 * at `paymentOption` level for a stored UPO, because the latter has no `card` key at all.
 *
 * Both placements are exercised through {@see \Techork\PaymentService\Nuvei\Purchase} here and
 * again through {@see \Techork\PaymentService\Nuvei\Authorize}, because the block is assembled once
 * in the shared payment body and a regression there would silently drop the cryptogram from
 * whichever operation was not covered.
 */
function threeDSAttestation(string $cavv, ECICode $eci, string $dsTransactionId): ThreeDSResult
{
    return new ThreeDSResult(
        ThreeDSStatus::Successful,
        $cavv,
        $eci,
        $dsTransactionId,
        'acs-txn-def',
        ThreeDSVersion::V220,
    );
}

// ──────────────────────────────────────────────
//  Token — externalMpi in card paymentOption
// ──────────────────────────────────────────────

it('includes externalMpi in card paymentOption when threeDS present', function () {
    $data = nuveiPurchaseOf(nuveiTestToken(), [
        'money' => new Money(5000, new Currency('USD')),
        'reference' => 'temp_token_xyz',
        'sessionToken' => 'sess_789',
        'customerReference' => 'user@test.com',
        'threeDS' => threeDSAttestation('cavv-value-123', ECICode::VisaSuccessful, 'ds-txn-abc'),
    ])->payload();

    expect($data['paymentOption']['card']['threeD']['externalMpi'])->toBe([
        'eci' => '05',
        'cavv' => 'cavv-value-123',
        'dsTransID' => 'ds-txn-abc',
        'challengePreference' => 'NoPreference',
    ]);
});

// ──────────────────────────────────────────────
//  PaymentMethod — externalMpi at top level
// ──────────────────────────────────────────────

it('includes externalMpi at top level for stored payment method', function () {
    $data = nuveiPurchaseOf(nuveiTestPaymentMethod(), [
        'money' => new Money(2000, new Currency('EUR')),
        'reference' => 'upo_99999',
        'sessionToken' => 'sess_pm',
        'threeDS' => threeDSAttestation('cavv-pm-value', ECICode::MastercardSuccessful, 'ds-txn-pm'),
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

// ──────────────────────────────────────────────
//  No threeDS — no threeD block
// ──────────────────────────────────────────────

it('excludes threeD block when threeDS is null', function () {
    $data = nuveiPurchaseOf(nuveiTestToken(), [
        'money' => new Money(3000, new Currency('USD')),
        'reference' => 'temp_token_no3ds',
        'sessionToken' => 'sess_no3ds',
        'customerReference' => 'user@test.com',
    ])->payload();

    expect($data['paymentOption']['card'])->not->toHaveKey('threeD')
        ->and($data['paymentOption'])->not->toHaveKey('threeD');
});

// ──────────────────────────────────────────────
//  the hold carries the same attestation as the sale
//
//  The block is built once for both, so this is what stops the two operations drifting apart.
// ──────────────────────────────────────────────

it('carries the attestation on an authorization exactly as on a sale', function () {
    $threeDS = threeDSAttestation('cavv-auth', ECICode::VisaSuccessful, 'ds-txn-auth');

    $authorized = nuveiAuthorizationOf(new Techork\PaymentService\Gateway\Command\PlacementCommand(
        gatewayId: Techork\PaymentService\Gateway\ValueObject\GatewayId::generate(),
        instrument: nuveiTestToken(),
        amount: new Money(5000, new Currency('USD')),
        threeDS: $threeDS,
    ), ['reference' => 'temp_token_auth'])->payload();

    expect($authorized['paymentOption']['card']['threeD']['externalMpi'])->toBe([
        'eci' => '05',
        'cavv' => 'cavv-auth',
        'dsTransID' => 'ds-txn-auth',
        'challengePreference' => 'NoPreference',
    ]);
});
