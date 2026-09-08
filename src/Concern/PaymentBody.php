<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Concern;

use Override;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\NuveiSettings;

/**
 * The body of a Nuvei payment — `payment.do` — for whichever operation is placing it.
 *
 * What used to be an abstract `NuveiPaymentRequest` two requests extended, with `transactionType()`
 * and `settleType()` as template methods. It is a collaborator now rather than a base class, for a
 * plain reason: the difference between a sale and a hold is two values on one call, and inheriting
 * a lifecycle to express two values put the operation's own identity — which endpoint, which
 * result shape — behind a `send()` that neither subclass could see.
 *
 * It visits the instrument itself, so the one place that knows how Nuvei addresses a stored card
 * is the one place that builds a payment with it.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final readonly class PaymentBody implements PaymentInstrumentVisitor
{
    use NuveiRequestParameters;

    /**
     * @param  string  $operation  Names the path that refused, rather than blaming the gateway as
     *   a whole: `purchase` and `authorize` accept different instruments from each other, and a
     *   merchant reading "does not accept a cash instrument" wants to know on which.
     * @param  string  $customerReference  Nuvei's `userTokenId`, resolved by the gateway before
     *   the operation was built. Empty means the caller is not in a position to link anything.
     */
    public function __construct(
        private NuveiSettings $settings,
        private GatewayInfrastructure $infrastructure,
        private PlacementCommand|RebillingCommand $command,
        private string $operation,
        private string $customerReference = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $transactionType, ?int $settleType): array
    {
        $paymentOption = $this->command->instrument->accept($this);

        $threeDS = $this->command->threeDS;

        if ($threeDS !== null) {
            // Nuvei marks eci, cavv and dsTransID all Required inside externalMpi.
            // An attestation that did not succeed carries neither cavv nor eci, so
            // assembling the block anyway posts a body Nuvei rejects — and that
            // rejection would enter the stream as an issuer decline. Refuse here,
            // before the request leaves.
            $missing = [];

            if ($threeDS->eci === null) {
                $missing[] = 'eci';
            }

            if (($threeDS->authenticationValue ?? '') === '') {
                $missing[] = 'cavv';
            }

            if ($threeDS->dsTransactionId === '') {
                $missing[] = 'dsTransID';
            }

            if ($missing !== []) {
                throw IncompleteAuthentication::missingFields('nuvei', $this->operation, $missing);
            }

            // Restated for the analyser: the collect-then-report form above proves this,
            // but the proof sits in $missing rather than in the property. Kept that way so
            // the exception can name every missing field at once instead of the first.
            assert($threeDS->eci !== null);

            $externalMpi = [
                'eci' => $threeDS->eci->value,
                'cavv' => $threeDS->authenticationValue,
                'dsTransID' => $threeDS->dsTransactionId,
                // Mandatory whenever external-MPI values are present. NoPreference,
                // never ExemptionRequest: asking for an exemption while presenting a
                // cryptogram gives up the very liability shift it was obtained for.
                'challengePreference' => 'NoPreference',
            ];

            if (isset($paymentOption['card'])) {
                $paymentOption['card']['threeD'] = ['externalMpi' => $externalMpi];
            } else {
                $paymentOption['threeD'] = ['externalMpi' => $externalMpi];
            }
        }

        $money = $this->command->amount;

        // One id for both fields: with two independent fallback UUIDs a
        // retried request can't be correlated (or deduplicated) by Nuvei.
        $clientUniqueId = $this->command->clientUniqueId ?? Uuid::uuid4()->toString();

        $data = [
            'sessionToken' => $this->settings->sessionToken,
            'clientRequestId' => $clientUniqueId,
            'clientUniqueId' => $clientUniqueId,
            'amount' => $this->formatMoney($money),
            'currency' => $money->getCurrency()->getCode(),
            'paymentOption' => $paymentOption,
            'transactionType' => $transactionType,
            // Nuvei requires the field; nothing upstream carries the buyer's address, and a
            // command has no slot for one, so the loopback stands in as it always has.
            'deviceDetails' => ['ipAddress' => '127.0.0.1'],
            'billingAddress' => $this->formatBillingAddress($this->command->billingAddress),
        ];

        // Omit, don't send '': Nuvei rejects an empty userTokenId outright,
        // while a payment without one is valid for non-stored instruments.
        // Stored userPaymentOptionIds still require the owning user — the
        // gateway resolves it via GatewayCustomerRepository before building this
        // operation.
        if ($this->customerReference !== '') {
            $data['userTokenId'] = $this->customerReference;
        }

        if ($settleType !== null) {
            $data['settleType'] = $settleType;
        }

        $data = [...$data, ...$this->rebilling()];

        $statementDescription = $this->command->statementDescription;
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['dynamicDescriptor'] = ['merchantName' => $statementDescription];
        }

        return $data;
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): never
    {
        // Nuvei takes card data only through tokenization (tokenize /
        // registerPaymentMethod); a payment carries the resulting reference.
        throw UnsupportedInstrument::forGateway('nuvei', $this->operation, $card);
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw UnsupportedInstrument::forGateway('nuvei', $this->operation, $cash);
    }

    /**
     * @return array{card: array{ccTempToken: string}}
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        $reference = $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $token)
            ?? throw new RuntimeException("No Nuvei reference found for token {$token->id}.");

        return ['card' => ['ccTempToken' => $reference]];
    }

    /**
     * @return array{userPaymentOptionId: string}
     */
    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        $reference = $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $paymentMethod)
            ?? throw new RuntimeException("No Nuvei reference found for payment method {$paymentMethod->id}.");

        // No storedCredentials. Their REST 1.0 reference is explicit that merchants
        // "using Nuvei's tokenization feature should not send this parameter", and we
        // do use it — this very method resolves a userPaymentOptionId to pay with. It
        // was sent unconditionally for any stored instrument, which also meant the
        // stored-credential marker was derived from the SHAPE of the instrument rather
        // than from anything about the payment.
        //
        // What actually marks a rebilling chain is `isRebilling`, and that now has an
        // operation of its own to travel on: `authorizeRebilling` sets it, an ordinary
        // payment sets nothing. Splitting the operations is what made this removable —
        // before it, this was the only place any stored-credential signal existed.
        return ['userPaymentOptionId' => $reference];
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        // Only `charge` has a hosted product, and {@see \Techork\PaymentService\Nuvei\Purchase}
        // answers it before ever building a REST body; every other payment lands here.
        throw UnsupportedInstrument::forGateway('nuvei', $this->operation, $hosted);
    }

    /**
     * The rebilling chain, as Nuvei's rebilling parameters.
     *
     * AT THE ROOT, and that is sourced rather than guessed. Their own SDK settles it:
     * `Payments\CreditCard::paymentCC()` lists `isRebilling` in the same
     * `$mandatoryFields` array as `merchantId`, `amount` and `checksum`, and
     * `BaseService::validate()` checks that array against `array_keys($params)` — the
     * top level. `PaymentService::createPayment()` likewise reaches for
     * `$params['externalSchemeDetails']` at the root, and that class is the
     * documented either/or partner of `relatedTransactionId`. The sandbox cannot
     * corroborate it: a probe found it approves the block at the root, nested under
     * `paymentOption.card`, with a bogus anchor, with none, and as a nonsense field,
     * so acceptance there identifies nothing (see NuveiRebillingProbeTest).
     *
     * Not sent here: `rebillFrequency` / `rebillExpiry`. Those belong on the INITIAL
     * 3DS CIT, under `card.threeD.v2AdditionalParams`, not on a subsequent payment.
     *
     * @return array<string, string|null>
     */
    private function rebilling(): array
    {
        if (! $this->command instanceof RebillingCommand) {
            // Conditional in their reference — required "when performing
            // recurring/rebilling". A payment outside a series is not that, and "0"
            // here would tell the acquirer to expect renewals that never come.
            //
            // Which operation the caller chose is the whole signal, and it is a type
            // now rather than a `rebilling` flag riding a shared parameter bag.
            return [];
        }

        $anchor = $this->command->genesisReference;

        // "0 – For the first rebilling payment. 1 – For all subsequent rebilling
        // transactions." Position, not who initiated it: a series opened by a present
        // cardholder is still the first of its series. Inside a series the anchor's
        // absence is unambiguous — nothing precedes this payment — which is precisely
        // what it could not mean on the ordinary authorize path, where absence also
        // meant "no series at all".
        $opensTheSeries = $anchor === null || $anchor === '';

        // Except that a recurring payment cannot be the one that opens a series — a
        // renewal by definition has something before it. Reaching here without an
        // anchor means the genesis was never recorded, which is every subscription
        // predating it being carried at all. Declaring that a first payment would
        // tell the acquirer a new series starts on every renewal, so it stays a
        // subsequent one with the reference simply missing.
        if ($opensTheSeries && $this->command->initiation === PaymentInitiation::MerchantRecurring) {
            return ['isRebilling' => '1', 'rebillingType' => 'Recurring'];
        }

        if ($opensTheSeries) {
            // No rebillingType and no anchor: their MIT page asks for the sub-type
            // only "for subsequent MIT payments", and the first is what later ones
            // point at.
            return ['isRebilling' => '0'];
        }

        return [
            'isRebilling' => '1',
            // Here initiation IS the right axis — it says whether the series bills on
            // a schedule or on demand. Their other two values, NoShow and
            // DelayedCharges, are industry-specific and need an account-manager
            // conversation before anything may send them.
            'rebillingType' => $this->command->initiation === PaymentInitiation::MerchantRecurring
                ? 'Recurring'
                : 'MIT',
            'relatedTransactionId' => $anchor,
        ];
    }
}
