<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Nuvei\Api\Environment;
use Nuvei\Api\RestClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Authorize;
use Techork\PaymentService\Nuvei\Capture;
use Techork\PaymentService\Nuvei\NuveiGateway;
use Techork\PaymentService\Nuvei\Payout;
use Techork\PaymentService\Nuvei\Purchase;
use Techork\PaymentService\Nuvei\Refund;
use Techork\PaymentService\Nuvei\RegisterPaymentMethod;
use Techork\PaymentService\Nuvei\Tokenize;
use Techork\PaymentService\Nuvei\VoidTransaction;

/**
 * Facade tests for {@see NuveiGateway}.
 *
 * The class was once entirely unexecuted, which hid two failure modes no operation-level test can
 * see: a role that names the wrong operation, and a role that builds one without giving it what the
 * caller asked for. Both used to be invisible in a different way as well — an omnipay request
 * assembled itself from a parameter array, so a role that dropped the array produced a well-typed
 * request with nothing in it, and the emptiness surfaced only in production as a gateway refusal.
 *
 * That second failure mode is structurally gone: an operation's inputs are constructor arguments,
 * so there is no array to drop and no setter for a value to fall through. What is left worth
 * pinning is the first — which operation each role picks — plus the payload the pair actually
 * produces, which is the only thing that proves the command reached it.
 *
 * A note on the `sessionToken` seeded into every gateway here. {@see NuveiGateway::configure()}
 * calls Nuvei's getSessionToken endpoint whenever real credentials are present and no token is set,
 * so seeding one keeps these tests offline. The one test that deliberately omits it also omits the
 * merchant id, exercising the guard that stops a bare gateway reaching the network.
 *
 * @param  array<string, mixed>  $settings
 */
function nuveiFacadeGateway(
    array $settings = [],
    ?GatewayCustomerRepository $customers = null,
    ?GatewayInstrumentRepository $instruments = null,
): NuveiGateway {
    $gateway = new NuveiGateway;

    $gateway->configure(new GatewayInfrastructure(
        nuveiSuiteCredential(),
        Mockery::mock(DecryptInterface::class),
        $instruments ?? nuveiSuiteInstruments('upo-linked'),
        $customers ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        $settings + [
            'merchantId' => 'mid-7',
            'merchantSiteId' => 'site-7',
            'secretKey' => 'secret-7',
            'sessionToken' => 'session-7',
        ],
    ));

    return $gateway;
}

/**
 * The gateway-customer map with its answer fixed up front. Mockery would do, but the interface is
 * two methods and the tests care about the returned value rather than the call, so a stub reads
 * clearer.
 *
 * `$saved` records the writes so a test can assert that taking a payment performs none: this
 * gateway used to register a Nuvei user as a side effect of resolution, and the absence of that
 * is now the behaviour worth pinning.
 */
function nuveiFacadeCustomerRepository(?string $existingReference): GatewayCustomerRepository
{
    return new class($existingReference) implements GatewayCustomerRepository
    {
        /** @var list<array{string, string}> */
        public array $saved = [];

        public function __construct(private readonly ?string $existingReference) {}

        public function find(GatewayId $gatewayId, CustomerIdentifier $customerId): ?string
        {
            return $this->existingReference;
        }

        public function saveReference(GatewayId $gatewayId, CustomerIdentifier $customerId, string $reference): void
        {
            $this->saved[] = [$customerId->toString(), $reference];
        }
    };
}

function nuveiFacadePlacement(?BillingAddress $billingAddress = null, ?CustomerIdentifier $customerId = null): PlacementCommand
{
    return new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiTestPaymentMethod(),
        amount: new Money(1000, new Currency('USD')),
        clientUniqueId: 'cuid-marker',
        billingAddress: $billingAddress,
        statementDescription: 'descriptor-marker',
        customerId: $customerId ?? nuveiSuiteCustomerId(),
    );
}

