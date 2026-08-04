<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

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

    public function visitCash(Cash $cash): never
    {
        throw new ValueError('Nuvei does not support cash for payment method creation.');
    }

    /**
     * @return array{ccTempToken: string}
     */
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        return [
            'ccTempToken' => $this->getReferenceResolver()->find($gateway->getId(), $token)
                ?? throw new RuntimeException('No Nuvei gateway reference found for token '.$token->id->toString()),
        ];
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

            $result = isset($data['ccTempToken'])
                ? $this->convertTempToken($client, $data)
                : $this->verifyCard($client, $data);

            return new CreatePaymentMethodResponse($this, $result);
        } catch (\Throwable $e) {
            return new CreatePaymentMethodResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
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
     * `storedCredentialsMode: '0'` does NOT establish the MIT chain, and the note
     * that used to say so here was wrong on two counts. Nuvei's REST 1.0 reference
     * makes it a separate mechanism from rebilling — "This parameter shows whether
     * or not stored tokenized card data is sent to execute the transaction … This
     * parameter is only applicable to merchants that store tokenized card data.
     * Merchants that do not store card data or that are using Nuvei's tokenization
     * feature should not send this parameter." We use their tokenization: this call
     * produces a `userPaymentOptionId` and payments quote it. By that sentence we
     * should not be sending the parameter at all, on either side.
     *
     * What actually marks the chain is `isRebilling` — "0" on the initial CIT, "1"
     * plus `rebillingType` and `relatedTransactionId` on every subsequent MIT — and
     * this repo sends none of it anywhere. Removing storedCredentialsMode is a
     * behaviour change on the money path and wants a sandbox probe first, so it is
     * left alone here rather than quietly dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
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
        throw UnsupportedInstrument::forGateway('nuvei', 'createPaymentMethod', $hosted);
    }
}
