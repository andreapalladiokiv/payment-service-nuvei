<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Webhook\Contract\InboundWebhook;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Nuvei\Webhook\ChecksumVerifier;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function checksumCredential(string $merchantId, string $siteId, string $secret): GatewayCredential
{
    return new readonly class($merchantId, $siteId, $secret) implements GatewayCredential
    {
        public function __construct(
            private string $merchantId,
            private string $siteId,
            private string $secret,
        ) {}

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
            return [
                'merchant_id' => $this->merchantId,
                'site_id' => $this->siteId,
                'secret_key' => $this->secret,
            ];
        }
    };
}

function dmnRequest(array $payload): InboundWebhook
{
    return InboundWebhook::from(
        (new Psr17Factory)->createServerRequest('POST', '/webhooks')->withParsedBody($payload),
    );
}

/**
 * A request whose checksum rides in the `checksum` header — the shape both the JSON Notification and
 * the event DMN authenticate with. The body is passed in bytes where a scheme hashes it.
 *
 * A `null` checksum sends no header at all, which is a different delivery from one that sends an empty
 * one; both are exercised, because a scheme that answered `true` for an unsigned delivery would be a
 * hole either way round.
 */
function headerChecksumRequest(array $payload, ?string $checksum, string $body = ''): InboundWebhook
{
    $request = (new Psr17Factory)->createServerRequest('POST', '/webhooks')
        ->withParsedBody($payload)
        ->withBody((new Psr17Factory)->createStream($body));

    if ($checksum !== null) {
        $request = $request->withHeader('checksum', $checksum);
    }

    return InboundWebhook::from($request);
}

it('accepts a DMN with a valid advanceResponseChecksum', function () {
    $secret = 'super-secret';
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'totalAmount' => '10.00',
        'currency' => 'USD',
        'responseTimeStamp' => '20260423000000',
        'PPP_TransactionID' => 'ppp_x',
        'Status' => 'APPROVED',
        'productId' => 'prod',
    ];
    $payload['advanceResponseChecksum'] = hash('sha256', implode('', [
        $secret, '10.00', 'USD', '20260423000000', 'ppp_x', 'APPROVED', 'prod',
    ]));

    expect((new ChecksumVerifier)->verify(dmnRequest($payload), checksumCredential('1', '2', $secret)))->toBeTrue();
});

it('rejects DMN when the merchant pair does not match the credential', function () {
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'advanceResponseChecksum' => 'irrelevant',
    ];

    expect((new ChecksumVerifier)->verify(dmnRequest($payload), checksumCredential('99', '99', 's')))->toBeFalse();
});

it('rejects DMN with a tampered checksum', function () {
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'totalAmount' => '10',
        'currency' => 'USD',
        'responseTimeStamp' => 'ts',
        'PPP_TransactionID' => 'ppp',
        'Status' => 'APPROVED',
        'productId' => 'p',
        'advanceResponseChecksum' => 'deadbeef',
    ];

    expect((new ChecksumVerifier)->verify(dmnRequest($payload), checksumCredential('1', '2', 'secret')))->toBeFalse();
});

it('accepts a Notification with a valid body-based checksum header', function () {
    $secret = 'super-secret';
    $body = '{"EventCorrelationId":"ec_1","merchantId":"1","merchantSiteId":"2"}';
    $expected = hash('sha256', $secret.$body);

    $request = InboundWebhook::from(
        (new Psr17Factory)->createServerRequest('POST', '/webhooks')
            ->withHeader('checksum', $expected)
            ->withParsedBody(json_decode($body, true))
            ->withBody((new Psr17Factory)->createStream($body)),
    );

    expect((new ChecksumVerifier)->verify($request, checksumCredential('1', '2', $secret)))->toBeTrue();
});

it('accepts an event DMN, which carries no merchant pair and signs the values it sends', function () {
    // The Chargeback delivery's shape: a Control Panel event, no `merchantId` / `merchantSiteId`
    // anywhere, and the checksum in a header over the payload's values in the order they are sent —
    // the concatenation is written out literally below rather than computed by the code under test.
    //
    // The credential's merchant ids are deliberately irrelevant here and never compared: with no pair
    // in the delivery there is nothing to match them against, and the secret is the whole of the
    // authentication — see the verifier's docblock. That is what makes this branch safe to add: a
    // delivery only verifies if it was signed with a secret this service holds.
    $secret = 'super-secret';
    $payload = [
        'EventId' => '1000000001',
        'EventType' => 'Chargeback',
        'Chargeback' => [
            'Type' => 'Chargeback',
            'DisputeId' => 'dc_1000000001',
        ],
    ];
    // EventId, EventType, then the nested object's own values in their own order.
    $expected = hash('sha256', $secret.'1000000001'.'Chargeback'.'Chargeback'.'dc_1000000001');

    expect((new ChecksumVerifier)->verify(
        headerChecksumRequest($payload, $expected),
        checksumCredential('1', '2', $secret),
    ))->toBeTrue();
});