/**
 * A temp token, not a stored payment method: registering converts one instrument INTO a UPO, so a
 * UPO is the one thing it cannot be handed. Tokenizing wants the other end of that chain, a raw
 * card, so it builds its own command where it is exercised.
 */
function nuveiFacadeVault(?CustomerIdentifier $customerId = null): VaultCommand
{
    return new VaultCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiTestToken(),
        clientUniqueId: 'cuid-marker',
        customerId: $customerId ?? nuveiSuiteCustomerId(),
    );
}

/**
 * The card operations take typed commands, so a dataset that only names the operation needs
 * something to build one. Kept because what these rows pin is the refusal, not the signature.
 */
function nuveiCardInvoke(NuveiGateway $gateway, string $operation): mixed
{
    return match ($operation) {
        'issueVirtualCard' => $gateway->issueVirtualCard(new IssueCardCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'sale-guid',
            amountLimit: new Money(1000, new Currency('USD')),
            spendCategory: CardSpendCategory::TravelAir,
        )),
        'updateVirtualCard' => $gateway->updateVirtualCard(new UpdateCardCommand(
            GatewayId::generate(),
            'card-guid',
            new Money(1000, new Currency('USD')),
            CardSpendCategory::TravelAir,
        )),
        default => $gateway->terminateVirtualCard(
            new TerminateCardCommand(GatewayId::generate(), 'card-guid'),
        ),
    };
}

// ──────────────────────────────────────────────
//  identity and configuration
// ──────────────────────────────────────────────

it('has name nuvei', function () {
    expect(nuveiFacadeGateway()->getName())->toBe('nuvei');
});

/*
 * Three tests are gone and cannot come back, all of them about the parameter bag rather than about
 * Nuvei. `it('defaults to the Nuvei test environment with blank credentials and no session')`
 * asserted `getDefaultParameters()`, which existed so omnipay's initializer had something to merge;
 * `it('round-trips every credential accessor')` and
 * `it('aliases setSiteId onto the merchant site id rather than a key of its own')` asserted setters
 * that existed only for that merge to find. There is one way in now — {@see
 * NuveiGateway::configure()} — and the test below reads what it produced.
 */

it('reads its credentials out of the settings it was configured with', function () {
    // The whole configuration surface, in one pass. A mistyped key is a value that is visibly
    // absent here rather than a setter that silently never fired.
    $gateway = nuveiFacadeGateway(['environment' => Environment::LIVE]);

    expect($gateway->getMerchantId())->toBe('mid-7')
        ->and($gateway->getMerchantSiteId())->toBe('site-7')
        ->and($gateway->getSecretKey())->toBe('secret-7')
        ->and($gateway->getEnvironment())->toBe(Environment::LIVE)
        ->and($gateway->getSessionToken())->toBe('session-7');
});

/**
 * The settings object is what every operation is built with, so what configure() read has to reach
 * it — and the same `RestClient` has to come back each time, because it carries the session the
 * gateway paid a round trip for and rebuilding one per operation would spend another.
 */
it('hands its configuration to the operations as one settings object', function () {
    $gateway = nuveiFacadeGateway();

    $settings = $gateway->settings();

    expect($settings->merchantId)->toBe('mid-7')
        ->and($settings->merchantSiteId)->toBe('site-7')
        ->and($settings->secretKey)->toBe('secret-7')
        ->and($settings->sessionToken)->toBe('session-7')
        ->and($settings->environment)->toBe(Environment::TEST)
        ->and($settings->restClient)->toBeInstanceOf(RestClient::class)
        ->and($gateway->settings()->restClient)->toBe($settings->restClient);
});

/**
 * Without the merchant-id guard, configuring a gateway from a half-filled credential row would post
 * to Nuvei's getSessionToken endpoint with blank credentials. A null token afterwards is the
 * observable proof the guard held.
 */
