<?php

declare(strict_types=1);

use Nuvei\Api\Environment;
use Nuvei\Api\Interfaces\HttpClientInterface;
use Nuvei\Api\Interfaces\ServiceInterface;
use Money\Currency;
use Money\Money;
use Nuvei\Api\RestClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Authorize;
use Techork\PaymentService\Nuvei\NuveiSettings;
use Techork\PaymentService\Nuvei\Purchase;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

/*
| The two things every Nuvei operation is constructed with, once, instead of the parameter array
| each test used to assemble by hand.
|
| They live here rather than in one test file because the operations all take the same pair —
| {@see NuveiSettings} for what the deployment configured, {@see GatewayInfrastructure} for the
| credential, decrypter and instrument repository — and a per-file copy is how the old arrays
| drifted from each other. Prefixed `nuveiSuite…`: Pest helpers are global across the whole
| foundation suite.
*/

/**
 * A client that can be built and inspected without a network. `RestClient::__construct()` only
 * wraps its config, so nothing here reaches ppp-test.nuvei.com.
 *
 * @param  array<string, mixed>  $overrides
 */
function nuveiSuiteRestClient(array $overrides = []): RestClient
{
    return new RestClient($overrides + [
        'environment' => Environment::TEST,
        'merchantId' => 'mid-suite',
        'merchantSiteId' => 'site-suite',
        'merchantSecretKey' => 'secret-suite',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiSuiteSettings(array $overrides = []): NuveiSettings
{
    return new NuveiSettings(
        restClient: $overrides['restClient'] ?? nuveiSuiteRestClient(),
        merchantId: $overrides['merchantId'] ?? 'mid-suite',
        merchantSiteId: $overrides['merchantSiteId'] ?? 'site-suite',
        secretKey: $overrides['secretKey'] ?? 'secret-suite',
        environment: $overrides['environment'] ?? Environment::TEST,
        sessionToken: array_key_exists('sessionToken', $overrides) ? $overrides['sessionToken'] : 'sess-suite',
    );
}

/**
 * A transport that records the endpoint and params the SDK service handed it, and answers with a
 * fixed reply.
 *
 * Operations are driven through it rather than by inspecting their payload alone, because what has
 * to hold is that the fields each declares reach the WIRE and that the SDK method it picks is the
 * one actually called. An operation substitutes a failure for any Throwable, so one that never got
 * out would still yield a well-formed result; the recorded call is what rules that out.
 *
 * @param  array<int, array{url: string, params: array}>  $calls
 * @param  array<string, mixed>  $reply
 */
function nuveiSuiteTransport(array &$calls, array $reply): HttpClientInterface
{
    return new class($calls, $reply) implements HttpClientInterface
    {
        /** @param array<int, array{url: string, params: array}> $calls */
        public function __construct(private array &$calls, private readonly array $reply) {}

        public function requestJson(ServiceInterface $service, $requestUrl, $params)
        {
            $this->calls[] = ['url' => $requestUrl, 'params' => $params];

            return $this->reply;
        }

        public function requestPost(ServiceInterface $service, $requestUrl, $params)
        {
            throw new LogicException('These operations go through requestJson.');
        }
    };
}

/**
 * A RestClient wired to the recorder. Subclassing is the only seam the SDK leaves: `$httpClient` is
 * private and lazily built, with no setter.
 *
 * @param  array<int, array{url: string, params: array}>  $calls
 * @param  array<string, mixed>  $reply
 */
function nuveiSuiteRecordingClient(
    array &$calls,
    array $reply = ['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED', 'transactionId' => 'txn-1'],
): RestClient {
    return new class(nuveiSuiteTransport($calls, $reply)) extends RestClient
    {
        public function __construct(private HttpClientInterface $transport)
        {
            parent::__construct([
                'environment' => Environment::TEST,
                'merchantId' => 'mid-suite',
                'merchantSiteId' => 'site-suite',
                'merchantSecretKey' => 'secret-suite',
            ]);
        }

        public function getHttpClient()
        {
            return $this->transport;
        }
    };
}

/**
 * A client whose transport refuses, standing in for an acquirer that cannot be reached. Every
 * operation substitutes a failed result for a Throwable rather than letting it out, because an
 * exception escaping here reaches the event stream as what reads like an issuer decline for a
 * request no issuer ever saw.
 */
function nuveiSuiteUnreachableClient(string $reason = 'Connection timed out'): RestClient
{
    return new class($reason) extends RestClient
    {
        public function __construct(private readonly string $reason)
        {
            parent::__construct([
                'environment' => Environment::TEST,
                'merchantId' => 'mid-suite',
                'merchantSiteId' => 'site-suite',
                'merchantSecretKey' => 'secret-suite',
            ]);
        }

        public function getHttpClient()
        {
            $reason = $this->reason;

            return new class($reason) implements HttpClientInterface
            {
                public function __construct(private readonly string $reason) {}

                public function requestJson(ServiceInterface $service, $requestUrl, $params)
                {
                    throw new Nuvei\Api\Exception\ConnectionException($this->reason);
                }

                public function requestPost(ServiceInterface $service, $requestUrl, $params)
                {
                    throw new Nuvei\Api\Exception\ConnectionException($this->reason);
                }
            };
        }
    };
}

function nuveiSuiteCredential(): GatewayCredential
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

/**
 * An instrument repository whose answer is fixed up front, since what the operations care about is
 * the reference that comes back rather than the call that fetched it.
 */
function nuveiSuiteInstruments(?string $reference = null): GatewayInstrumentRepository
{
    $mock = Mockery::mock(GatewayInstrumentRepository::class, ['find' => $reference]);
    $mock->shouldReceive('find')->andReturn($reference);
    $mock->shouldReceive('findMetadata')->andReturn([]);

    return $mock;
}

/**
 * The instruments a Nuvei payment can actually be made with. A card is not one of them — Nuvei
 * takes card data only through tokenization — so the token and the stored payment method are what
 * nearly every payment test starts from.
 */
/**
 * A decrypter (and its matching encrypter) that hand back what they were given, so a test can
 * assert the card fields an operation assembles without standing up encryption.
 */
function nuveiSuiteDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $data): string
        {
            return $data;
        }
    };
}

function nuveiSuiteEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface
    {
        public function encrypt(string $data): string
        {
            return $data;
        }
    };
}

/**
 * A card whose PAN and CVV can actually be read back — the only shape tokenization can be handed,
 * as against the masked {@see nuveiTestCard()} a stored instrument carries.
 */
function nuveiSuiteRawCard(): CreditCard
{
    return new CreditCard(
        Number::fromNumber('4761344136141390', nuveiSuiteEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', nuveiSuiteEncrypter()),
    );
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

function nuveiTestToken(): Token
{
    return new Token(
        TokenId::generate(),
        nuveiTestCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );
}

function nuveiTestPaymentMethod(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        nuveiTestCard(),
    );
}

/**
 * The same stored card with a customer attached — the only form a gateway will take a payment
 * on.
 *
 * A bare `PaymentMethod` is refused by every payment operation now, so the two fixtures are
 * both needed: this one for the payments, the bare one for the tests that assert the refusal.
 */
function nuveiTestAttachedPaymentMethod(): AttachedPaymentMethod
{
    return new AttachedPaymentMethod(nuveiSuiteCustomer(), nuveiTestPaymentMethod());
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiSuiteInfrastructure(array $overrides = []): GatewayInfrastructure
{
    return new GatewayInfrastructure(
        $overrides['credential'] ?? nuveiSuiteCredential(),
        $overrides['decrypter'] ?? nuveiSuiteDecrypter(),
        $overrides['instruments'] ?? nuveiSuiteInstruments(),
        $overrides['customers'] ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        $overrides['settings'] ?? [],
    );
}

/*
| The two placement operations, built the way the gateway builds them.
|
| Three shapes of input kept apart, where an omnipay request took one array: the settings are what
| the deployment configured, the command is what the caller asked for, and the customer reference is
| what the gateway resolved before the operation existed. `$overrides` addresses all three at once
| only because a test wants one line, not because the production seam does.
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiPurchaseOf(PaymentInstrument $instrument, array $overrides = []): Purchase
{
    return new Purchase(
        nuveiSuiteSettings($overrides),
        nuveiSuiteInfrastructure(['instruments' => nuveiSuiteInstruments($overrides['reference'] ?? null)]),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: $instrument,
            amount: $overrides['money'] ?? new Money(2500, new Currency('USD')),
            clientUniqueId: $overrides['clientUniqueId'] ?? null,
                threeDS: $overrides['threeDS'] ?? null,
            statementDescription: $overrides['statementDescription'] ?? null,
        ),
        $overrides['customerReference'] ?? '',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiAuthorizationOf(PlacementCommand|RebillingCommand $command, array $overrides = []): Authorize
{
    return new Authorize(
        nuveiSuiteSettings($overrides),
        nuveiSuiteInfrastructure(['instruments' => nuveiSuiteInstruments($overrides['reference'] ?? 'upo_123')]),
        $command,
        $overrides['customerReference'] ?? '',
    );
}

/**
 * A customer id for this suite's tests.
 */
function nuveiSuiteCustomerId(string $id = '01920000-0000-7000-8000-00000000cafe'): CustomerId
{
    return CustomerId::fromString($id);
}

/**
 * The payer these tests hand to a command, complete, because a {@see Customer} has no partial
 * form — an id, a person and an address or nothing at all.
 *
 * That completeness is the change worth knowing about here. The id, the identity and the address
 * used to be three optional arguments a caller could supply any subset of, which is how a
 * provider-side customer came to be built out of whatever billing address rode along with the
 * payment. A test that wants to say "no payer" passes null, not a fragment.
 */
function nuveiSuiteCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?PhoneNumber $phone = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? nuveiSuiteCustomerId(),
        identity: new CustomerIdentity($firstName, $lastName, $email, $phone),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}

/**
 * The one customer a command now takes, assembled from the option keys these tests have used all
 * along.
 *
 * `billingAddress`, `customerId` and `customerIdentity` were three separate command fields and
 * are one. The keys stay because what each test is *saying* has not changed — "billed here", "for
 * this customer", "who is this person" — and rewriting every call site to say it a new way would
 * bury the change that matters in the change that does not.
 *
 * Naming any one of them yields a whole customer, which is the design: an address with nobody
 * attached to it is not expressible any more. A test that means "no payer at all" names none of
 * the three and gets null.
 *
 * @param  array<string, mixed>  $options
 */
function nuveiSuiteCustomerFrom(array $options): ?Customer
{
    if (array_key_exists('customer', $options)) {
        return $options['customer'];
    }

    $named = ['billingAddress', 'customerId', 'customerIdentity'];
    if (! array_filter($named, static fn (string $k): bool => ($options[$k] ?? null) !== null)) {
        return null;
    }

    /** @var ?CustomerIdentity $identity */
    $identity = $options['customerIdentity'] ?? null;

    return nuveiSuiteCustomer(
        id: $options['customerId'] ?? null,
        firstName: $identity->firstName ?? 'Ada',
        lastName: $identity->lastName ?? 'Lovelace',
        email: $identity->email ?? null,
        phone: $identity->phone ?? null,
        address: $options['billingAddress'] ?? null,
    );
}
