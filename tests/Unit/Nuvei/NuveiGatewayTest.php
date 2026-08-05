<?php

declare(strict_types=1);

use Nuvei\Api\Environment;
use Nuvei\Api\RestClient;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\AuthorizeRequest;
use Techork\PaymentService\Nuvei\CaptureRequest;
use Techork\PaymentService\Nuvei\CreateCardRequest;
use Techork\PaymentService\Nuvei\CreateCustomerRequest;
use Techork\PaymentService\Nuvei\CreatePaymentMethodRequest;
use Techork\PaymentService\Nuvei\NuveiGateway;
use Techork\PaymentService\Nuvei\PayoutRequest;
use Techork\PaymentService\Nuvei\PurchaseRequest;
use Techork\PaymentService\Nuvei\RefundRequest;
use Techork\PaymentService\Nuvei\UpdateCustomerRequest;
use Techork\PaymentService\Nuvei\VoidRequest;

/**
 * Facade smoke tests for {@see NuveiGateway}.
 *
 * The whole class was unexecuted, which hides two failure modes that no
 * request-level test can see:
 *
 *  1. A factory method that names the wrong request class, or that
 *     {@see \Techork\PaymentService\Gateway\Contract\Gateway} declares but this
 *     gateway never implemented. `AbstractGateway` has no `__call`, so the
 *     latter is a fatal `Call to undefined method` that the router's catch
 *     turns into what reads as an acquirer decline.
 *  2. A factory method reached with the wrong parameter array — or none at
 *     all. An omnipay request assembles itself entirely from what
 *     `initialize()` was handed, so a call site that forgets the array
 *     produces a well-typed request object that has nothing in it and fails
 *     on `send()`. That is precisely how `PaymentGatewayRouter` was calling
 *     `retryRefund()` and `updateVirtualCard()`: mocks answered the method
 *     regardless of arguments, so the empty request surfaced only in
 *     production as a gateway refusal.
 *
 * Every assertion below therefore checks BOTH halves: the class that comes
 * back and the options that reached it.
 *
 * A note on the `sessionToken` passed to every gateway here. `initialize()`
 * calls Nuvei's getSessionToken endpoint whenever real credentials are present
 * and no token is set yet, so seeding one keeps these tests offline. The one
 * test that deliberately omits it also omits the merchant id, exercising the
 * guard that stops `new NuveiGateway` from reaching the network.
 */
function nuveiFacadeGateway(array $overrides = []): NuveiGateway
{
    $gateway = new NuveiGateway;
    $gateway->initialize($overrides + [
        'merchantId' => 'mid-7',
        'merchantSiteId' => 'site-7',
        'secretKey' => 'secret-7',
        'sessionToken' => 'session-7',
    ]);

    return $gateway;
}

function nuveiFacadeCredential(): GatewayCredential
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

function nuveiFacadeInstrument(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test Holder'),
            new Cvc,
        ),
        new BillingAddress('Test', 'User', '1 Street', 'Miami', new Country('US'), '33101'),
    );
}

/**
 * A repository whose answer for the existing customer link is fixed up front.
 * Mockery would do, but the interface is two methods and the tests care about
 * the returned value rather than the call, so a stub reads clearer.
 */
function nuveiFacadeCustomerRepository(?string $existingLink): CustomerRepository
{
    return new readonly class($existingLink) implements CustomerRepository
    {
        public function __construct(private ?string $existingLink) {}

        public function findByInstrument(GatewayId $gatewayId, PaymentInstrument $instrument): ?string
        {
            return $this->existingLink;
        }

        public function saveAndAttach(GatewayId $gatewayId, PaymentInstrument $instrument, string $customerReference): void
        {
            // No-op: nothing here reaches the create-and-link branch, which
            // would need a live Nuvei createUser call.
        }
    };
}

// ──────────────────────────────────────────────
//  identity and credentials
// ──────────────────────────────────────────────

it('has name nuvei', function () {
    expect(nuveiFacadeGateway()->getName())->toBe('nuvei');
});

