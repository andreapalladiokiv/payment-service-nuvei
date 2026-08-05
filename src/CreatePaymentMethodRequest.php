<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Exception\ConfigurationException;
use Nuvei\Api\Exception\ConnectionException;
use Nuvei\Api\Exception\ResponseException;
use Nuvei\Api\Exception\ValidationException;
use Override;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\PaymentService;
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
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Throwable;
use ValueError;

/**
 * Creates a permanent Nuvei User Payment Option (UPO), by either of the two
 * routes Nuvei offers.
 *
 * A ccTempToken is converted through `addUPOCreditCardByTempToken`, a vault
 * operation. A card is registered through a zero-amount `Auth` on `payment.do`,
 * which returns a `userPaymentOptionId` just the same and additionally comes back
 * with the issuer's AVS and CVV verdicts — `addUPOCreditCard.do` would also
 * accept the card, but it takes no CVV and returns no checks, so it cannot answer
 * the question a registration is usually asked to answer.
 *
 * The zero-amount Auth is what the pre-bridge integration used for years. It does
 * reach the issuer, which is the point: registration happens lazily, while the
 * cardholder is already paying, so a verification against their account is both
 * expected and useful.
 *
 * Expects: instrument (Token or CreditCard), gateway (Gateway), userTokenId.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use NuveiRequestParameters;

    /**
     * Nuvei needs an amount and a currency even to verify. Zero is the
     * account-verification amount the schemes define for exactly this, and the
     * currency is immaterial to a zero-value message.
     */
    private const string VERIFICATION_CURRENCY = 'USD';

    #[Override]
    public function getData(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return [
            ...$instrument->accept($this),
            'userTokenId' => $this->getUserTokenId(),
        ];
    }

    /**
     * @return array{paymentOption: array{card: array<string, mixed>}}
     */
    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        return [
            'paymentOption' => [
                'card' => array_filter([
                    'cardNumber' => $card->number->getNumber($decrypter),
                    'cardHolderName' => (string) $card->holder,
                    'expirationMonth' => $card->expiration->format('m'),
                    'expirationYear' => $card->expiration->format('Y'),
                    'CVV' => $card->cvc->getCvc($decrypter) ?: null,
                ]),
            ],
        ];
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new ValueError('Nuvei does not support cash for payment method creation.');
    }

    /**
     * @return array{ccTempToken: string}
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        return [
            'ccTempToken' => $this->getReferenceResolver()->find($gateway->getId(), $token)
                ?? throw new RuntimeException('No Nuvei gateway reference found for token '.$token->id->toString()),
        ];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be converted to UPO via Nuvei.');
    }

    #[Override]
    public function sendData($data): CreatePaymentMethodResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $result = isset($data['ccTempToken'])
                ? $this->convertTempToken($client, $data)
                : $this->verifyCard($client, $data);

            return new CreatePaymentMethodResponse($this, $result);
        } catch (Throwable $e) {
            return new CreatePaymentMethodResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }

    /**
     * @param RestClient $client
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws ConfigurationException
     * @throws ConnectionException
     * @throws ResponseException
     * @throws ValidationException
     */
    private function convertTempToken(RestClient $client, array $data): array
    {
        return new UserPaymentOptions($client)->addUPOCreditCardByTempToken([
            'sessionToken' => $this->getParameter('sessionToken'),
            'userTokenId' => $data['userTokenId'],
            'clientRequestId' => uniqid('upo_', true),
            'ccTempToken' => $data['ccTempToken'],
        ]);
    }

    /**
     * Registers the card by asking the issuer to verify it for zero.
     *
     * `storedCredentialsMode: '0'` belongs here and only here — this is the moment a
     * credential is stored, which is what the parameter describes: Nuvei's REST 1.0
     * reference defines it as showing "whether or not stored tokenized card data is
     * sent to execute the transaction", `'0'` being "the card data was entered for the
     * first time, and, upon completion of the transaction, is tokenized and stored".
     * Registration is reached only through `createPaymentMethod`, an operation of its
     * own, so saying it here says it exactly once.
     *
     * It does NOT establish the MIT chain, and the note that used to stand here
     * claiming it did was wrong: what marks a chain is `isRebilling`, and that travels
     * on `authorizeRebilling`. Payments accordingly send no stored-credential marker at
     * all now — {@see NuveiPaymentRequest::visitPaymentMethod}
     * used to attach mode `'1'` to every stored instrument, deriving a
     * stored-credential claim from the instrument's shape rather than from the payment.
     *
     * @param RestClient $client
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws ConfigurationException
     * @throws ConnectionException
     * @throws ResponseException
     * @throws ValidationException
     */
    private function verifyCard(RestClient $client, array $data): array
    {
        $card = $data['paymentOption']['card'];
        $card['storedCredentials'] = ['storedCredentialsMode' => '0'];

        $clientRequestId = uniqid('upo_', true);

        return new PaymentService($client)->createPayment([
            'sessionToken' => $this->getParameter('sessionToken'),
            'clientRequestId' => $clientRequestId,
            'clientUniqueId' => $this->getParameter('clientUniqueId') ?? $clientRequestId,
            'userTokenId' => $data['userTokenId'],
            'amount' => '0',
            'currency' => self::VERIFICATION_CURRENCY,
            'transactionType' => 'Auth',
            'paymentOption' => ['card' => $card],
            'billingAddress' => $this->formatBillingAddress($this->getParameter('billingAddress')),
        ]);
    }

    public function getUserTokenId(): string
    {
        return $this->getCustomerReference();
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('nuvei', 'createPaymentMethod', $hosted);
    }
}
