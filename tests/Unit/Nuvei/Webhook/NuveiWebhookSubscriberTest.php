<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayAuthorizationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Nuvei\NuveiGateway;
use Techork\PaymentService\Nuvei\Webhook\ChecksumVerifier;
use Techork\PaymentService\Nuvei\Webhook\EventParser;
use Techork\PaymentService\Nuvei\Webhook\Handler\AuthHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\ChargebackHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\CreditHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\PaymentMethodCreationHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\SaleHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\SettleHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\VoidHandler;
use Techork\PaymentService\Nuvei\Webhook\NuveiWebhookSubscriber;

/**
 * {@see NuveiWebhookSubscriber} is the only place the gateway kind, the verifier,
 * the parser and six handlers are brought together, and it was unexecuted. The
 * real {@see VerifierRegistry}, {@see HandlerRegistry} and {@see WebhookRouter}
 * are driven here rather than doubles, because every failure mode this class can
 * have lives BETWEEN the classes: a kind the registry is keyed by that the
 * gateway never reports, a handler bound to an event type the parser never
 * emits, a constructor nobody can satisfy. Mocked registries would answer
 * whatever they were asked and prove none of it.
 *
 * The one thing kept as test doubles is the recorder/resolver layer the handlers
 * depend on — those are the persistence boundary, and the handlers have tests of
 * their own for their behaviour against it.
 *
 * Payload and checksum helpers are re-declared here under their own prefix
 * instead of reaching into ChecksumVerifierTest's: Pest helpers are global, so
 * borrowing one would make this file pass only when the other happens to be in
 * the same run.
 */
function nuveiWiringSecret(): string
{
    return 'sek_'.bin2hex(random_bytes(8));
}

function nuveiWiringCredential(string $secret): GatewayCredential
{
    return new readonly class($secret) implements GatewayCredential
    {
        public function __construct(private string $secret) {}

        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-0000000000c1');
        }

        public function getGatewayName(): string
        {
            return 'nuvei';
        }

        public function getCredentials(): array
        {
            return [
                'merchant_id' => 'mid-wire',
                'site_id' => 'site-wire',
                'secret_key' => $this->secret,
            ];
        }
    };
}

/**
 * A DMN body, the form-encoded shape Nuvei posts. `totalAmount` is non-zero so
 * an Auth here is a real authorization rather than the zero-amount tokenization
 * variant the parser splits off.
 */
function nuveiWiringPayload(string $transactionType = 'Void', string $totalAmount = '25.00'): array
{
    return [
        'merchantId' => 'mid-wire',
        'merchantSiteId' => 'site-wire',
        'transactionType' => $transactionType,
        'Status' => 'APPROVED',
        'PPP_TransactionID' => '778899',
        'TransactionID' => '1110000000123456',
        'relatedTransactionId' => '1110000000123400',
        'clientUniqueId' => '01929fa5-0000-7000-8000-000000000009:cancel',
        'totalAmount' => $totalAmount,
        'currency' => 'USD',
        'responseTimeStamp' => '2026-08-04.11:22:33',
        'productId' => 'prod-1',
    ];
}

/** The DMN checksum, over the field order the verifier expects. */
function nuveiWiringSign(array $payload, string $secret): string
{
    return hash('sha256', implode('', [
        $secret,
        (string) $payload['totalAmount'],
        (string) $payload['currency'],
        (string) $payload['responseTimeStamp'],
        (string) $payload['PPP_TransactionID'],
        (string) $payload['Status'],
        (string) $payload['productId'],
    ]));
}

function nuveiWiringRequest(array $payload, string $checksum): ServerRequest
{
    $signed = [...$payload, 'advanceResponseChecksum' => $checksum];

    return (new ServerRequest(
        'POST',
        'https://merchant.example/webhooks/nuvei',
        [],
        http_build_query($signed),
    ))->withParsedBody($signed);
}

