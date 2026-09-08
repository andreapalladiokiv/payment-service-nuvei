<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Nuvei\NuveiTransactionOutcome;

/**
 * {@see NuveiTransactionOutcome} reads a Nuvei transaction answer — every one of payment.do,
 * settleTransaction.do, refundTransaction.do and voidTransaction.do replies in the same shape —
 * and says what it means.
 *
 * It answers with the typed results directly. There is no response object implementing
 * `CardChecksProvider` / `ChallengeProvider` / `ConvertedAmountProvider` for a shared assembler to
 * interrogate, so the tests that used to assert those interfaces were satisfied now assert the
 * signals landed on the result — which is what those interfaces existed to carry.
 */
function makeNuveiOutcome(array $data): NuveiTransactionOutcome
{
    return new NuveiTransactionOutcome($data);
}

it('returns null for all checks when paymentOption.card data absent', function () {
    $outcome = makeNuveiOutcome(['status' => 'SUCCESS', 'transactionId' => '1']);

    expect($outcome->addressLineCheck())->toBeNull()
        ->and($outcome->postalCodeCheck())->toBeNull()
        ->and($outcome->cvcCheck())->toBeNull();
});

it('decomposes a Y AVS letter into (Pass, Pass) for line + postal', function () {
    $outcome = makeNuveiOutcome([
        'paymentOption' => ['card' => ['avsCode' => 'Y']],
    ]);

    expect($outcome->addressLineCheck())->toBe(CheckResult::Pass)
        ->and($outcome->postalCodeCheck())->toBe(CheckResult::Pass);
});

it('decomposes A AVS letter into (Pass, Fail) — street match, postal mismatch', function () {
    $outcome = makeNuveiOutcome([
        'paymentOption' => ['card' => ['avsCode' => 'A']],
    ]);

    expect($outcome->addressLineCheck())->toBe(CheckResult::Pass)
        ->and($outcome->postalCodeCheck())->toBe(CheckResult::Fail);
});

it('decomposes Z AVS letter into (Fail, Pass) — postal match, street mismatch', function () {
    $outcome = makeNuveiOutcome([
        'paymentOption' => ['card' => ['avsCode' => 'Z']],
    ]);

    expect($outcome->addressLineCheck())->toBe(CheckResult::Fail)
        ->and($outcome->postalCodeCheck())->toBe(CheckResult::Pass);
});

it('maps CVV M to Pass and N to Fail', function () {
    expect(makeNuveiOutcome(['paymentOption' => ['card' => ['cvv2Reply' => 'M']]])->cvcCheck())
        ->toBe(CheckResult::Pass)
        ->and(makeNuveiOutcome(['paymentOption' => ['card' => ['cvv2Reply' => 'N']]])->cvcCheck())
        ->toBe(CheckResult::Fail);
});

it('maps CVV S to Fail (protocol violation, real signal)', function () {
    expect(makeNuveiOutcome(['paymentOption' => ['card' => ['cvv2Reply' => 'S']]])->cvcCheck())
        ->toBe(CheckResult::Fail);
});

it('returns null for cvc when cvv2Reply is empty string', function () {
    expect(makeNuveiOutcome(['paymentOption' => ['card' => ['cvv2Reply' => '']]])->cvcCheck())->toBeNull();
});

