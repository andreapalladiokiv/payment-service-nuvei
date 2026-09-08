<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\RegisterPaymentMethod;

/**
 * A decrypter that returns what it was given, so a test can assert the card
 * fields the operation assembles without standing up encryption.
 */
final readonly class EncryptsToItself implements DecryptInterface, EncryptInterface
{
    public function decrypt(string $value): string
    {
        return $value;
    }

    public function encrypt(string $value): string
    {
        return $value;
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiUpo(PaymentInstrument $instrument, array $overrides = []): RegisterPaymentMethod
{
    return new RegisterPaymentMethod(
        nuveiSuiteSettings($overrides),
        nuveiSuiteInfrastructure([
            'decrypter' => $overrides['decrypter'] ?? new EncryptsToItself,
            'instruments' => nuveiSuiteInstruments($overrides['reference'] ?? null),
        ]),
        new VaultCommand(GatewayId::generate(), $instrument),
        $overrides['customerReference'] ?? 'user@test.com',
    );
}

// ──────────────────────────────────────────────
//  the body, per route
// ──────────────────────────────────────────────

it('builds UPO creation data from token reference', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('476134', '1390', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $data = nuveiUpo($token, ['reference' => 'ccTempToken_abc123'])->payload();

    expect($data['ccTempToken'])->toBe('ccTempToken_abc123')
        ->and($data['userTokenId'])->toBe('user@test.com');
});

it('builds a zero-amount verification from a raw card', function () {
    // The card goes to `payment.do` rather than `addUPOCreditCard.do` because
    // only the payment route answers with the issuer's AVS and CVV verdicts,
    // which is what a lazy registration is performed to learn.
    $card = new CreditCard(
        Number::fromNumber('4761341234561390', new EncryptsToItself),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('T Cardholder'),
        Cvc::fromCvc('123', new EncryptsToItself),
    );

    $data = nuveiUpo($card)->payload();

    expect($data)->not->toHaveKey('ccTempToken')
        ->and($data['userTokenId'])->toBe('user@test.com')
        ->and($data['paymentOption']['card'])->toBe([
            'cardNumber' => '4761341234561390',
            'cardHolderName' => 'T Cardholder',
            'expirationMonth' => '12',
            'expirationYear' => '2030',
            'CVV' => '123',
        ]);
});

it('throws on cash instrument', function () {
    nuveiUpo(new Cash)->payload();
})->throws(ValueError::class, 'Nuvei does not support cash');

// ──────────────────────────────────────────────
//  which endpoint each route reaches
// ──────────────────────────────────────────────

it('converts a temp token through the vault endpoint', function () {
    $calls = [];
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('476134', '1390', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $result = nuveiUpo($token, [
        'reference' => 'ccTempToken_abc123',
        'restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'SUCCESS', 'userPaymentOptionId' => '4242']),
    ])->register();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/addUPOCreditCardByTempToken.do')
        ->and($calls[0]['params']['ccTempToken'])->toBe('ccTempToken_abc123')
        ->and($calls[0]['params']['userTokenId'])->toBe('user@test.com')
        ->and($result->reference)->toBe('4242');
});

it('registers a raw card as a zero-amount Auth marking the credential newly stored', function () {
    // `storedCredentialsMode: '0'` belongs on this call and only this call — it is the moment a
    // credential is stored. It does NOT establish the MIT chain; what marks a chain is
    // `isRebilling`, and that travels on authorizeRebilling.
    $calls = [];
    $card = new CreditCard(
        Number::fromNumber('4761341234561390', new EncryptsToItself),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('T Cardholder'),
        Cvc::fromCvc('123', new EncryptsToItself),
    );

    // This is a LIVE DEFECT, not a quirk of the test setup, and not something the migration
    // introduced — it is carried over from the request class unchanged.
    //
    // `payment.do` marks `deviceDetails` mandatory and the SDK fills it from
    // $_SERVER['REMOTE_ADDR'], which exists under a web request and NOT under CLI. This operation
    // sends none of its own (a payment does; see Concern\PaymentBody), so registering a raw card
    // from a queue worker never leaves the process and fails on "Missing input parameters:
    // deviceDetails". REMOTE_ADDR is set here only so the rest of the body can be asserted at all;
    // remove these three lines and the call below records nothing, which is exactly the bug.
    $previousRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    try {
        nuveiUpo($card, [
            'restClient' => nuveiSuiteRecordingClient($calls, [
                'status' => 'SUCCESS',
                'transactionStatus' => 'APPROVED',
                'paymentOption' => ['userPaymentOptionId' => '9001'],
            ]),
        ])->register();
    } finally {
        if ($previousRemoteAddress === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $previousRemoteAddress;
        }
    }

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/payment.do')
        ->and($calls[0]['params']['transactionType'])->toBe('Auth')
        ->and($calls[0]['params']['amount'])->toBe('0')
        ->and($calls[0]['params']['currency'])->toBe('USD')
        ->and($calls[0]['params']['paymentOption']['card']['storedCredentials'])
        ->toBe(['storedCredentialsMode' => '0']);
});

// ──────────────────────────────────────────────
//  reading either answer
//
//  `addUPOCreditCardByTempToken` answers flat, with `userPaymentOptionId` at the top level. The
//  zero-amount Auth answers like any other payment: the id is nested under `paymentOption`, and
//  the outcome is `transactionStatus` rather than `status`.
// ──────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $reply
 */
function nuveiUpoAnswering(array $reply): RegistrationResult
{
    $calls = [];

    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('476134', '1390', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    return nuveiUpo($token, [
        'reference' => 'ccTempToken_abc123',
        'restClient' => nuveiSuiteRecordingClient($calls, $reply),
    ])->register();
}

it('reads the flat shape the temp-token conversion answers with', function () {
    $result = nuveiUpoAnswering(['status' => 'SUCCESS', 'userPaymentOptionId' => '4242']);

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('4242')
        // A vault write asks the issuer nothing, so there is no verdict to report.
        ->and($result->addressLineCheck)->toBeNull()
        ->and($result->cvcCheck)->toBeNull();
});

it('reads the nested shape the zero-amount verification answers with', function () {
    $result = nuveiUpoAnswering([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'paymentOption' => [
            'userPaymentOptionId' => '9001',
            'card' => ['avsCode' => 'Y', 'cvv2Reply' => 'M'],
        ],
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('9001')
        ->and($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Pass)
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

it('treats a declined verification as a failure', function () {
    // `status` only says Nuvei accepted the request. Reading it alone would take
    // a decline for a registration.
    $result = nuveiUpoAnswering([
        'status' => 'SUCCESS',
        'transactionStatus' => 'DECLINED',
        'gwErrorReason' => 'Insufficient funds',
        'paymentOption' => ['userPaymentOptionId' => '9002'],
    ]);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Insufficient funds');
});

it('fails when the call itself was rejected', function () {
    $result = nuveiUpoAnswering(['status' => 'ERROR', 'reason' => 'Invalid checksum']);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Invalid checksum');
});

it('fails an approved verification that named no payment option', function () {
    expect(nuveiUpoAnswering(['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED'])->success)->toBeFalse();
});

it('reports a split AVS verdict on each field separately', function () {
    // Schemes pack street and postal into one letter; 'A' means the street
    // matched and the postal code did not.
    $result = nuveiUpoAnswering([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'paymentOption' => [
            'userPaymentOptionId' => '9003',
            'card' => ['avsCode' => 'A'],
        ],
    ]);

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBeNull();
});
