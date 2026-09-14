<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

use Override;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser as EventParserContract;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;

/**
 * Parses a Nuvei DMN payload into a {@see NuveiEvent}. Zero-amount Auth is
 * Nuvei's tokenization flow and is surfaced as a distinct event type so the
 * handler registry can point it at a different handler than a real auth.
 *
 * ## Two channels, and the discriminator between them
 *
 * A **payment DMN** is form-encoded, carries `transactionType`, and names its transaction in
 * `PPP_TransactionID`. A **Control Panel event DMN** — a Chargeback, a Dispute API callback, an
 * Ethoca alert — is JSON, carries the envelope's `EventType`, and has no `transactionType` at all.
 *
 * Until this class knew that, the second shape took the payment path and came out with type `''`
 * and an id built from `uniqid()`: unroutable, and keyed on a random value so that no redelivery
 * could ever be recognised as one. Both halves of that were silent — the registry answers `null`
 * for an unknown type and the router reports `Skipped`, which is also what it reports for a
 * delivery it has genuinely finished with. The distinction below is therefore one line and it is
 * load-bearing: `transactionType` absent *and* `EventType` present is the event channel, and the
 * payment path is then untouched byte for byte.
 *
 * The envelope's presence is used as a discriminator rather than a precedence rule, because a
 * delivery carrying both is a shape neither channel documents — and picking one silently is how a
 * payment DMN would end up routed by a field that means something else.
 */
final readonly class EventParser implements EventParserContract
{
    public const string TYPE_AUTH = 'Auth';

    public const string TYPE_AUTH_PAYMENT_METHOD = 'Auth:PaymentMethod';

    public const string TYPE_SALE = 'Sale';

    public const string TYPE_SETTLE = 'Settle';

    public const string TYPE_CREDIT = 'Credit';

    public const string TYPE_VOID = 'Void';

    /**
     * The Chargeback event DMN's own `EventType`, registered here so the subscriber and the
     * registry cannot disagree about the spelling.
     */
    public const string TYPE_CHARGEBACK = 'Chargeback';

    #[Override]
    public function parse(array $payload): ParsedEvent
    {
        $event = new NuveiEvent($payload);
        $transactionType = $event->transactionType();

        if ($transactionType === '' && ($eventType = $event->eventType()) !== '') {
            return new ParsedEvent(
                type: $eventType,
                // `EventType:EventId`, and the type is not decoration: the envelope's id is unique
                // within one event type and there is no documented promise that it is unique across
                // them, so the pair is what identifies a delivery — the same reasoning that puts
                // `transactionType` in front of `PPP_TransactionID` below.
                //
                // A delivery with no `EventId` falls back to a random value, exactly as the payment
                // path does for a missing `PPP_TransactionID`, and the cost is the same: this layer
                // can no longer recognise the redelivery. The aggregate still can, because the
                // snapshot carries the dispute suite's own `Chargeback.DisputeEventId` as its
                // idempotency key and refuses a blank one — see {@see ChargebackEvent}.
                externalId: sprintf(
                    '%s:%s',
                    $eventType,
                    $event->eventId() !== '' ? $event->eventId() : uniqid('nuvei_', true),
                ),
                native: $event,
            );
        }

        $type = $transactionType === 'Auth' && $event->totalAmount() === 0.0
            ? self::TYPE_AUTH_PAYMENT_METHOD
            : $transactionType;

        $externalId = sprintf(
            '%s:%s',
            $transactionType !== '' ? $transactionType : 'Unknown',
            $event->pppTransactionId() !== '' ? $event->pppTransactionId() : uniqid('nuvei_', true),
        );

        return new ParsedEvent(
            type: $type,
            externalId: $externalId,
            native: $event,
        );
    }
}
