<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Override;
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
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Throwable;
use ValueError;

/**
 * Tokenizes a payment instrument via Nuvei.
 * For credit cards: uses cardTokenization.do endpoint, returns ccTempToken.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class CreateCardRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use NuveiRequestParameters;

    #[Override]
    public function getData(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return $instrument->accept($this);
    }

    #[Override]
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

    #[Override]
    public function visitCash(Cash $cash): mixed
    {
        throw new ValueError('Nuvei does not support cash tokenization.');
    }

    #[Override]
    public function visitToken(Token $token): never
    {
        throw new RuntimeException('Token does not support tokenization.');
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    #[Override]
    public function sendData($data): CreateCardResponse
    {
        // Asserted, not declared. The parent declares `mixed`, so narrowing the parameter
        // itself would be contravariance backwards — an implementation may accept more than
        // the contract promises, never less. What arrives is always this class's own
        // getData() output, because omnipay's send() is the only caller.
        /** @var array<string, mixed> $data */
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $result = new NuveiCreditCardService($client)->cardTokenization([
                ...$data,
                'sessionToken' => $this->getParameter('sessionToken'),
            ]);

            return new CreateCardResponse($this, $result);
        } catch (Throwable $e) {
            return new CreateCardResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('nuvei', 'createCard', $hosted);
    }
}