it('does not fetch a session token while the credentials are still blank', function () {
    $gateway = new NuveiGateway;
    $gateway->configure(new GatewayInfrastructure(
        nuveiSuiteCredential(),
        Mockery::mock(DecryptInterface::class),
        nuveiSuiteInstruments(),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
    ));

    expect($gateway->getSessionToken())->toBeNull();
});

// ──────────────────────────────────────────────
//  which operation each role picks, and what reaches it
//
//  There are no per-operation accessors to interrogate any more: a role builds its operation and
//  invokes it, and a test that wants a payload builds the same operation itself — {@see
//  NuveiSettings} is a public value object, so nothing in the gateway has to be widened for it.
//
//  What is left for the FACADE to prove is therefore the half an operation-level test cannot see:
//  that the role reaches the right endpoint with the caller's fields on it. Driven through a
//  recording transport, because the endpoint is the mapping — `retryRefund` going to `payout.do`
//  rather than `refundTransaction.do` is the whole of "at Nuvei a refund to another card is a
//  payout", and nothing else in the codebase says it.
// ──────────────────────────────────────────────

/**
 * @param  array<int, array{url: string, params: array}>  $calls
 */
function nuveiFacadeRecordingGateway(array &$calls, ?GatewayCustomerRepository $customers = null): NuveiGateway
{
    $gateway = new NuveiGateway;

    $gateway->configure(new GatewayInfrastructure(
        nuveiSuiteCredential(),
        nuveiSuiteDecrypter(),
        nuveiSuiteInstruments('upo-linked'),
        $customers ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        [
            'merchantId' => 'mid-7',
            'merchantSiteId' => 'site-7',
            'secretKey' => 'secret-7',
            'sessionToken' => 'session-7',
            // The gateway builds its own RestClient in configure(), so the recorder is injected by
            // swapping what that client's transport is — see nuveiSuiteRecordingClient.
        ],
    ));

    $swap = new ReflectionProperty(NuveiGateway::class, 'restClient');
    $swap->setValue($gateway, nuveiSuiteRecordingClient($calls));

    return $gateway;
}

it('sends each role to the Nuvei endpoint that serves it', function (string $role, string $endpoint) {
    $calls = [];
    $gateway = nuveiFacadeRecordingGateway($calls);

    match ($role) {
        'charge' => $gateway->charge(nuveiFacadePlacement()),
        'authorize' => $gateway->authorize(nuveiFacadePlacement()),
        'authorizeRebilling' => $gateway->authorizeRebilling(new RebillingCommand(
            gatewayId: GatewayId::generate(),
            instrument: nuveiTestPaymentMethod(),
            amount: new Money(1000, new Currency('USD')),
            initiation: PaymentInitiation::MerchantRecurring,
            genesisReference: 'genesis-1',
        )),
        'capture' => $gateway->capture(new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'txn-marker',
            amount: new Money(2500, new Currency('USD')),
            clientUniqueId: 'cuid-marker',
        )),
        'refund' => $gateway->refund(new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'txn-marker',
            amount: new Money(2500, new Currency('USD')),
            clientUniqueId: 'cuid-marker',
        )),
        'retryRefund' => $gateway->retryRefund(new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'txn-marker',
            amount: new Money(2500, new Currency('USD')),
            clientUniqueId: 'cuid-marker',
            retryInstrument: nuveiTestPaymentMethod(),
        )),
        'cancel' => $gateway->cancel(new CancelCommand(GatewayId::generate(), 'txn-marker', 'cuid-marker')),
        'registerPaymentMethod' => $gateway->registerPaymentMethod(nuveiFacadeVault()),
        // A raw card, because tokenizing is what turns one INTO a token — handed a token,
        // {@see Tokenize} refuses and nothing leaves.
        default => $gateway->tokenize(new VaultCommand(GatewayId::generate(), nuveiSuiteRawCard())),
    };

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/'.$endpoint);
})->with([
    ['charge', 'payment.do'],
    ['authorize', 'payment.do'],
    ['authorizeRebilling', 'payment.do'],
    ['capture', 'settleTransaction.do'],
    ['refund', 'refundTransaction.do'],
    // "Refund to a different card" is a payout at Nuvei, not a refund.
    ['retryRefund', 'payout.do'],
    // Cancelling an intent is voiding its authorization; Nuvei has no other verb for it.
    ['cancel', 'voidTransaction.do'],
    ['registerPaymentMethod', 'addUPOCreditCardByTempToken.do'],
    ['tokenize', 'cardTokenization.do'],
]);