/**
 * The defaults are what a gateway built with no configuration hands to every
 * request. They are pinned because `createRequest()` copies them into the
 * request unconditionally: an empty string here reaches Nuvei as an empty
 * merchant id, whereas a missing key would be a `null` the request classes
 * do not expect.
 */
it('defaults to the Nuvei test environment with blank credentials and no session', function () {
    expect(new NuveiGateway()->getDefaultParameters())->toBe([
        'merchantId' => '',
        'merchantSiteId' => '',
        'secretKey' => '',
        'sessionToken' => null,
        'environment' => Environment::TEST,
    ]);
});

it('round-trips every credential accessor', function () {
    $gateway = new NuveiGateway;

    $gateway->setMerchantId('mid-1')
        ->setMerchantSiteId('site-1')
        ->setSecretKey('secret-1')
        ->setEnvironment(Environment::LIVE)
        ->setSessionToken('session-1');

    expect($gateway->getMerchantId())->toBe('mid-1')
        ->and($gateway->getMerchantSiteId())->toBe('site-1')
        ->and($gateway->getSecretKey())->toBe('secret-1')
        ->and($gateway->getEnvironment())->toBe(Environment::LIVE)
        ->and($gateway->getSessionToken())->toBe('session-1');
});

/**
 * `setSiteId()` exists only because the Laravel factory feeds
 * `services.nuvei.site_id` through `setParameter`-style naming. It has no
 * storage of its own, so a rename on `setMerchantSiteId` would silently make
 * the alias write to a key nothing reads.
 */
it('aliases setSiteId onto the merchant site id rather than a key of its own', function () {
    expect(new NuveiGateway()->setSiteId('site-alias')->getMerchantSiteId())->toBe('site-alias');
});

/**
 * `AbstractGateway::__construct()` calls `initialize()` with no arguments, so
 * without the merchant-id guard every `new NuveiGateway` — including the one
 * the container makes while merely resolving the binding — would post to
 * Nuvei's getSessionToken endpoint with blank credentials. A null token after
 * initialize is the observable proof the guard held.
 */
it('does not fetch a session token while the credentials are still blank', function () {
    expect(new NuveiGateway()->initialize()->getSessionToken())->toBeNull();
});

// ──────────────────────────────────────────────
//  factory methods: class AND options
// ──────────────────────────────────────────────

/**
 * The first half of the contract: each method names the request class the
 * router expects. `retryRefund` is the interesting row — it maps to
 * `PayoutRequest`, because at Nuvei "refund to a different card" is a payout,
 * not a refund. Nothing else in the codebase makes that mapping visible.
 */
it('builds the request class each operation is routed to', function (string $method, string $expected) {
    expect(nuveiFacadeGateway()->{$method}())->toBeInstanceOf($expected);
})->with([
    ['createCustomer', CreateCustomerRequest::class],
    ['updateCustomer', UpdateCustomerRequest::class],
    ['createCard', CreateCardRequest::class],
    ['createPaymentMethod', CreatePaymentMethodRequest::class],
    ['purchase', PurchaseRequest::class],
    ['authorize', AuthorizeRequest::class],
    ['capture', CaptureRequest::class],
    ['refund', RefundRequest::class],
    ['retryRefund', PayoutRequest::class],
    ['void', VoidRequest::class],
]);

/**
 * The second half, and the one the router's `retryRefund()` bug would have
 * failed: the caller's options must actually land in the request. An omnipay
 * request has no other input — `getData()` reads the parameter bag and
 * nothing else — so a factory method that drops the array, or a call site
 * that never passes one, yields a request that is the right class and still
 * empty.
 */
it('forwards the caller options into every request it builds', function (string $method) {
    $request = nuveiFacadeGateway()->{$method}([
        'transactionReference' => 'txn-marker',
        'clientUniqueId' => 'cuid-marker',
        'description' => 'description-marker',
    ]);

    expect($request->getParameters())
        ->toHaveKey('transactionReference', 'txn-marker')
        ->toHaveKey('clientUniqueId', 'cuid-marker')
        ->toHaveKey('description', 'description-marker');
})->with([
    'createCustomer',
    'updateCustomer',
    'createCard',
    'createPaymentMethod',
    'purchase',
    'authorize',
    'capture',
    'refund',
    'retryRefund',
    'void',
]);

