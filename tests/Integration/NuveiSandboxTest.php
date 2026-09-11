<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Nuvei\Api\Environment;
use Nuvei\Api\RestClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Authorize;
use Techork\PaymentService\Nuvei\Capture;
use Techork\PaymentService\Nuvei\NuveiSettings;
use Techork\PaymentService\Nuvei\Refund;
use Techork\PaymentService\Nuvei\RegisterPaymentMethod;
use Techork\PaymentService\Nuvei\VoidTransaction;

/**
 * Live integration tests against the Nuvei SANDBOX (ppp-test.safecharge.com), through the
 * operation classes production calls. Sibling of the rebilling probe in this directory and
 * of {@see \Techork\PaymentService\Tests\ConnexPayIntegration\ConnexPaySandboxTest}: that
 * probe answers a vendor question about the MIT chain; this suite walks the ordinary
 * operations end to end.
 *
 *   NUVEI_MERCHANT_ID=... NUVEI_MERCHANT_SITE_ID=... NUVEI_SECRET_KEY=... \
 *   [NUVEI_TEST_CARD=...] \
 *   vendor/bin/pest src/Nuvei/tests/Integration/NuveiSandboxTest.php
 *
 * A card is registered once per suite (raw card → UPO, the verifyCard route) and every
 * subsequent operation runs on that UPO through a Token — which is also how production
 * reaches a stored card, since Nuvei takes no raw card data on a payment.
 *
 * SAFETY. Sandbox only (Environment::TEST). `sessionToken` is never passed: the SDK fetches
 * one itself when it is absent, so the suite cannot fail on a token it fetched differently
 * from production. Holds are `Auth` with settleType 0 and every one is voided in its own
 * test; the capture test settles 1.x USD of sandbox money and refunds it, mirroring the
 * ConnexPay sandbox suite. Every request carries its own clientUniqueId (NUVLIVE-<n>-<ts>)
 * so anything left behind is findable.
 */
const NUVEI_SANDBOX_SKIP = 'Set NUVEI_MERCHANT_ID / NUVEI_MERCHANT_SITE_ID / NUVEI_SECRET_KEY to run the Nuvei sandbox integration tests.';

function nuveiSandboxConfigured(): bool
{
    return (getenv('NUVEI_MERCHANT_ID') ?: '') !== ''
        && (getenv('NUVEI_MERCHANT_SITE_ID') ?: '') !== ''
        && (getenv('NUVEI_SECRET_KEY') ?: '') !== '';
}

function nuveiSandboxClient(): RestClient
{
    static $client = null;

    return $client ??= new RestClient([
        'environment' => Environment::TEST,
        'merchantId' => (string) getenv('NUVEI_MERCHANT_ID'),
        'merchantSiteId' => (string) getenv('NUVEI_MERCHANT_SITE_ID'),
        'merchantSecretKey' => (string) getenv('NUVEI_SECRET_KEY'),
    ]);
}

function nuveiSandboxSettings(): NuveiSettings
{
    return new NuveiSettings(nuveiSandboxClient());
}

function nuveiSandboxInfrastructure(?string $reference = null): GatewayInfrastructure
{
    $instruments = Mockery::mock(GatewayInstrumentRepository::class);
    $instruments->shouldReceive('find')->andReturn($reference);

    return new GatewayInfrastructure(
        new readonly class implements GatewayCredential
        {
            public function getId(): GatewayId
            {
                return GatewayId::generate();
            }

            public function getGatewayName(): string
            {
                return 'Nuvei';
            }

            public function getCredentials(): array
            {
                return [];
            }
        },
        new readonly class implements DecryptInterface
        {
            public function decrypt(string $data): string
            {
                return $data;
            }
        },
        $instruments,
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
    );
}

function nuveiSandboxCard(): CreditCard
{
    return new CreditCard(
        Number::fromNumber((string) (getenv('NUVEI_TEST_CARD') ?: '4111111111111111'), new readonly class implements EncryptInterface
        {
            public function encrypt(string $data): string
            {
                return $data;
            }
        }),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Foundation Test'),
        Cvc::fromCvc('123', new readonly class implements EncryptInterface
        {
            public function encrypt(string $data): string
            {
                return $data;
            }
        }),
    );
}

function nuveiSandboxUserTokenId(): string
{
    static $id = null;

    return $id ??= 'nuvlive-'.bin2hex(random_bytes(6)).'@example.com';
}

/**
 * A payer whose email exists: Nuvei's mandatory-fields check refuses a billing
 * address without one (the suite Pest helper defaults its email to null).
 */