it('separates the sale from the hold by transaction type and settle type', function () {
    $chargeCalls = [];
    nuveiFacadeRecordingGateway($chargeCalls)->charge(nuveiFacadePlacement());

    $authorizeCalls = [];
    nuveiFacadeRecordingGateway($authorizeCalls)->authorize(nuveiFacadePlacement());

    expect($chargeCalls[0]['params']['transactionType'])->toBe('Sale')
        ->and($chargeCalls[0]['params'])->not->toHaveKey('settleType')
        ->and($authorizeCalls[0]['params']['transactionType'])->toBe('Auth')
        ->and($authorizeCalls[0]['params']['settleType'])->toBe(0);
});

/**
 * A series payment reaches the same operation as an ordinary hold, and it has to keep its position
 * on the way — that is the whole reason it is a separate role.
 */
it('carries the series position through authorizeRebilling and on no other role', function () {
    $seriesCalls = [];
    nuveiFacadeRecordingGateway($seriesCalls)->authorizeRebilling(new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: nuveiTestPaymentMethod(),
        amount: new Money(1000, new Currency('USD')),
        initiation: PaymentInitiation::MerchantRecurring,
        genesisReference: 'genesis-1',
    ));

    $plainCalls = [];
    nuveiFacadeRecordingGateway($plainCalls)->authorize(nuveiFacadePlacement());

    expect($seriesCalls[0]['params']['isRebilling'])->toBe('1')
        ->and($seriesCalls[0]['params']['relatedTransactionId'])->toBe('genesis-1')
        ->and($plainCalls[0]['params'])->not->toHaveKey('isRebilling');
});

it('forwards the commanded fields into the placement it sends', function (string $role) {
    // The guarantee the old options-array tests were really after: a field the caller set has to
    // reach the body. The array could be dropped whole; a command cannot be, which is the defect
    // class {@see PlacementCommand} removes rather than re-tests.
    $calls = [];
    $gateway = nuveiFacadeRecordingGateway($calls);
    $command = nuveiFacadePlacement();

    $role === 'authorize' ? $gateway->authorize($command) : $gateway->charge($command);

    expect($calls[0]['params']['clientUniqueId'])->toBe('cuid-marker')
        ->and($calls[0]['params']['clientRequestId'])->toBe('cuid-marker')
        ->and($calls[0]['params']['dynamicDescriptor'])->toBe(['merchantName' => 'descriptor-marker'])
        ->and($calls[0]['params']['amount'])->toBe('10.00');
})->with(['charge', 'authorize']);

it('forwards the transaction a capture, refund, void and payout each act on', function () {
    $captureCalls = [];
    nuveiFacadeRecordingGateway($captureCalls)->capture(new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'txn-marker',
        amount: new Money(2500, new Currency('USD')),
        clientUniqueId: 'cuid-marker',
    ));

    $refundCommand = new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'txn-marker',
        amount: new Money(2500, new Currency('USD')),
        clientUniqueId: 'cuid-marker',
        retryInstrument: nuveiTestPaymentMethod(),
    );

    $refundCalls = [];
    nuveiFacadeRecordingGateway($refundCalls)->refund($refundCommand);

    $voidCalls = [];
    nuveiFacadeRecordingGateway($voidCalls)->cancel(new CancelCommand(GatewayId::generate(), 'txn-marker', 'cuid-marker'));

    $payoutCalls = [];
    nuveiFacadeRecordingGateway($payoutCalls)->retryRefund($refundCommand);

    expect($captureCalls[0]['params']['relatedTransactionId'])->toBe('txn-marker')
        ->and($captureCalls[0]['params']['clientUniqueId'])->toBe('cuid-marker')
        ->and($refundCalls[0]['params']['relatedTransactionId'])->toBe('txn-marker')
        ->and($voidCalls[0]['params']['relatedTransactionId'])->toBe('txn-marker')
        // A payout is independent of the original sale, so it names no related transaction at all —
        // it moves money from the merchant balance to the instrument the retry supplied.
        ->and($payoutCalls[0]['params'])->not->toHaveKey('relatedTransactionId')
        ->and($payoutCalls[0]['params']['userPaymentOption'])->toBe(['userPaymentOptionId' => 'upo-linked']);
});