/**
 * Omnipay's `Helper::initialize()` only applies an option when the request
 * exposes a matching `set…()`; anything else is discarded without a word.
 * Pinned because it is the mechanism behind the whole class of defect above:
 * a mistyped or newly-introduced option name does not fail loudly, it simply
 * never arrives, and the request goes to the acquirer missing a field.
 */
it('silently drops options no request setter accepts', function () {
    expect(nuveiFacadeGateway()->purchase(['notASetterOnAnyRequest' => 'x'])->getParameters())
        ->not->toHaveKey('notASetterOnAnyRequest');
});

/**
 * Credentials are injected by `createRequest()`, not by the caller, so a
 * request built through the facade must be able to reach Nuvei on its own.
 * The `RestClient` is asserted to be the very same object across two calls:
 * it carries the session the gateway paid a round trip for, and rebuilding
 * one per request would spend another.
 */
it('injects the credentials and a shared rest client into each request', function () {
    $gateway = nuveiFacadeGateway();

    $first = $gateway->purchase()->getParameters();
    $second = $gateway->refund()->getParameters();

    expect($first)
        ->toHaveKey('merchantId', 'mid-7')
        ->toHaveKey('merchantSiteId', 'site-7')
        ->toHaveKey('secretKey', 'secret-7')
        ->toHaveKey('sessionToken', 'session-7')
        ->toHaveKey('environment', Environment::TEST)
        ->and($first['restClient'])->toBeInstanceOf(RestClient::class)
        ->and($second['restClient'])->toBe($first['restClient']);
});

/**
 * A caller-supplied value must win over the injected default, otherwise a
 * request cannot be retargeted — the Laravel factory configures the gateway
 * once per provider, and per-request overrides are how a single gateway
 * serves more than one merchant site.
 */
it('lets caller options override the injected gateway credentials', function () {
    expect(nuveiFacadeGateway()->purchase(['merchantSiteId' => 'site-override'])->getParameters())
        ->toHaveKey('merchantSiteId', 'site-override');
});

// ──────────────────────────────────────────────
//  refusals
// ──────────────────────────────────────────────

/**
 * Nuvei is an acquirer and issues no cards, so all three card-issuing
 * operations are structurally impossible. They exist at all because
 * {@see \Techork\PaymentService\Gateway\Contract\Gateway} declares them and
 * `PaymentGatewayRouter` calls them on whatever gateway it holds — undeclared,
 * each would be a fatal `Call to undefined method` instead of a refusal.
 *
 * The message is asserted, not just the type, because the router surfaces it
 * to operators as the reason nothing happened; "Nuvei does not support …"
 * is actionable where a bare class name is not.
 */
it('refuses card issuing as a typed error rather than an undefined method', function (string $method) {
    $gateway = nuveiFacadeGateway();

    expect(fn () => $gateway->{$method}())
        ->toThrow(UnsupportedOperation::class, 'does not support the "'.$method.'" operation');
})->with(['issueVirtualCard', 'updateVirtualCard', 'terminateVirtualCard']);