/**
 * A Chargeback delivery — the event channel, in the shape the parser now distinguishes from a payment
 * DMN: the envelope's `EventType` and **no** `transactionType`.
 *
 * Minimal rather than the documented field list in full, which is the handler's own test's job. This
 * one is about the wiring: that a stored delivery of this shape reaches the handler written for it and
 * comes out as a fact on the recorder.
 */
function nuveiWiringChargebackPayload(): array
{
    return [
        'EventId' => '1000000001',
        'EventDateUTC' => '2026-08-04 11:22:33',
        'EventType' => 'Chargeback',
        'Chargeback' => [
            'Type' => 'Chargeback',
            'DisputeId' => 'dc_1000000001',
            'DisputeEventId' => 'dce_1000000001',
            'DisputeUnifiedStatusCode' => 'FC',
            'ChargebackReason' => '10.4 - Other Fraud-Card Absent Environment',
            'Amount' => '25.00',
            'Currency' => 'USD',
            'DisputeDueDate' => '2026-08-20',
        ],
        'TransactionDetails' => [
            'TransactionId' => '1110000000123456',
            'ClientUniqueId' => '01929fa5-0000-7000-8000-000000000009',
        ],
    ];
}

/** The dispute recorder, stubbed, keeping whatever it was handed for the assertions. */
function nuveiWiringDisputeRecorder(?DisputeSnapshot &$captured): GatewayDisputeRecorder
{
    $recorder = Mockery::mock(GatewayDisputeRecorder::class);
    $recorder->shouldReceive('onDisputeObserved')->once()
        ->andReturnUsing(function ($gatewayId, $paymentIntentId, DisputeSnapshot $snapshot) use (&$captured): RecorderOutcome {
            $captured = $snapshot;

            return RecorderOutcome::Applied;
        });

    return $recorder;
}

/**
 * The subscriber with every handler real and only the persistence boundary
 * mocked. Constructing all seven is itself part of what is pinned — the subscriber
 * declares them by concrete type, so a handler whose constructor changed shape
 * would fail here.
 */
function nuveiWiringSubscriber(
    ?TransactionIdResolver $resolver = null,
    ?GatewayCancellationRecorder $cancellation = null,
    ?GatewayDisputeRecorder $dispute = null,
): NuveiWebhookSubscriber {
    return new NuveiWebhookSubscriber(
        new ChecksumVerifier,
        new EventParser,
        new AuthHandler(
            Mockery::mock(GatewayAuthorizationRecorder::class),
            Mockery::mock(GatewayFailureRecorder::class),
            Mockery::mock(GatewayPaymentMethodRecorder::class),
            Mockery::mock(LoggerInterface::class),
        ),
        new PaymentMethodCreationHandler(Mockery::mock(GatewayPaymentMethodRecorder::class)),
        new SaleHandler(
            Mockery::mock(GatewaySuccessRecorder::class),
            Mockery::mock(GatewayFailureRecorder::class),
        ),
        new SettleHandler(
            Mockery::mock(GatewaySuccessRecorder::class),
            Mockery::mock(GatewayFeeRecorder::class),
        ),
        new CreditHandler(
            Mockery::mock(TransactionIdResolver::class),
            Mockery::mock(RefundProcessingRecorder::class),
            Mockery::mock(RefundFailureRecorder::class),
            Mockery::mock(GatewayFeeRecorder::class),
        ),
        new VoidHandler(
            $resolver ?? Mockery::mock(TransactionIdResolver::class),
            $cancellation ?? Mockery::mock(GatewayCancellationRecorder::class),
        ),
        // The same resolver instance the void handler gets, which is what the container does: it is one
        // binding, and a second mock here would hide a handler reaching for the wrong dependency.
        new ChargebackHandler(
            $resolver ?? Mockery::mock(TransactionIdResolver::class),
            $dispute ?? Mockery::mock(GatewayDisputeRecorder::class),
        ),
    );
}

