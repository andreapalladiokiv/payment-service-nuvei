<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Nuvei\PurchaseRequest;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function nuveiCredential(): GatewayCredential
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

function nuveiRefResolver(string $ref, array $metadata = []): GatewayInstrumentRepository
{
    $mock = Mockery::mock(GatewayInstrumentRepository::class);
    $mock->shouldReceive('find')->andReturn($ref);
    $mock->shouldReceive('findMetadata')->andReturn($metadata);

    return $mock;
}

function nuveiTestCard(): CreditCard
{
    return new CreditCard(
        new Number('476134', '1390', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );
}

it('builds purchase data for token with ccTempToken', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_abc'),
        'sessionToken' => 'sess_123',
        'customerReference' => 'user@test.com',
    ]);

    $data = $request->getData();

    expect($data['amount'])->toBe('25.00')
        ->and($data['currency'])->toBe('USD')
        ->and($data['sessionToken'])->toBe('sess_123')
        ->and($data['userTokenId'])->toBe('user@test.com')
        ->and($data['paymentOption']['card']['ccTempToken'])->toBe('temp_token_abc')
        ->and($data)->toHaveKey('deviceDetails')
        ->and($data)->toHaveKey('clientRequestId');
});

it('includes dynamicDescriptor.merchantName when statementDescription is set', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_abc'),
        'sessionToken' => 'sess_123',
        'statementDescription' => 'ACME Trip 42',
    ]);

    expect($request->getData()['dynamicDescriptor'])->toBe(['merchantName' => 'ACME Trip 42']);
});

it('omits dynamicDescriptor when statementDescription is null or empty', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_abc'),
        'sessionToken' => 'sess_123',
        'statementDescription' => '',
    ]);

    expect($request->getData())->not->toHaveKey('dynamicDescriptor');
});

it('omits userTokenId when no customer reference is resolved', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_abc'),
        'sessionToken' => 'sess_123',
        'customerReference' => '',
    ]);

    expect($request->getData())->not->toHaveKey('userTokenId');
});

it('uses one id for clientUniqueId and clientRequestId even when generated', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_abc'),
        'sessionToken' => 'sess_123',
    ]);

    $data = $request->getData();

    expect($data['clientUniqueId'])->toBe($data['clientRequestId']);
});

it('builds purchase data for payment method with UPO and storedCredentials', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        nuveiTestCard(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('EUR')),
        'instrument' => $pm,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('upo_12345'),
        'sessionToken' => 'sess_456',
    ]);

    $data = $request->getData();

    expect($data['paymentOption']['userPaymentOptionId'])->toBe('upo_12345')
        ->and($data['paymentOption']['storedCredentials']['storedCredentialsMode'])->toBe('1');
});

it('throws on credit card instrument', function () {
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => nuveiTestCard(),
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'sessionToken' => 'sess',
    ]);

    $request->getData();
})->throws(UnsupportedInstrument::class, 'does not accept a "card" instrument on the "purchase" operation');

it('throws on cash instrument', function () {
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'sessionToken' => 'sess',
    ]);

    $request->getData();
})->throws(UnsupportedInstrument::class, 'does not accept a "cash" instrument on the "purchase" operation');

it('includes externalMpi in card paymentOption when threeDS is present', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
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
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_xyz'),
        'sessionToken' => 'sess_789',
        'customerReference' => 'user@test.com',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    expect($data['paymentOption']['card']['threeD']['externalMpi'])->toBe([
        'eci' => '05',
        'cavv' => 'cavv-value-123',
        'dsTransID' => 'ds-txn-abc',
        'challengePreference' => 'NoPreference',
    ]);
});

it('includes externalMpi at top level for stored PM when threeDS is present', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        nuveiTestCard(),
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
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('upo_99999'),
        'sessionToken' => 'sess_pm',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    // PM paymentOption has no 'card' key, so threeD goes at paymentOption level
    expect($data['paymentOption']['threeD']['externalMpi'])->toBe([
        'eci' => '02',
        'cavv' => 'cavv-pm-value',
        'dsTransID' => 'ds-txn-pm',
        'challengePreference' => 'NoPreference',
    ])
        ->and($data['paymentOption'])->not->toHaveKey('card');
});

it('builds hosted Cashier form data for HostedPayment instrument', function () {
    $hosted = new HostedPayment(
        successUrl: 'https://merchant.example/success',
        cancelUrl: 'https://merchant.example/cancel',
    );

    $piId = '550e8400-e29b-41d4-a716-446655440000';

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1050, new Currency('USD')),
        'instrument' => $hosted,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'merchantId' => 'mid_123',
        'merchantSiteId' => 'sid_456',
        'secretKey' => 'sek_789',
        'environment' => 'int',
        'clientUniqueId' => $piId,
    ]);

    $data = $request->getData();

    expect($data['_hosted'])->toBeTrue()
        ->and($data['cashier_url'])->toBe('https://ppp-test.nuvei.com/ppp/purchase.do')
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
    $hosted = new HostedPayment('https://m/s', 'https://m/c');

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(100, new Currency('USD')),
        'instrument' => $hosted,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'merchantId' => 'm', 'merchantSiteId' => 's', 'secretKey' => 'k',
        'environment' => 'live',
        'clientUniqueId' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    expect($request->getData()['cashier_url'])->toBe('https://secure.safecharge.com/ppp/purchase.do');
});

it('sendData on hosted bypasses Nuvei API and returns RedirectChallenge', function () {
    $hosted = new HostedPayment('https://m/s', 'https://m/c');

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(100, new Currency('USD')),
        'instrument' => $hosted,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'merchantId' => 'm', 'merchantSiteId' => 's', 'secretKey' => 'k',
        'environment' => 'int',
        'clientUniqueId' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $response = $request->send();

    expect($response->getTransactionReference())->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($response->getChallenge())->toBeInstanceOf(RedirectChallenge::class)
        ->and($response->getChallenge()->url)->toBe('https://ppp-test.nuvei.com/ppp/purchase.do')
        ->and($response->getChallenge()->formFields)->toHaveKey('merchant_id')
        ->and($response->getChallenge()->formFields)->toHaveKey('checksum');
});

it('excludes threeD block when threeDS is null', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_no3ds'),
        'sessionToken' => 'sess_no3ds',
        'customerReference' => 'user@test.com',
    ]);

    $data = $request->getData();

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
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_xyz'),
        'sessionToken' => 'sess_789',
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-value-123',
            null,
            'ds-txn-abc',
            'acs-txn-def',
            ThreeDSVersion::V220,
        ),
    ]);

    $request->getData();
})->throws(IncompleteAuthentication::class, 'missing eci');

it('refuses an attestation that carries neither cavv nor eci, naming both', function () {
    $token = new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => nuveiCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiRefResolver('temp_token_xyz'),
        'sessionToken' => 'sess_789',
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
    ]);

    $request->getData();
})->throws(IncompleteAuthentication::class, 'missing eci, cavv');

it('marks an incomplete attestation as a wiring error, not an acquirer decline', function () {
    expect(is_subclass_of(IncompleteAuthentication::class, UnsupportedByGateway::class))->toBeTrue();
});