it('carries the card checks onto the authorization result', function () {
    // What CardChecksProvider was for. The signals reach the caller on the result now rather than
    // through an interface a shared assembler had to test for.
    $result = makeNuveiOutcome([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'transactionId' => '7110000000000000003',
        'paymentOption' => ['card' => ['avsCode' => 'A', 'cvv2Reply' => 'M']],
    ])->toAuthorizationResult();

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

it('parses the FX-settled amount from the DCC currencyConversion block', function () {
    $outcome = makeNuveiOutcome([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'transactionId' => '7110000000000000001',
        'currencyConversion' => [
            'convertedCurrency' => 'SGD',
            'convertedAmount' => '76.09',
            'originalAmount' => '50.00',
            'originalCurrencyCode' => 'USD',
            'rate' => '1.52181',
        ],
    ]);

    expect($outcome->convertedAmount())->toEqual(new Money(7609, new Currency('SGD')))
        // And it reaches the caller, which is the only reason the block is read at all.
        ->and($outcome->toAuthorizationResult()->convertedAmount)->toEqual(new Money(7609, new Currency('SGD')));
});

it('returns null convertedAmount when no currencyConversion block is present', function () {
    $outcome = makeNuveiOutcome([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'transactionId' => '7110000000000000002',
    ]);

    expect($outcome->convertedAmount())->toBeNull();
});

it('returns null convertedAmount when the conversion block lacks amount or currency', function () {
    expect(makeNuveiOutcome(['currencyConversion' => ['convertedCurrency' => 'SGD', 'convertedAmount' => '']])->convertedAmount())
        ->toBeNull()
        ->and(makeNuveiOutcome(['currencyConversion' => ['convertedAmount' => '76.09']])->convertedAmount())
        ->toBeNull();
});

// ──────────────────────────────────────────────
//  folding an answer into a result
// ──────────────────────────────────────────────

it('records which transaction opened the intent, on placements and on nothing else', function () {
    // `reference` is overwritten on transition, so once a capture lands the row holds the settle
    // reference. Nuvei's settle expects the authorization's transactionId and its refund expects
    // the settle's, so both readings have to survive — and a capture writing this key would bury
    // the authorization's under its own.
    $payload = ['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED', 'transactionId' => 'txn-9'];

    expect(makeNuveiOutcome($payload)->toAuthorizationResult()->metadata)
        ->toBe(['opening_transaction_reference' => 'txn-9'])
        ->and(makeNuveiOutcome($payload)->toGatewayResult()->metadata)->toBe([]);
});

it('reports a challenge before it reports success', function () {
    // A payment awaiting a step-up is neither successful nor failed. Reading success first would
    // drop the challenge of an answer that carries both, leaving the cardholder nowhere to go.
    $result = makeNuveiOutcome([
        'status' => 'SUCCESS',
        'transactionStatus' => 'REDIRECT',
        'transactionId' => 'txn-3ds',
        'paymentOption' => ['card' => ['threeD' => ['acsUrl' => 'https://acs.example/step-up', 'cReq' => 'creq-blob']]],
    ])->toAuthorizationResult();

    expect($result->isRequiresAction())->toBeTrue()
        ->and($result->challenge)->toBeInstanceOf(ThreeDSChallenge::class)
        ->and($result->challenge->url)->toBe('https://acs.example/step-up')
        ->and($result->reference)->toBe('txn-3ds');
});

it('reads the fingerprinting step as a challenge too', function () {
    // `methodUrl` comes first in Nuvei's own flow and was not read at all once, so the device
    // fingerprinting step never reached a client — which costs frictionless approvals, since that
    // step is what the issuer's risk decision is made on.
    $result = makeNuveiOutcome([
        'status' => 'SUCCESS',
        'transactionId' => 'txn-fp',
        'paymentOption' => ['card' => ['threeD' => ['methodUrl' => 'https://acs.example/3ds-method', 'methodPayload' => 'blob']]],
    ])->toAuthorizationResult();

    expect($result->isRequiresAction())->toBeTrue()
        ->and($result->challenge->url)->toBe('https://acs.example/3ds-method')
        ->and($result->challenge->payload)->toBe('blob');
});

it('fails an unsuccessful answer with the reason Nuvei gave', function () {
    expect(makeNuveiOutcome(['status' => 'ERROR', 'reason' => 'Insufficient funds'])->toGatewayResult())
        ->success->toBeFalse()
        ->message->toBe('Insufficient funds');
});

it('prefers the reason, then the gateway reason, then the error code', function () {
    expect(makeNuveiOutcome(['reason' => 'r', 'gwErrorReason' => 'g', 'errCode' => '5'])->message())->toBe('r')
        ->and(makeNuveiOutcome(['gwErrorReason' => 'g', 'errCode' => '5'])->message())->toBe('g')
        ->and(makeNuveiOutcome(['errCode' => '5'])->message())->toBe('Error code: 5')
        // '0' is Nuvei's marker for no error, so there is nothing to report.
        ->and(makeNuveiOutcome(['errCode' => '0'])->message())->toBeNull()
        ->and(makeNuveiOutcome([])->message())->toBeNull();
});

it('refuses to report a success that names no transaction', function () {
    // A success that identifies nothing is unreachable afterwards: the reference is the only handle
    // the ports get, so nothing could capture, cancel or refund it. Empty counts as absent.
    $approvedWithoutId = ['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED', 'transactionId' => ''];

    expect(makeNuveiOutcome($approvedWithoutId)->toGatewayResult())
        ->success->toBeFalse()
        ->message->toBe(GatewayResult::UNNAMED_SUCCESS)
        ->and(makeNuveiOutcome($approvedWithoutId)->toAuthorizationResult())
        ->success->toBeFalse()
        ->message->toBe(GatewayResult::UNNAMED_SUCCESS);
});

it('turns a call that never left into a failure carrying its reason', function () {
    expect(NuveiTransactionOutcome::fromThrowable(new RuntimeException('Connection timed out'))->toAuthorizationResult())
        ->success->toBeFalse()
        ->message->toBe('Connection timed out');
});