/**
 * Only the retry carries the alternative instrument, and only the payout reads it. A plain refund
 * addresses the original transaction and has no instrument at all, which is why
 * {@see RefundCommand} keeps the two apart rather than letting one null field mean both.
 */
it('gives the alternative instrument to the payout and to nothing else', function () {
    $command = new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'txn-marker',
        amount: new Money(2500, new Currency('USD')),
    );

    $refundCalls = [];
    nuveiFacadeRecordingGateway($refundCalls)->refund($command);

    expect($refundCalls[0]['params']['relatedTransactionId'])->toBe('txn-marker');

    // Unreachable in production — RefundAdapter only calls retryRefund inside
    // `$request->retryInstrument !== null` — but the operation still has to say what is missing
    // rather than address a payout to nobody, and it has to THROW rather than answer.
    //
    // The throw is the part worth pinning. Assembling a body has never been able to answer as a
    // gateway refusal: omnipay's `send()` called `getData()` outside the try that wrapped
    // `sendData()`, so a payload that could not be built propagated. Folding it into a failed
    // result instead would let a wiring error be recorded as a refused refund.
    $payoutCalls = [];
    expect(fn () => nuveiFacadeRecordingGateway($payoutCalls)->retryRefund($command))
        ->toThrow(RuntimeException::class, 'the refund command carried none');
});

/**
 * A body that cannot be built propagates out of the role; it does not come back as a refusal.
 *
 * This is the single most important thing the operations inherited from omnipay's `send()`, which
 * ran `getData()` OUTSIDE the try that wrapped `sendData()`. Both exceptions below carry
 * {@see UnsupportedByGateway}, which the gateway stack rethrows while folding everything else into
 * a failed result — so catching one here would record a wiring error, or a 3DS attestation we
 * silently dropped the cryptogram from, as an issuer decline for a request no issuer ever saw.
 */
it('lets a payload it cannot build propagate instead of answering with a refusal', function (string $role, string $exception, string $fragment) {
    $calls = [];
    $gateway = nuveiFacadeRecordingGateway($calls);

    $attempt = match ($role) {
        // Nuvei takes card data only through tokenization; a payment carries the reference.
        'charge' => fn () => $gateway->charge(new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: nuveiTestCard(),
            amount: new Money(1000, new Currency('USD')),
        )),
        // An attestation that did not succeed carries no cavv and no eci, and Nuvei marks both
        // Required inside externalMpi.
        default => fn () => $gateway->authorize(new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: nuveiTestPaymentMethod(),
            amount: new Money(1000, new Currency('USD')),
            threeDS: new ThreeDSResult(
                ThreeDSStatus::NotAuthenticated,
                null,
                null,
                'ds-txn-abc',
                'acs-txn-def',
                ThreeDSVersion::V220,
            ),
        )),
    };

    expect($attempt)->toThrow($exception, $fragment)
        // And nothing was sent, which is the other half: the refusal happens before the acquirer
        // is involved at all.
        ->and($calls)->toBeEmpty();
})->with([
    ['charge', UnsupportedInstrument::class, 'does not accept a "card" instrument on the "purchase" operation'],
    ['authorize', IncompleteAuthentication::class, 'missing eci, cavv'],
]);

