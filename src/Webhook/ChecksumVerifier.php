<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

use Override;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Webhook\Contract\InboundWebhook;

/**
 * Nuvei checksum verification. Pure protocol: the caller passes a single
 * candidate credential; we verify that (a) the request's merchant pair matches
 * this credential and (b) the checksum validates against `secretKey`.
 *
 * Nuvei ships three shapes; we handle all three:
 *
 *   - **DMN** (form-encoded body, no `EventCorrelationId`): checksum field in
 *     the body, computed as
 *     `sha256(secretKey + totalAmount + currency + responseTimeStamp
 *             + PPP_TransactionID + Status + productId)`.
 *
 *   - **Notification** (`EventCorrelationId` in the body, `checksum` in a
 *     header): computed as `sha256(secretKey + rawBody)`.
 *
 *   - **Event DMN** (a Control Panel event — a Chargeback, a Dispute API callback, an Ethoca alert:
 *     `EventType` in the body, `checksum` in a header, and **no merchant pair at all**): computed as
 *     `sha256(secretKey + the payload's values, concatenated in the order they are sent)`. This is
 *     the branch F4-notification needs, and it exists because the other two cannot answer for this
 *     shape: the form-encoded branch would hash the secret against six fields the event DMN does not
 *     carry, and the Notification branch needs `EventCorrelationId` in a *signed* body.
 *
 * ## The new branch is reachable only where the old code answered `false`, and that is structural
 *
 * Both implemented branches require the merchant pair — it is what identifies the tenant — and the
 * event DMN carries none. So the pair check is where the new branch belongs: every delivery that
 * verifies today has a pair, therefore takes the code below unchanged, and cannot have its answer
 * changed by this. A payment DMN (pair, no marker) and a JSON Notification (pair **and**
 * `EventCorrelationId`) both still pass the pair check and are decided by the branches they were
 * always decided by. The event branch is reached only by a delivery that has no pair, where the old
 * answer was `false` unconditionally.
 *
 * ## What the new branch cannot do, stated rather than implied
 *
 * With no merchant pair the **tenant is not matched**. It is identified by *which* candidate
 * credential's secret validates the header — the registry's existing multi-tenant loop, which the
 * class docblock below already assigns to the caller. For this delivery shape the checksum is
 * therefore the whole of the authentication, and it is a strong one: a delivery that verifies is one
 * that was signed with a secret this service holds.
 *
 * ## UNCONFIRMED — the concatenation, and how the values are read
 *
 * The scheme is quoted from the event-DMN page and is a *reading* of it, not a captured payload:
 * "concatenate into a string all of the parameter values in the JSON payload of the DMN in the exact
 * order that they are sent. Add `merchantSecretKey` to the beginning". Two things follow from the
 * words and neither is confirmed by one:
 *
 *  - **Nested objects are walked into**, in document order, because a nested object is a parameter
 *    whose value is an object and its members are the values there are. This is one small private
 *    method precisely so that a captured payload swaps the *input* of the comparison rather than the
 *    branch that makes it — including if the scheme turns out to be the raw body after all.
 *  - **The values are read after JSON decoding**, so a numeric literal's original spelling is not
 *    recoverable — `25.00` unquoted decodes to a float and concatenates as `25`. The documented
 *    examples state amounts as strings, and a payload that states one as a number may therefore
 *    fail to verify. That failure is a `false` on a delivery nobody can authenticate, which is the
 *    safe direction; a captured payload settles it.
 *
 * Multi-tenant iteration over candidate credentials is the caller's job.
 */