/** Single-tenant repository, as the router's candidate iteration sees it. */
function nuveiWiringRepository(GatewayCredential $credential): GatewayCredentialRepository
{
    return new readonly class($credential) implements GatewayCredentialRepository
    {
        public function __construct(private GatewayCredential $credential) {}

        public function findOrFail(GatewayId $gatewayId): GatewayCredential
        {
            return $this->credential;
        }

        public function all(): iterable
        {
            return [$this->credential];
        }
    };
}

/** @return array{VerifierRegistry, HandlerRegistry} */
function nuveiWiringRegistries(?NuveiWebhookSubscriber $subscriber = null): array
{
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    ($subscriber ?? nuveiWiringSubscriber())->subscribe($verifiers, $handlers);

    return [$verifiers, $handlers];
}

it('registers the verifier and parser under the kind the gateway reports', function () {
    // The router looks kinds up by GatewayCredential::getGatewayName(), which for
    // this package is NuveiGateway::getName() = 'nuvei', while the subscriber
    // registers the literal 'Nuvei'. The registry lowercases both ends; pinned
    // because if the two spellings ever stop meeting, every Nuvei DMN resolves to
    // no verifier and is dropped without a trace.
    [$verifiers] = nuveiWiringRegistries();

    $kind = new NuveiGateway()->getName();

    expect($verifiers->hasKind($kind))->toBeTrue()
        ->and($verifiers->verifier($kind))->toBeInstanceOf(ChecksumVerifier::class)
        ->and($verifiers->parser($kind))->toBeInstanceOf(EventParser::class);
});

it('points each DMN event type at the handler written for it', function (string $eventType, string $handlerClass) {
    // The keys are the parser's own constants, so the two halves cannot drift
    // apart silently — a renamed constant fails to compile this test rather than
    // quietly registering a type nothing emits. Auth is the interesting pair:
    // a zero-amount Auth is Nuvei's tokenization flow and must reach the
    // payment-method handler, not the authorization one. The chargeback is the
    // one row here that is an *event* DMN's `EventType` rather than a payment
    // DMN's `transactionType` — the two channels the parser distinguishes.
    [, $handlers] = nuveiWiringRegistries();

    expect($handlers->resolve('nuvei', $eventType))->toBeInstanceOf($handlerClass);
})->with([
    'authorization' => [EventParser::TYPE_AUTH, AuthHandler::class],
    'zero-amount tokenization' => [EventParser::TYPE_AUTH_PAYMENT_METHOD, PaymentMethodCreationHandler::class],
    'sale' => [EventParser::TYPE_SALE, SaleHandler::class],
    'settle' => [EventParser::TYPE_SETTLE, SettleHandler::class],
    'credit' => [EventParser::TYPE_CREDIT, CreditHandler::class],
    'void' => [EventParser::TYPE_VOID, VoidHandler::class],
    'chargeback' => [EventParser::TYPE_CHARGEBACK, ChargebackHandler::class],
]);

it('registers no handler for a transaction type we do not act on', function (string $eventType) {
    // Nuvei sends more types than we map. They must resolve to no handler so the
    // router reports Skipped, rather than being retried forever or run through a
    // handler meant for something else. The empty string is included because it
    // is what the parser emits for a DMN with no transactionType at all.
    //
    // This row used to be `Chargeback`, which was this service's example of a
    // type it deliberately did not act on; the chargeback now has a handler, so
    // the example moves to a spelling no Nuvei delivery carries — the parser
    // uses it as an id prefix, never as a type. That is a stronger statement
    // than the old row made: the registry's null is a genuine absence rather
    // than a list of known types it happens not to contain.
    [, $handlers] = nuveiWiringRegistries();

    expect($handlers->resolve('nuvei', $eventType))->toBeNull();
})->with([
    'unknown' => 'Unknown',
    'no transaction type' => '',
]);