// ──────────────────────────────────────────────
//  refusals
// ──────────────────────────────────────────────

/**
 * Nuvei is an acquirer and issues no cards, so all three card-issuing
 * operations are structurally impossible. They exist at all because
 * {@see \Techork\PaymentService\Gateway\Contract\Gateway} declares them and
 * the gateway stack calls them on whatever gateway it holds — undeclared,
 * each would be a fatal `Call to undefined method` instead of a refusal.
 *
 * The message is asserted, not just the type, because the router surfaces it
 * to operators as the reason nothing happened; "Nuvei does not support …"
 * is actionable where a bare class name is not.
 */
it('refuses card issuing as a typed error rather than an undefined method', function (string $method) {
    $gateway = nuveiFacadeGateway();

    expect(fn () => nuveiCardInvoke($gateway, $method))
        ->toThrow(UnsupportedOperation::class, 'does not support the "'.$method.'" operation');
})->with(['issueVirtualCard', 'updateVirtualCard', 'terminateVirtualCard']);

it('refuses card issuing with the marker that stops it becoming a decline', function (string $method) {
    // The reason the type matters more than the message. These used to be a bare
    // RuntimeException, and the gateway stack rethrows only UnsupportedByGateway while
    // folding everything else into AuthorizationResult::failed() — so a card-issuing request
    // misrouted to Nuvei was recorded as PaymentIntentFailed, an acquirer decline for a
    // request no acquirer ever saw.
    $thrown = null;

    try {
        nuveiCardInvoke(nuveiFacadeGateway(), $method);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
})->with(['issueVirtualCard', 'updateVirtualCard', 'terminateVirtualCard']);

// ──────────────────────────────────────────────
//  customer resolution
// ──────────────────────────────────────────────

/**
 * Only the operations that need a `userTokenId` on the wire resolve a customer. The split is
 * pinned in both directions because it was not free when resolution could cost a createUser round
 * trip; it is a lookup now, and the split stays because capture, refund and void reference an
 * existing transaction and have no user to name.
 */
it('attaches the resolved customer reference to the operations that need one', function (string $role) {
    $calls = [];
    $gateway = nuveiFacadeRecordingGateway($calls, nuveiFacadeCustomerRepository(nuveiSuiteCustomerId()->toString()));

    match ($role) {
        'registerPaymentMethod' => $gateway->registerPaymentMethod(nuveiFacadeVault()),
        'authorize' => $gateway->authorize(nuveiFacadePlacement()),
        default => $gateway->charge(nuveiFacadePlacement()),
    };

    expect($calls[0]['params']['userTokenId'])->toBe(nuveiSuiteCustomerId()->toString());
})->with(['registerPaymentMethod', 'charge', 'authorize']);

/**
 * The defect this whole change exists to remove, stated as a test.
 *
 * `userTokenId` was the payer's **email**, and Nuvei documents that field as the id which
 * "uniquely identifies your consumer/user in your system" and requires it to reuse a stored
 * `userPaymentOptionId`. So a customer who changed their email became a different customer at
 * Nuvei and every payment option stored under the old value was orphaned; two people sharing an
 * address were one customer.
 *
 * Both payments below carry the same customer and different emails, and the token does not move.
 */
it('keeps the token when the email changes, because an email is not an identity', function () {
    $calls = [];
    $gateway = nuveiFacadeRecordingGateway($calls, nuveiFacadeCustomerRepository(nuveiSuiteCustomerId()->toString()));

    foreach (['first@example.com', 'second@example.com'] as $email) {
        $gateway->charge(nuveiFacadePlacement(
            new BillingAddress('Ada', 'Lovelace', '1 Street', 'Miami', new Country('US'), '33101', email: new Email($email)),
        ));
    }

    expect($calls[0]['params']['userTokenId'])->toBe(nuveiSuiteCustomerId()->toString())
        ->and($calls[1]['params']['userTokenId'])->toBe(nuveiSuiteCustomerId()->toString());
});

it('resolves no customer for tokenization, which links an instrument to nobody', function () {
    // Structural rather than conditional: {@see Tokenize} takes no customer reference at all, so
    // there is no branch in which a ccTempToken could acquire an owner.
    $constructor = new ReflectionClass(Tokenize::class)->getConstructor();
    $parameters = array_map(fn (ReflectionParameter $p): string => $p->getName(), $constructor?->getParameters() ?? []);

    expect($parameters)->not->toContain('customerReference');
});

/**
 * Resolution needs a repository and a customer named on the command; either missing means there
 * is nothing to look up. Each row omits exactly one, so a future short-circuit that collapses the
 * two checks into one cannot pass by accident.
 *
 * What is no longer in this list is an instrument. Resolution used to be keyed on one — which is
 * why a raw card could never resolve a customer and an expiring token could — and the key is the
 * person now, so an instrument has nothing to do with the answer.
 */
/**
 * Only one row left, and losing the other is the point.
 *
 * "No repository" used to be a case because the map arrived through a `setCustomerRepository()`
 * a driver could be built without. It arrives with `GatewayInfrastructure` now, once, typed, so a
 * configured gateway always has one and the absence stopped being expressible — the guard for it
 * went with the setter rather than sitting there unreachable. What remains is the real condition:
 * a payment that names nobody.
 *
 * What was never in this list is an instrument. Resolution used to be keyed on one — which is why
 * a raw card could never resolve a customer and an expiring token could — and the key is the
 * person now, so an instrument has nothing to do with the answer.
 */
it('skips resolution when the payment names no customer', function () {
    $gateway = nuveiFacadeGateway(customers: nuveiFacadeCustomerRepository('cust-reference'));

    $resolve = new ReflectionMethod($gateway, 'customerFor');

    expect($resolve->invoke($gateway, null))->toBe('');
});

/**
 * An empty-string reference counts as missing, not as a customer named ''. Legacy rows exist where
 * `customer_reference` was written as '', and an empty `userTokenId` makes Nuvei reject any
 * payment that references a stored `userPaymentOptionId` — so passing it through would turn a
 * repairable row into a decline. The field is omitted instead.
 */
it('treats an empty-string customer reference as missing', function () {
    $calls = [];
    nuveiFacadeRecordingGateway($calls, nuveiFacadeCustomerRepository(''))->charge(nuveiFacadePlacement());

    expect($calls[0]['params'])->not->toHaveKey('userTokenId');
});

/**
 * Taking a payment registers nobody, and this is the assertion that says so.
 *
 * Resolution used to be lookup-**or-create** and hung on `charge` and `authorize` as well as on
 * the registration, so a payment for an unlinked card built a Nuvei user out of whatever address
 * rode along with it — under that address's email as the token. Worse, the user it minted could
 * not own the instrument being charged: a `userPaymentOptionId` exists only under the token it was
 * stored against, so what was left behind was a stray user and a failed payment.
 *
 * A payment for a customer Nuvei has never been told about now sends no token at all, which for a
 * raw-card one-off is correct and for a stored instrument fails visibly at Nuvei.
 */
it('registers no customer while taking a payment, whatever the address carries', function () {
    $calls = [];
    $customers = nuveiFacadeCustomerRepository(null);

    nuveiFacadeRecordingGateway($calls, $customers)->charge(nuveiFacadePlacement(
        new BillingAddress('Ada', 'Lovelace', '1 Street', 'Miami', new Country('US'), '33101', email: new Email('ada@example.com')),
    ));

    expect($calls[0]['params'])->not->toHaveKey('userTokenId')
        ->and($customers->saved)->toBeEmpty()
        // One call, the payment. A createUser would be a second.
        ->and($calls)->toHaveCount(1);
});

