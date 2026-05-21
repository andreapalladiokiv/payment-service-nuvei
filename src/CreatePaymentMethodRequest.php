<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\UserPaymentOptions;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use ValueError;

/**
 * Converts a Nuvei ccTempToken into a permanent User Payment Option (UPO).
 * Expects: instrument (Token), gateway (Gateway), userTokenId (string).
 */
final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use NuveiRequestParameters;

    public function getData(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');
        $ccTempToken = $instrument->accept($this);

        return [
            'ccTempToken' => $ccTempToken,
            'userTokenId' => $this->getUserTokenId(),
        ];
    }

    public function visitCreditCard(CreditCard $card): never
    {
        throw new RuntimeException('Credit card must be tokenized before converting to UPO via Nuvei.');
    }

    public function visitCash(Cash $cash): never
    {
        throw new ValueError('Nuvei does not support cash for payment method creation.');
    }

    public function visitToken(Token $token): string
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        return $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException('No Nuvei gateway reference found for token '.$token->id->toString());
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be converted to UPO via Nuvei.');
    }

    public function sendData($data): CreatePaymentMethodResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $result = new UserPaymentOptions($client)->addUPOCreditCardByTempToken([
                'sessionToken' => $this->getParameter('sessionToken'),
                'userTokenId' => $data['userTokenId'],
                'clientRequestId' => uniqid('upo_', true),
                'ccTempToken' => $data['ccTempToken'],
            ]);

            return new CreatePaymentMethodResponse($this, $result);
        } catch (\Throwable $e) {
            return new CreatePaymentMethodResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }

    public function getUserTokenId(): string
    {
        return $this->getCustomerReference();
    }

    public function getCustomerReference(): string
    {
        return $this->getParameter('customerReference') ?? '';
    }

    public function setCustomerReference(string $value): self
    {
        return $this->setParameter('customerReference', $value);
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