it('refuses card issuing with the marker that stops it becoming a decline', function (string $method) {
    // The reason the type matters more than the message. These used to be a bare
    // RuntimeException, and PaymentGatewayRouter rethrows only UnsupportedByGateway while
    // folding everything else into AuthorizationResult::failed() — so a card-issuing request
    // misrouted to Nuvei was recorded as PaymentIntentFailed, an acquirer decline for a
    // request no acquirer ever saw.
    $thrown = null;

    try {
        nuveiFacadeGateway()->{$method}();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
})->with(['issueVirtualCard', 'updateVirtualCard', 'terminateVirtualCard']);

/**
 * The refusal must not depend on what it was handed. `updateVirtualCard()` was
 * the method the router called with no options at all, so a refusal that read
 * its arguments first would behave differently for that caller than for any
 * other.
 */
it('refuses card issuing identically whether or not options are supplied', function () {
    $gateway = nuveiFacadeGateway();

    expect(fn () => $gateway->updateVirtualCard(['cardReference' => 'card-1']))
        ->toThrow(UnsupportedOperation::class, 'does not support the "updateVirtualCard" operation');
});

// ──────────────────────────────────────────────
//  customer resolution
// ──────────────────────────────────────────────

/**
 * Only the four operations that need a `userTokenId` on the wire resolve a
 * customer. The split is pinned in both directions because it is not free:
 * resolution can cost a createUser round trip, so widening it to `capture`,
 * `refund` or `void` would add a network call to operations that reference an
 * existing transaction and need no user at all.
 */
it('attaches the resolved customer reference to the operations that need one', function (string $method) {
    $gateway = nuveiFacadeGateway();
    $gateway->setCustomerRepository(nuveiFacadeCustomerRepository('linked@example.com'));

    $request = $gateway->{$method}([
        'gateway' => nuveiFacadeCredential(),
        'instrument' => nuveiFacadeInstrument(),
    ]);

    expect($request->getParameters())->toHaveKey('customerReference', 'linked@example.com');
})->with(['createPaymentMethod', 'purchase', 'authorize', 'retryRefund']);

it('resolves no customer for operations that act on an existing transaction', function (string $method) {
    $gateway = nuveiFacadeGateway();
    $gateway->setCustomerRepository(nuveiFacadeCustomerRepository('linked@example.com'));

    $request = $gateway->{$method}([
        'gateway' => nuveiFacadeCredential(),
        'instrument' => nuveiFacadeInstrument(),
    ]);

    expect($request->getParameters())->not->toHaveKey('customerReference');
})->with(['capture', 'refund', 'void', 'createCard']);

/**
 * Resolution needs all three of a repository, a gateway credential and an
 * instrument; any one missing means the caller is not in a position to link
 * anything. Each row omits exactly one so a future short-circuit that
 * collapses the three checks into one cannot pass by accident.
 */
it('skips resolution when the inputs it links are not all present', function (array $options, bool $withRepository) {
    $gateway = nuveiFacadeGateway();
    if ($withRepository) {
        $gateway->setCustomerRepository(nuveiFacadeCustomerRepository('linked@example.com'));
    }

    expect($gateway->purchase($options)->getParameters())->not->toHaveKey('customerReference');
})->with([
    'no repository' => [fn () => ['gateway' => nuveiFacadeCredential(), 'instrument' => nuveiFacadeInstrument()], false],
    'no gateway credential' => [fn () => ['instrument' => nuveiFacadeInstrument()], true],
    'no instrument' => [fn () => ['gateway' => nuveiFacadeCredential()], true],
]);

/**
 * An empty-string link counts as missing, not as a customer named ''. Legacy
 * rows exist where `customer_reference` was written as '', and an empty
 * `userTokenId` makes Nuvei reject any payment that references a stored
 * `userPaymentOptionId` — so passing it through would turn a repairable row
 * into a decline.
 *
 * With no billing address there is nothing to create a customer from, so the
 * repair stops here and the reference stays unset rather than empty.
 */
it('treats an empty-string customer link as missing', function () {
    $gateway = nuveiFacadeGateway();
    $gateway->setCustomerRepository(nuveiFacadeCustomerRepository(''));

    $request = $gateway->purchase([
        'gateway' => nuveiFacadeCredential(),
        'instrument' => nuveiFacadeInstrument(),
    ]);

    expect($request->getParameters())->not->toHaveKey('customerReference');
});

/**
 * For Nuvei the customer reference IS the email, so an address without one
 * cannot produce a `userTokenId`. Pinned because the alternative — falling
 * through to createUser with an empty email — would register a junk user at
 * the acquirer on every unlinked payment.
 */
it('does not create a customer from a billing address that carries no email', function () {
    $gateway = nuveiFacadeGateway();
    $gateway->setCustomerRepository(nuveiFacadeCustomerRepository(null));

    $request = $gateway->purchase([
        'gateway' => nuveiFacadeCredential(),
        'instrument' => nuveiFacadeInstrument(),
        'billingAddress' => new BillingAddress('Test', 'User', '1 Street', 'Miami', new Country('US'), '33101'),
    ]);

    expect($request->getParameters())->not->toHaveKey('customerReference');
});
