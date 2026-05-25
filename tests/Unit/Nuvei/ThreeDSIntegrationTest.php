<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
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
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Nuvei\PurchaseRequest;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function threeDSNuveiCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Nuvei'; }
        public function getCredentials(): array { return []; }
    };
}

function threeDSNuveiRefResolver(string $ref): GatewayInstrumentRepository
{
    $mock = Mockery::mock(GatewayInstrumentRepository::class);
    $mock->shouldReceive('find')->andReturn($ref);
    $mock->shouldReceive('findMetadata')->andReturn([]);

    return $mock;
}

function threeDSNuveiTestCard(): CreditCard
{
    return new CreditCard(
        new Number('476134', '1390', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );
}

// ──────────────────────────────────────────────
//  Token — externalMpi in card paymentOption
// ──────────────────────────────────────────────

it('includes externalMpi in card paymentOption when threeDS present', function () {
    $token = new Token(
        TokenId::generate(),
        threeDSNuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-value-123',
        ECICode::VisaSuccessful,
        'ds-txn-abc',
        'acs-txn-def',
        ThreeDSVersion::V220,
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => threeDSNuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => threeDSNuveiRefResolver('temp_token_xyz'),
        'sessionToken' => 'sess_789',
        'customerReference' => 'user@test.com',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    expect($data['paymentOption']['card']['threeD']['externalMpi'])->toBe([
        'eci' => '05',
        'cavv' => 'cavv-value-123',
        'dsTransID' => 'ds-txn-abc',
    ]);
});

// ──────────────────────────────────────────────
//  PaymentMethod — externalMpi at top level
// ──────────────────────────────────────────────

it('includes externalMpi at top level for stored payment method', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        threeDSNuveiTestCard(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-pm-value',
        ECICode::MastercardSuccessful,
        'ds-txn-pm',
        'acs-txn-pm',
        ThreeDSVersion::V220,
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2000, new Currency('EUR')),
        'instrument' => $pm,
        'gateway' => threeDSNuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => threeDSNuveiRefResolver('upo_99999'),
        'sessionToken' => 'sess_pm',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    // PM paymentOption has no 'card' key, so threeD goes at paymentOption level
    expect($data['paymentOption']['threeD']['externalMpi'])->toBe([
        'eci' => '02',
        'cavv' => 'cavv-pm-value',
        'dsTransID' => 'ds-txn-pm',
    ])
        ->and($data['paymentOption'])->not->toHaveKey('card');
});

// ──────────────────────────────────────────────
//  No threeDS — no threeD block
// ──────────────────────────────────────────────

it('excludes threeD block when threeDS is null', function () {
    $token = new Token(
        TokenId::generate(),
        threeDSNuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => threeDSNuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => threeDSNuveiRefResolver('temp_token_no3ds'),
        'sessionToken' => 'sess_no3ds',
        'customerReference' => 'user@test.com',
    ]);

    $data = $request->getData();

    expect($data['paymentOption']['card'])->not->toHaveKey('threeD')
        ->and($data['paymentOption'])->not->toHaveKey('threeD');
});
