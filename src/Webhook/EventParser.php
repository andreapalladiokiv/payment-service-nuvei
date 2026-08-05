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
 */
final readonly class EventParser implements EventParserContract
{
    public const string TYPE_AUTH = 'Auth';

    public const string TYPE_AUTH_PAYMENT_METHOD = 'Auth:PaymentMethod';

    public const string TYPE_SALE = 'Sale';

    public const string TYPE_SETTLE = 'Settle';

    public const string TYPE_CREDIT = 'Credit';

    public const string TYPE_VOID = 'Void';

    #[Override]
    public function parse(array $payload): ParsedEvent
    {
        $event = new NuveiEvent($payload);
        $transactionType = $event->transactionType();

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
