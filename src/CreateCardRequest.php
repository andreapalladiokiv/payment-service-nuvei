<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\Payments\CreditCard as NuveiCreditCardService;
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
 * Tokenizes a payment instrument via Nuvei.
 * For credit cards: uses cardTokenization.do endpoint, returns ccTempToken.
 */
final class CreateCardRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use NuveiRequestParameters;

    public function getData(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return $instrument->accept($this);
    }

    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        return [
            'cardData' => array_filter([
                'cardNumber' => $card->number->getNumber($decrypter),
                'cardHolderName' => (string) $card->holder ?: null,
                'expirationMonth' => $card->expiration->format('m'),
                'expirationYear' => $card->expiration->format('Y'),
                'CVV' => $card->cvc->getCvc($decrypter) ?: null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];
    }

    public function visitCash(Cash $cash): mixed
    {
        throw new ValueError('Nuvei does not support cash tokenization.');
    }

    public function visitToken(Token $token): never
    {
        throw new RuntimeException('Token does not support tokenization.');
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    public function sendData($data): CreateCardResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $result = new NuveiCreditCardService($client)->cardTokenization([
                ...$data,
                'sessionToken' => $this->getParameter('sessionToken'),
            ]);

            return new CreateCardResponse($this, $result);
        } catch (\Throwable $e) {
            return new CreateCardResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