it('identifies the tenant and the idempotency key from a checksummed DMN', function () {
    // End to end over the real router: checksum verification against the
    // credential, kind resolution, and the composite external id the parser
    // builds. This is the path a live delivery takes before anything is stored.
    $secret = nuveiWiringSecret();
    $payload = nuveiWiringPayload();
    $credential = nuveiWiringCredential($secret);

    [$verifiers, $handlers] = nuveiWiringRegistries();
    $router = new WebhookRouter(nuveiWiringRepository($credential), $verifiers, $handlers);

    $match = $router->identifyGateway(nuveiWiringRequest($payload, nuveiWiringSign($payload, $secret)));

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('nuvei')
        // The type is part of the key on purpose: Nuvei reuses one
        // PPP_TransactionID across the Auth and the Settle of a payment, so the
        // id alone would make the second delivery look like a replay of the first.
        ->and($match->externalId)->toBe('Void:778899')
        ->and($match->gatewayId->equals($credential->getId()))->toBeTrue();
});

it('identifies no tenant when the DMN is not checksummed for any candidate', function () {
    // The rejection has to survive the wiring: a forged delivery must leave
    // identifyGateway with null so nothing is stored or dispatched under a tenant
    // it does not belong to.
    $payload = nuveiWiringPayload();
    $credential = nuveiWiringCredential(nuveiWiringSecret());

    [$verifiers, $handlers] = nuveiWiringRegistries();
    $router = new WebhookRouter(nuveiWiringRepository($credential), $verifiers, $handlers);

    // Signed with a secret no configured tenant holds.
    $forged = nuveiWiringRequest($payload, nuveiWiringSign($payload, nuveiWiringSecret()));

    expect($router->identifyGateway($forged))->toBeNull();
});

it('dispatches a stored Void DMN through the parser into its handler', function () {
    // The other half of the chain, from the stored record onwards. The parser hands
    // the handler a NuveiEvent; the handler digs relatedTransactionId out of it. A
    // DTO mismatch between the two would be invisible to per-class tests and would
    // leave every confirmed void unapplied.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $reference): bool => $reference === '1110000000123400')
        ->andReturn('01929fa5-0000-7000-8000-000000000009');

    $cancellation = Mockery::mock(GatewayCancellationRecorder::class);
    $cancellation->shouldReceive('onGatewayCancellation')->once()->andReturn(RecorderOutcome::Applied);

    [$verifiers, $handlers] = nuveiWiringRegistries(nuveiWiringSubscriber($resolver, $cancellation));

    $router = new WebhookRouter(
        nuveiWiringRepository(nuveiWiringCredential(nuveiWiringSecret())),
        $verifiers,
        $handlers,
    );

    $outcome = $router->dispatch(new StoredWebhookCall('nuvei', GatewayId::generate(), nuveiWiringPayload()));

    expect($outcome)->toBe(HandlerOutcome::Processed);
});

it('routes a zero-amount Auth to tokenization rather than to authorization', function () {
    // The registry split only pays off if the parser actually reaches the other
    // key. Driven through the router so the parser's zero-amount branch and the
    // registration are proven to agree: the authorization recorder is asserted
    // never to be touched, which is what would happen if both types resolved to
    // AuthHandler.
    $authorization = Mockery::mock(GatewayAuthorizationRecorder::class);
    $authorization->shouldNotReceive('onGatewayAuthorization');

    $subscriber = new NuveiWebhookSubscriber(
        new ChecksumVerifier,
        new EventParser,
        new AuthHandler(
            $authorization,
            Mockery::mock(GatewayFailureRecorder::class),
            Mockery::mock(GatewayPaymentMethodRecorder::class),
            Mockery::mock(LoggerInterface::class),
        ),
        new PaymentMethodCreationHandler(Mockery::mock(GatewayPaymentMethodRecorder::class)),
        new SaleHandler(Mockery::mock(GatewaySuccessRecorder::class), Mockery::mock(GatewayFailureRecorder::class)),
        new SettleHandler(Mockery::mock(GatewaySuccessRecorder::class), Mockery::mock(GatewayFeeRecorder::class)),
        new CreditHandler(
            Mockery::mock(TransactionIdResolver::class),
            Mockery::mock(RefundProcessingRecorder::class),
            Mockery::mock(RefundFailureRecorder::class),
            Mockery::mock(GatewayFeeRecorder::class),
        ),
        new VoidHandler(Mockery::mock(TransactionIdResolver::class), Mockery::mock(GatewayCancellationRecorder::class)),
        new ChargebackHandler(Mockery::mock(TransactionIdResolver::class), Mockery::mock(GatewayDisputeRecorder::class)),
    );

    [$verifiers, $handlers] = nuveiWiringRegistries($subscriber);

    $router = new WebhookRouter(
        nuveiWiringRepository(nuveiWiringCredential(nuveiWiringSecret())),
        $verifiers,
        $handlers,
    );

    // A zero-amount Auth carrying no userPaymentOptionId: the tokenization handler
    // has nothing to store, so Skipped is its answer — and reaching it at all is
    // the point.
    $outcome = $router->dispatch(new StoredWebhookCall(
        'nuvei',
        GatewayId::generate(),
        [...nuveiWiringPayload('Auth', '0'), 'userPaymentOptionId' => ''],
    ));

    expect($outcome)->toBe(HandlerOutcome::Skipped);
});

