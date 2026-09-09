<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Nuvei\Api\Service\Payments\CreditCard as NuveiCreditCardService;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Throwable;
use ValueError;

/**
 * Hands a card to Nuvei to keep for the length of a session — `cardTokenization.do`, which answers
 * with a `ccTempToken` a payment can then carry in place of the PAN.
 *
 * It links the instrument to nobody, which is why no customer is resolved for it: a temp token
 * belongs to the session it was created in, not to a user. Making the token permanent, and
 * attaching it to somebody, is {@see RegisterPaymentMethod}.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final readonly class Tokenize implements PaymentInstrumentVisitor
{
    public function __construct(
        private NuveiSettings $settings,
        private GatewayInfrastructure $infrastructure,
        private VaultCommand $command,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->command->instrument->accept($this);
    }

    /**
     * @return array{cardData: array<string, string>}
     */
    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

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

    /**
     * An attached one is refused for the same reason as a bare one: this operation is what
     * PRODUCES a stored instrument, so being handed one is a caller's mistake either way, and
     * having a customer attached does not make a stored card re-storable.
     */
    #[Override]
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('nuvei', 'createCard', $hosted);
    }

    public function tokenize(): RegistrationResult
    {
        // Built outside the try, as omnipay's `send()` built it outside `sendData()`: an instrument
        // this cannot tokenize raises from the visitor and has always propagated rather than
        // reading as a rejected card.
        $data = $this->payload();

        try {
            $result = new NuveiCreditCardService($this->settings->restClient)->cardTokenization([
                ...$data,
                'sessionToken' => $this->settings->sessionToken,
            ]);
        } catch (Throwable $e) {
            return RegistrationResult::failed($e->getMessage());
        }

        return $this->map($result);
    }

    /**
     * Nuvei answers SUCCESS on the CALL while omitting `ccTempToken` when the card data was
     * rejected downstream. Treating that as success would store a null reference against the
     * instrument and fail the first payment that used it — far from the operation that actually
     * failed. So the token has to be there as well as the status.
     *
     * @param  array<string, mixed>  $result
     */
    private function map(array $result): RegistrationResult
    {
        $token = isset($result['ccTempToken']) ? (string) $result['ccTempToken'] : null;

        // Cast, not passed through: Nuvei's JSON has returned ccTempToken unquoted, and the
        // reference has to stay a string all the way into storage, where the column and every
        // consumer are typed on string.
        if (($result['status'] ?? '') !== 'SUCCESS' || $token === null) {
            return RegistrationResult::failed(
                $this->message($result) ?? 'Gateway returned an unsuccessful response.',
            );
        }

        return $token === ''
            ? RegistrationResult::failed(GatewayResult::UNNAMED_SUCCESS)
            : RegistrationResult::succeeded($token);
    }

    /**
     * A reason, whatever its shape, is the message — including an empty one, which is a deliberate
     * answer rather than an absent field.
     *
     * The `errCode` fallback casts. The value was returned uncast once and Nuvei sends the code
     * unquoted, so asking for a message raised `TypeError: must be of type ?string, int returned`.
     * And what actually reaches that branch is `errCode => 0`, because the SDK converts a non-empty
     * code into its own error shape first: 0 is Nuvei's marker for NO error, so reporting it would
     * put "0" where a caller expects nothing to say.
     *
     * @param  array<string, mixed>  $result
     */
    private function message(array $result): ?string
    {
        if (array_key_exists('reason', $result)) {
            return (string) $result['reason'];
        }

        $code = $result['errCode'] ?? null;

        return $code === null || (int) $code === 0 ? null : (string) $code;
    }
}