it('reads either envelope marker as the event-channel discriminator', function (string $marker) {
    // The two fields the event envelope carries that no payment delivery does. `EventType` is the one a
    // Chargeback states and `EventCorrelationId` is the other field of the same envelope, which the
    // documentation lists for event DMNs generally — so either identifies the shape, and requiring the
    // one the Chargeback happens to send would refuse every other event type.
    $secret = 'super-secret';
    $payload = [
        'EventId' => '1',
        $marker => 'marker-value',
        'Chargeback' => ['DisputeId' => 'dc_1'],
    ];
    $expected = hash('sha256', $secret.'1'.'marker-value'.'dc_1');

    expect((new ChecksumVerifier)->verify(
        headerChecksumRequest($payload, $expected),
        checksumCredential('1', '2', $secret),
    ))->toBeTrue();
})->with([
    'an event type' => ['EventType'],
    'an event correlation id' => ['EventCorrelationId'],
]);

it('rejects an event DMN when the payload and the header disagree', function () {
    // A value changed after the fact, with the header left as it was: the only way a forged or edited
    // delivery can present itself. Signed for `dc_1`, delivered as `dc_2`.
    $secret = 'super-secret';
    $signed = ['EventId' => '1', 'EventType' => 'Chargeback', 'Chargeback' => ['DisputeId' => 'dc_1']];
    $header = hash('sha256', $secret.'1'.'Chargeback'.'dc_1');

    $tampered = ['EventId' => '1', 'EventType' => 'Chargeback', 'Chargeback' => ['DisputeId' => 'dc_2']];

    expect((new ChecksumVerifier)->verify(
        headerChecksumRequest($tampered, $header),
        checksumCredential('1', '2', $secret),
    ))->toBeFalse()
        // …and the delivery it was signed for does verify, so the rejection above is about the edit and
        // not about the payload being unreadable.
        ->and((new ChecksumVerifier)->verify(
            headerChecksumRequest($signed, $header),
            checksumCredential('1', '2', $secret),
        ))->toBeTrue();
});

it('rejects an event DMN that carries no checksum header, or an empty one', function (?string $checksum) {
    // Both deliveries are unsigned, and the guard answers `false` before any comparison happens — which
    // is the point: a scheme that compared an unsigned delivery against a computed value would be
    // admitting anything that happened to concatenate to nothing. `null` sends no header and `''` sends
    // an empty one; `InboundWebhook::header()` collapses them to the same string, so they are checked
    // as one behaviour rather than presented as two.
    $payload = ['EventId' => '1', 'EventType' => 'Chargeback'];

    expect((new ChecksumVerifier)->verify(
        headerChecksumRequest($payload, $checksum),
        checksumCredential('1', '2', 'super-secret'),
    ))->toBeFalse();
})->with([
    'no header' => [null],
    'an empty header' => [''],
]);

it('rejects a delivery with no merchant pair that is not an event DMN, whatever header it carries', function () {
    // The negative that makes the new branch's reachability argument a test rather than a claim: a
    // pair-less delivery carrying none of the event envelope's fields must still be rejected — even
    // when the header is exactly what the event scheme would compute for it. Without this, "no pair"
    // would mean "try the event scheme", and any unrecognised delivery shape would be admitted to a
    // branch written for one.
    $secret = 'super-secret';
    $payload = ['totalAmount' => '10.00', 'currency' => 'USD', 'Status' => 'APPROVED'];
    $eventStyleHeader = hash('sha256', $secret.'10.00'.'USD'.'APPROVED');

    expect((new ChecksumVerifier)->verify(
        headerChecksumRequest($payload, $eventStyleHeader),
        checksumCredential('1', '2', $secret),
    ))->toBeFalse();
});

it('still decides a payment DMN with a merchant pair by the DMN branch, not the event one', function () {
    // The other half of reachability, from this side of the line: a pair changes which branch answers,
    // and the DMN branch hashes six named fields rather than a value walk — so a header computed the
    // event way does not verify here, and the body field the DMN scheme reads does.
    $secret = 'super-secret';
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'totalAmount' => '10.00',
        'currency' => 'USD',
        'responseTimeStamp' => '20260423000000',
        'PPP_TransactionID' => 'ppp_x',
        'Status' => 'APPROVED',
        'productId' => 'prod',
    ];

    expect((new ChecksumVerifier)->verify(
        headerChecksumRequest($payload, hash('sha256', $secret.implode('', array_values($payload)))),
        checksumCredential('1', '2', $secret),
    ))->toBeFalse()
        ->and((new ChecksumVerifier)->verify(
            dmnRequest([...$payload, 'advanceResponseChecksum' => hash('sha256', implode('', [
                $secret, '10.00', 'USD', '20260423000000', 'ppp_x', 'APPROVED', 'prod',
            ]))]),
            checksumCredential('1', '2', $secret),
        ))->toBeTrue();
});