it('skips a stored DMN whose transaction type has no registered handler', function () {
    // Unmapped types must come back Skipped — neither retried forever nor
    // mistaken for a payment.
    [$verifiers, $handlers] = nuveiWiringRegistries();

    $router = new WebhookRouter(
        nuveiWiringRepository(nuveiWiringCredential(nuveiWiringSecret())),
        $verifiers,
        $handlers,
    );

    expect($router->dispatch(new StoredWebhookCall(
        'nuvei',
        GatewayId::generate(),
        nuveiWiringPayload('Frobnicate'),
    )))->toBe(HandlerOutcome::Skipped);
});

it('dispatches a stored Chargeback DMN through the parser into the dispute recorder', function () {
    // This delivery used to be this file's example of one the router *skips*. It is now the one the
    // subscription exists for, so the pin is inverted: end to end over the real router, the real
    // parser and the real handler, from a stored event-DMN payload to the snapshot a recorder is
    // handed.
    //
    // It is here rather than only in the handler's own test because the half each of those cannot see
    // is this one: the parser routing the *event* channel by `EventType`, the registry having a handler
    // under that key, and the handler's DTO reading a payload the parser built rather than an array the
    // test wrote. Every one of those is a seam between classes.
    $paymentIntentId = '01929fa5-0000-7000-8000-000000000009';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->once()->andReturn($paymentIntentId);

    $recorder = nuveiWiringDisputeRecorder($captured);

    [$verifiers, $handlers] = nuveiWiringRegistries(nuveiWiringSubscriber($resolver, null, $recorder));

    $router = new WebhookRouter(
        nuveiWiringRepository(nuveiWiringCredential(nuveiWiringSecret())),
        $verifiers,
        $handlers,
    );

    $outcome = $router->dispatch(new StoredWebhookCall(
        'nuvei',
        GatewayId::generate(),
        nuveiWiringChargebackPayload(),
    ));

    expect($outcome)->toBe(HandlerOutcome::Processed)
        ->and($captured)->toBeInstanceOf(DisputeSnapshot::class);

    // The case as it arrived, with both the provider's own code and the two spellings the package
    // owning Nuvei's vocabulary read out of it — which is the whole of what F4-notification can carry
    // on classic API 1.0, where reading and answering a case are Control Panel work.
    expect($captured?->gatewayDisputeRef)->toBe('dc_1000000001')
        ->and($captured?->providerEventKey)->toBe('dce_1000000001')
        ->and($captured?->reasonCode)->toBe('10.4')
        ->and($captured?->cardBrand)->toBe(CardBrand::Visa)
        ->and($captured?->stage)->toBe('chargeback')
        ->and($captured?->status)->toBe('needs_response')
        ->and($captured?->stageCode)->toBe('FC')
        ->and($captured?->responseDueAt?->format('Y-m-d'))->toBe('2026-08-20');
});
