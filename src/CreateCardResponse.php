<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;
use Override;

final class CreateCardResponse extends AbstractResponse
{
    #[Override]
    public function isSuccessful(): bool
    {
        return isset($this->data['status']) && $this->data['status'] === 'SUCCESS'
            && isset($this->data['ccTempToken']);
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return isset($this->data['ccTempToken'])
            ? (string) $this->data['ccTempToken']
            : null;
    }

    public function getSessionToken(): ?string
    {
        return $this->data['sessionToken'] ?? null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        // A reason, whatever its shape, is the message — including an empty one, which is a
        // deliberate answer rather than an absent field.
        if (array_key_exists('reason', $this->data)) {
            return (string) $this->data['reason'];
        }

        $code = $this->data['errCode'] ?? null;

        // Two things were wrong here. The value was returned uncast from a `?string` method,
        // and Nuvei sends `errCode` unquoted so json_decode keeps it an int — asking a response
        // for its message raised `TypeError: must be of type ?string, int returned`. And what
        // actually reaches this branch is `errCode => 0`, because the SDK converts a non-empty
        // code into its own error shape first: 0 is Nuvei's marker for NO error, so reporting
        // it as the message would put "0" where a caller expects nothing to say.
        return $code === null || (int) $code === 0 ? null : (string) $code;
    }
}
