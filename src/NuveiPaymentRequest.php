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
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use ValueError;

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
            $externalMpi = [
                'eci' => $threeDS->eci->value,
                'cavv' => $threeDS->authenticationValue,
                'dsTransID' => $threeDS->dsTransactionId,
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

        $statementDescription = $this->getStatementDescription();
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['dynamicDescriptor'] = ['merchantName' => $statementDescription];
        }

        return $data;
    }

    public function visitCreditCard(CreditCard $card): never
    {
        throw new RuntimeException('Credit card must be tokenized before payment via Nuvei.');
    }

    public function visitCash(Cash $cash): never
    {
        throw new ValueError('Nuvei does not support cash payments.');
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
        throw new RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
