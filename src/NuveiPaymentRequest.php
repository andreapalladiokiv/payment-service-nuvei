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
     * The stored-credential chain, as Nuvei's rebilling parameters.
     *
     * AT THE ROOT, and that is sourced rather than guessed. Their own SDK settles
     * it: `Payments\CreditCard::paymentCC()` lists `isRebilling` in the same
     * `$mandatoryFields` array as `merchantId`, `amount` and `checksum`, and
     * `BaseService::validate()` checks that array against `array_keys($params)` —
     * the top level. `PaymentService::createPayment()` likewise reaches for
     * `$params['externalSchemeDetails']` at the root, and that class is the
     * documented either/or partner of `relatedTransactionId`. The sandbox cannot
     * corroborate it: a probe found it approves the block at the root, nested under
     * `paymentOption.card`, and as a nonsense field, so acceptance there identifies
     * nothing (see NuveiRebillingProbeTest).
     *
     * The anchor rides along only when the caller resolved one. Nuvei marks it
     * required for MIT, so declaring a chain without it is by their contract an
     * incomplete request — but refusing outright would break the next renewal of
     * every subscription that predates the anchor being recorded at all, and a
     * renewal declared merchant-initiated without an anchor is still better than
     * one submitted as though the cardholder were present. So it declares what it
     * knows and omits what it does not.
     *
     * Not sent here: `rebillFrequency` / `rebillExpiry`. Those belong on the
     * INITIAL 3DS CIT, under `card.threeD.v2AdditionalParams`, not on the
     * subsequent payment this class builds.
     *
     * @return array<string, string>
     */
    private function rebilling(): array
    {
        $initiation = $this->getInitiation();

        if ($initiation->isCardholderInitiated()) {
            // Conditional in their reference — required "when performing
            // recurring/rebilling" — and nothing here can tell a subscription opened
            // by a present cardholder from a standalone checkout. Both arrive as
            // CardholderInitiated with no anchor. Sending "0" would tell the acquirer
            // to expect renewals of every one-off sale, so the ambiguity resolves
            // toward silence: a missing "0" understates a series, an invented one
            // describes a series that does not exist.
            return [];
        }

        $anchor = $this->getStoredCredentialReference();
        $hasAnchor = $anchor !== null && $anchor !== '';

        // "0 – For the first rebilling payment. 1 – For all subsequent rebilling
        // transactions" — their reference is worded on position in the series, not on
        // who initiated the payment, and the two axes come apart. A series opened
        // without the cardholder present is MerchantUnscheduled and is still the
        // FIRST of its series; a recurring payment is by construction never first.
        // So the position follows from the initiation plus whether anything precedes
        // this payment, and needs no separate flag.
        if ($initiation === PaymentInitiation::MerchantUnscheduled && ! $hasAnchor) {
            return ['isRebilling' => '0'];
        }

        $rebilling = [
            'isRebilling' => '1',
            // Here the initiation IS the right axis: it says whether the series bills
            // on a schedule or on demand. Their other two values, NoShow and
            // DelayedCharges, are industry-specific and need an account-manager
            // conversation before anything may send them.
            'rebillingType' => $initiation === PaymentInitiation::MerchantRecurring
                ? 'Recurring'
                : 'MIT',
        ];

        if ($hasAnchor) {
            $rebilling['relatedTransactionId'] = $anchor;
        }

        return $rebilling;
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