final readonly class ChecksumVerifier implements SignatureVerifier
{
    /** The fields an event DMN carries and no payment delivery does — see {@see self::isEventDmn()}. */
    private const array EVENT_DMN_MARKERS = ['EventType', 'EventCorrelationId'];

    #[Override]
    public function verify(InboundWebhook $webhook, GatewayCredential $gateway): bool
    {
        $payload = $webhook->fields();

        // Nuvei sends the merchant pair under two different shapes depending
        // on the delivery channel: camelCase in JSON Notifications and
        // snake_case in form-encoded DMN posts.
        $merchantId = (string) ($payload['merchantId'] ?? $payload['merchant_id'] ?? '');
        $merchantSiteId = (string) ($payload['merchantSiteId'] ?? $payload['merchant_site_id'] ?? '');
        if ($merchantId === '' || $merchantSiteId === '') {
            // A delivery with no pair is not one of ours to match — unless it is an event DMN, which
            // carries no pair by design. Today's answer is kept for everything else, unchanged.
            return $this->isEventDmn($payload) && $this->verifyEventDmn($webhook, $gateway);
        }

        $credentials = $gateway->getCredentials();
        if (($credentials['merchant_id'] ?? null) !== $merchantId
            || ($credentials['site_id'] ?? null) !== $merchantSiteId) {
            return false;
        }

        $secret = $credentials['secret_key'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if (isset($payload['EventCorrelationId'])) {
            return $this->verifyNotification($webhook, $secret);
        }

        return $this->verifyDmn($payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifyDmn(array $payload, string $secret): bool
    {
        $expected = hash('sha256', implode('', [
            $secret,
            (string) ($payload['totalAmount'] ?? ''),
            (string) ($payload['currency'] ?? ''),
            (string) ($payload['responseTimeStamp'] ?? ''),
            (string) ($payload['PPP_TransactionID'] ?? ''),
            (string) ($payload['Status'] ?? ''),
            (string) ($payload['productId'] ?? ''),
        ]));

        $received = strtolower((string) ($payload['advanceResponseChecksum'] ?? $payload['responseChecksum'] ?? ''));

        return hash_equals($expected, $received);
    }

    private function verifyNotification(InboundWebhook $webhook, string $secret): bool
    {
        $received = strtolower($webhook->header('checksum'));
        if ($received === '') {
            return false;
        }

        $body = $webhook->body;
        $expected = hash('sha256', $secret.$body);

        return hash_equals($expected, $received);
    }

    /**
     * Whether a pair-less delivery is an event DMN rather than a malformed delivery of another shape.
     *
     * A marker field alone is the test, and it is enough because the pair's absence has already been
     * established by the caller: a payment DMN carries the pair and no marker, a Notification carries
     * both, and this shape carries a marker and no pair. A delivery with neither is not one of the
     * three, and answering `false` for it is what the code did before the branch existed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isEventDmn(array $payload): bool
    {
        foreach (self::EVENT_DMN_MARKERS as $marker) {
            if (isset($payload[$marker])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event-DMN scheme, over the values the caller's credential's secret signed — see the class
     * docblock for what is confirmed about this and what is not.
     *
     * The header may be absent (`''`), which is a delivery nobody can authenticate and answers the
     * same as a wrong one; a credential with no secret answers the same way, as it does in the two
     * branches above.
     */
    private function verifyEventDmn(InboundWebhook $webhook, GatewayCredential $gateway): bool
    {
        $received = strtolower($webhook->header('checksum'));
        if ($received === '') {
            return false;
        }

        $secret = $gateway->getCredentials()['secret_key'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals($this->eventDmnExpected($webhook->fields(), $secret), $received);
    }

    /**
     * The value the header is compared against: the secret, then every value in the payload in the
     * order the payload states them, nested objects walked into depth-first.
     *
     * Deliberately its own method so that a captured payload changes this line's *input* and nothing
     * else — see the class docblock's UNCONFIRMED note.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventDmnExpected(array $payload, string $secret): string
    {
        return hash('sha256', $secret.implode('', array_map(self::parameterValue(...), array_values($payload))));
    }

    /**
     * One parameter's value as the concatenation states it.
     *
     * Strings are taken as they are; a nested object is the concatenation of its own values, in its
     * own order; a boolean is spelled as the JSON it arrived as, since `(string) true` would be `1`
     * and `false` would be nothing at all. `null` contributes nothing, which is the only reading that
     * keeps a stated null from shifting every value after it.
     */
    private static function parameterValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode('', array_map(self::parameterValue(...), array_values($value)));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? '' : (string) $value;
    }
}