function nuveiSandboxCustomer(): \Techork\PaymentService\Common\ValueObject\Customer
{
    static $customer = null;

    return $customer ??= nuveiSuiteCustomer(email: new \Techork\PaymentService\Common\ValueObject\Email(nuveiSandboxUserTokenId()));
}

/**
 * Registers the suite's card as a UPO — the production verifyCard route — and hands the UPO
 * back for the payment tests. Registered once, memoized: each test wants an instrument, not
 * a fresh registration.
 */
function nuveiSandboxRegisteredUpo(): string
{
    static $upo = null;

    if ($upo === null) {
        $result = new RegisterPaymentMethod(
            nuveiSandboxSettings(),
            nuveiSandboxInfrastructure(),
            new VaultCommand(
                gatewayId: GatewayId::generate(),
                instrument: nuveiSandboxCard(),
                clientUniqueId: 'NUVLIVE-reg-'.time(),
                customer: nuveiSandboxCustomer(),
            ),
            nuveiSandboxUserTokenId(),
        )->register();

        expect($result->success)->toBeTrue($result->message ?? 'card registration failed');

        $upo = (string) $result->reference;
    }

    return $upo;
}

/**
 * The UPO as production carries it into a payment: a stored PaymentMethod attached to
 * somebody, whose repository reference is the `userPaymentOptionId`. (A Token's reference
 * is a one-shot ccTempToken, not the UPO.)
 */
function nuveiSandboxStoredPaymentMethod(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        nuveiSandboxCard(),
        nuveiSandboxCustomer(),
    );
}

function nuveiSandboxPlacement(int $amountMinor): PlacementCommand
{
    return new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiSandboxStoredPaymentMethod(),
        amount: new Money($amountMinor, new Currency('USD')),
        clientUniqueId: 'NUVLIVE-'.substr(bin2hex(random_bytes(4)), 0, 8).'-'.time(),
        customer: nuveiSandboxCustomer(),
    );
}

it('registers a raw card as a UPO and reports the verification result', function () {
    $result = new RegisterPaymentMethod(
        nuveiSandboxSettings(),
        nuveiSandboxInfrastructure(),
        new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: nuveiSandboxCard(),
            clientUniqueId: 'NUVLIVE-1-'.time(),
            customer: nuveiSandboxCustomer(),
        ),
        nuveiSandboxUserTokenId(),
    )->register();

    expect($result->success)->toBeTrue($result->message ?? 'register failed')
        ->and($result->reference)->not->toBeEmpty();
})->skip(! nuveiSandboxConfigured(), NUVEI_SANDBOX_SKIP);

it('authorizes a hold on the registered card and voids it', function () {
    $result = new Authorize(
        nuveiSandboxSettings(),
        nuveiSandboxInfrastructure(nuveiSandboxRegisteredUpo()),
        nuveiSandboxPlacement(517),
        nuveiSandboxUserTokenId(),
    )->authorize();

    expect($result->success)->toBeTrue($result->message ?? 'authorize failed')
        ->and($result->reference)->not->toBeEmpty();

    $void = new VoidTransaction(
        nuveiSandboxSettings(),
        new CancelCommand(GatewayId::generate(), (string) $result->reference),
    )->void();

    expect($void->success)->toBeTrue($void->message ?? 'void failed');
})->skip(! nuveiSandboxConfigured(), NUVEI_SANDBOX_SKIP);

it('authorizes, captures the hold, and refunds the settled payment', function () {
    $auth = new Authorize(
        nuveiSandboxSettings(),
        nuveiSandboxInfrastructure(nuveiSandboxRegisteredUpo()),
        nuveiSandboxPlacement(519),
        nuveiSandboxUserTokenId(),
    )->authorize();

    expect($auth->success)->toBeTrue($auth->message ?? 'authorize failed');

    $capture = new Capture(
        nuveiSandboxSettings(),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: (string) $auth->reference,
            amount: new Money(519, new Currency('USD')),
            clientUniqueId: 'NUVLIVE-capture-'.time(),
        ),
    )->capture();

    expect($capture->success)->toBeTrue($capture->message ?? 'capture failed');

    $refund = new Refund(
        nuveiSandboxSettings(),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: (string) $capture->reference,
            amount: new Money(519, new Currency('USD')),
            clientUniqueId: 'NUVLIVE-refund-'.time(),
        ),
    )->refund();

    expect($refund->success)->toBeTrue($refund->message ?? 'refund failed');
})->skip(! nuveiSandboxConfigured(), NUVEI_SANDBOX_SKIP);