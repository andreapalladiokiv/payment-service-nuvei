<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Money\Money;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\PaymentService;
use Omnipay\Common\Message\AbstractRequest;
use Omnipay\Common\Message\AbstractResponse;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;

/**
 * Base for Nuvei purchase/authorize requests.
 * Subclasses override settleType() and wrapResponse().
 */
abstract class NuveiPaymentRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use NuveiRequestParameters;

    abstract protected function transactionType(): string;

    abstract protected function settleType(): ?int;

    abstract protected function wrapResponse(array $result): AbstractResponse;

    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');
        $paymentOption = $instrument->accept($this);

        $threeDS = $this->getThreeDS();

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
                throw IncompleteAuthentication::missingFields('nuvei', $this->operationLabel(), $missing);
            }

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

        /** @var Money $money */
        $money = $this->getParameter('money');

        // One id for both fields: with two independent fallback UUIDs a
        // retried request can't be correlated (or deduplicated) by Nuvei.
        $clientUniqueId = $this->getParameter('clientUniqueId') ?? Uuid::uuid4()->toString();

        $data = [
            'sessionToken' => $this->getParameter('sessionToken'),
            'clientRequestId' => $clientUniqueId,
            'clientUniqueId' => $clientUniqueId,
            'amount' => $this->formatMoney($money),
            'currency' => $money->getCurrency()->getCode(),
            'paymentOption' => $paymentOption,
            'transactionType' => $this->transactionType(),
            'deviceDetails' => ['ipAddress' => $this->getParameter('clientIp') ?? '127.0.0.1'],
            'billingAddress' => $this->formatBillingAddress($this->getParameter('billingAddress')),
        ];

        // Omit, don't send '': Nuvei rejects an empty userTokenId outright,
        // while a payment without one is valid for non-stored instruments.
        // Stored userPaymentOptionIds still require the owning user — the
        // gateway resolves it via CustomerRepository before building this
        // request.
        $userTokenId = $this->getCustomerReference();
        if ($userTokenId !== '') {
            $data['userTokenId'] = $userTokenId;
        }

        $settleType = $this->settleType();
        if ($settleType !== null) {
            $data['settleType'] = $settleType;
        }

        $data = [...$data, ...$this->rebilling()];

        $statementDescription = $this->getStatementDescription();
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['dynamicDescriptor'] = ['merchantName' => $statementDescription];
        }

        return $data;
    }

    public function visitCreditCard(CreditCard $card): never
    {
        // Nuvei takes card data only through tokenization (createCard /
        // createPaymentMethod); a payment request carries the resulting reference.
        throw UnsupportedInstrument::forGateway('nuvei', $this->operationLabel(), $card);
    }

    public function visitCash(Cash $cash): never
    {
        throw UnsupportedInstrument::forGateway('nuvei', $this->operationLabel(), $cash);
    }

    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Nuvei reference found for token {$token->id}.");

        return ['card' => ['ccTempToken' => $reference]];
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No Nuvei reference found for payment method {$paymentMethod->id}.");

        return [
            'userPaymentOptionId' => $reference,
            'storedCredentials' => [
                'storedCredentialsMode' => '1',
            ],
        ];
    }

    public function sendData($data): AbstractResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');
            $result = (new PaymentService($client))->createPayment($data);

            return $this->wrapResponse($result);
        } catch (\Throwable $e) {
            return $this->wrapResponse(['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }

    public function getCustomerReference(): string
    {
        return $this->getParameter('customerReference') ?? '';
    }

    public function setCustomerReference(string $value): self
    {
        return $this->setParameter('customerReference', $value);
    }

    public function visitHostedPayment(HostedPayment $hosted): array
    {
        // Only PurchaseRequest overrides this with the real Cashier build;
        // every other Nuvei payment request lands here.
        throw UnsupportedInstrument::forGateway('nuvei', $this->operationLabel(), $hosted);
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
     * @return array<string, string>
     */
    private function rebilling(): array
    {
        if (! $this->isRebilling()) {
            // Conditional in their reference — required "when performing
            // recurring/rebilling". A payment outside a series is not that, and "0"
            // here would tell the acquirer to expect renewals that never come.
            return [];
        }

        $anchor = $this->getRebillingReference();

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
        if ($opensTheSeries && $this->getInitiation() === PaymentInitiation::MerchantRecurring) {
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
            'rebillingType' => $this->getInitiation() === PaymentInitiation::MerchantRecurring
                ? 'Recurring'
                : 'MIT',
            'relatedTransactionId' => $anchor,
        ];
    }

    /**
     * Names the path that refused, rather than blaming the gateway as a whole.
     * Subclasses are named <Operation>Request, so the class name is the label.
     */
    private function operationLabel(): string
    {
        $short = basename(str_replace('\\', '/', static::class));

        return lcfirst((string) preg_replace('/Request$/', '', $short));
    }
}
