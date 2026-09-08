<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\Payout;

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiPayout(?PaymentInstrument $retryInstrument, array $overrides = []): Payout
{
    return new Payout(
        nuveiSuiteSettings($overrides),
        nuveiSuiteInfrastructure(['instruments' => nuveiSuiteInstruments($overrides['reference'] ?? null)]),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $overrides['transactionReference'] ?? 'txn-original',
            amount: $overrides['money'] ?? new Money(2500, new Currency('USD')),
            retryInstrument: $retryInstrument,
        ),
        $overrides['customerReference'] ?? 'cust@test',
    );
}

it('builds payout data for a Token via userPaymentOptionId', function () {
    $token = new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-000000000200'),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Alt Holder'),
            new Cvc,
        ),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $data = nuveiPayout($token, ['reference' => 'nuvei-upo-77'])->payload();

    expect($data['amount'])->toBe('25.00')
        ->and($data['currency'])->toBe('USD')
        ->and($data['userTokenId'])->toBe('cust@test')
        ->and($data['userPaymentOption'])->toBe(['userPaymentOptionId' => 'nuvei-upo-77'])
        ->and($data)->toHaveKeys(['clientUniqueId', 'clientRequestId']);
});

it('builds payout data for a PaymentMethod via userPaymentOptionId', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::fromString('01961f5a-0000-7000-8000-000000000201'),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Alt Holder'),
            new Cvc,
        ),
        new BillingAddress(
            firstName: 'Alt',
            lastName: 'Holder',
            line: '1 St',
            city: 'NYC',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );

    expect(nuveiPayout($pm, ['reference' => 'nuvei-upo-99'])->payload()['userPaymentOption'])
        ->toBe(['userPaymentOptionId' => 'nuvei-upo-99']);
});

it('rejects raw credit cards (PCI scope)', function () {
    $card = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Alt Holder'),
        new Cvc,
    );

    nuveiPayout($card)->payload();
})->throws(RuntimeException::class, 'Nuvei payout does not accept raw card data');

/**
 * A refund with no alternative instrument is not a payout at all — there is nowhere to send the
 * money. It used to surface as omnipay's "The instrument parameter is required" from a `validate()`
 * call; now it names what is actually missing and from where.
 */
it('refuses a retry that names no alternative instrument', function () {
    nuveiPayout(null)->payload();
})->throws(RuntimeException::class, 'the refund command carried none');

it('signs the payout and posts it to payout.do', function () {
    // The checksum covers a timestamp taken at call time, which is why it is not part of
    // payload() — but it does have to reach the wire, along with the merchant identity the SDK
    // client was configured with, or Nuvei rejects the call outright.
    $calls = [];
    $token = new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-000000000200'),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Alt Holder'),
            new Cvc,
        ),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $result = nuveiPayout($token, [
        'reference' => 'nuvei-upo-77',
        'restClient' => nuveiSuiteRecordingClient($calls, [
            'status' => 'SUCCESS',
            'transactionStatus' => 'APPROVED',
            'transactionId' => 'payout-1',
        ]),
    ])->payout();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/payout.do')
        ->and($calls[0]['params']['merchantId'])->toBe('mid-suite')
        ->and($calls[0]['params']['merchantSiteId'])->toBe('site-suite')
        ->and($calls[0]['params'])->toHaveKeys(['timeStamp', 'checksum'])
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe('payout-1')
        // A payout opens nothing — it is the tail of a refund, not a placement — so it records no
        // opening reference to bury the original sale's under.
        ->and($result->metadata)->toBe([]);
});
