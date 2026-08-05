<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use DateTimeImmutable;
use Money\Money;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\PaymentService;
use Nuvei\Api\Utils;
use Omnipay\Common\Message\AbstractRequest;
use Omnipay\Common\Message\AbstractResponse;
use Override;
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
use Throwable;

/**
 * Retries a refund onto an alternative instrument via Nuvei's Payout
 * endpoint (Visa OCT / Mastercard MoneySend rails). Unlike `refund`, a
 * payout is independent of the original sale — funds move from the
 * merchant balance to whatever instrument the merchant supplies.
 *
 * Raw cards (PAN) are intentionally rejected: payout with raw card data
 * requires the service to hold PCI Level 1, which is out of scope. Use a
 * Nuvei-side ccTempToken or a stored payment method instead.
 *
 * Expects: money, instrument (Token or PaymentMethod), gateway.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class PayoutRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use NuveiRequestParameters;

    #[Override]
    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');
        $paymentOption = $instrument->accept($this);

        /** @var Money $money */
        $money = $this->getParameter('money');
        $clientUniqueId = (string) ($this->getParameter('clientUniqueId') ?? Uuid::uuid4()->toString());
        $clientRequestId = (string) ($this->getParameter('clientRequestId') ?? Uuid::uuid4()->toString());

        return [
            'clientRequestId' => $clientRequestId,
            'clientUniqueId' => $clientUniqueId,
            'amount' => $this->formatMoney($money),
            'currency' => $money->getCurrency()->getCode(),
            'userTokenId' => $this->getCustomerReference(),
            'userPaymentOption' => $paymentOption,
            'deviceDetails' => ['ipAddress' => $this->getParameter('clientIp') ?? '127.0.0.1'],
        ];
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): never
    {
        throw new RuntimeException(
            'Nuvei payout does not accept raw card data. Tokenize the alternative '
            .'instrument first (or use a stored PaymentMethod).',
        );
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash is not a valid retry refund instrument.');
    }

    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Nuvei reference found for token {$token->id->toString()}.");

        return ['userPaymentOptionId' => $reference];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No Nuvei reference found for payment method {$paymentMethod->id->toString()}.");

        return ['userPaymentOptionId' => $reference];
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new RuntimeException('HostedPayment is not a valid retry refund instrument.');
    }

    public function getCustomerReference(): string
    {
        return $this->getParameter('customerReference') ?? '';
    }

    public function setCustomerReference(string $value): self
    {
        return $this->setParameter('customerReference', $value);
    }

    #[Override]
    public function sendData($data): AbstractResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $service = new PaymentService($client);
            $config = $client->getConfig()
                ?? throw new RuntimeException('The Nuvei client carries no configuration, so a payout cannot be addressed.');

            $data['merchantId'] = $config->getMerchantId();
            $data['merchantSiteId'] = $config->getMerchantSiteId();
            $data['timeStamp'] = (new DateTimeImmutable)->format('YmdHis');

            $data['checksum'] = Utils::calculateChecksum(
                $data,
                [
                    'merchantId',
                    'merchantSiteId',
                    'clientRequestId',
                    'clientUniqueId',
                    'amount',
                    'currency',
                    'userTokenId',
                    'timeStamp',
                    'merchantSecretKey',
                ],
                $config->getMerchantSecretKey(),
                $config->getHashAlgorithm(),
            );

            $result = $service->requestJson($data, 'payout.do');

            return new RefundResponse($this, $result);
        } catch (Throwable $e) {
            return new RefundResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }
}
