<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
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
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Nuvei\PayoutRequest;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function nuveiPayoutCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'nuvei';
        }

        public function getCredentials(): array
        {
            return [];
        }
    };
}

function nuveiPayoutResolver(string $ref): GatewayInstrumentRepository
{
    $mock = Mockery::mock(GatewayInstrumentRepository::class);
    $mock->shouldReceive('find')->andReturn($ref);

    return $mock;
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

    $request = new PayoutRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiPayoutCredential(),
        'referenceResolver' => nuveiPayoutResolver('nuvei-upo-77'),
        'customerReference' => 'cust@test',
    ]);

    $data = $request->getData();

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

    $request = new PayoutRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => nuveiPayoutCredential(),
        'referenceResolver' => nuveiPayoutResolver('nuvei-upo-99'),
        'customerReference' => 'cust@test',
    ]);

    expect($request->getData()['userPaymentOption'])
        ->toBe(['userPaymentOptionId' => 'nuvei-upo-99']);
});

it('rejects raw credit cards (PCI scope)', function () {
    $card = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Alt Holder'),
        new Cvc,
    );

    $request = new PayoutRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $card,
        'gateway' => nuveiPayoutCredential(),
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Nuvei payout does not accept raw card data');
