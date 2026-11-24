<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Data;

use MageOS\GiftCard\Api\Data\GiftCardInterface;

/**
 * A gift card in an API answer.
 */
class GiftCard implements GiftCardInterface
{
    /**
     * @param string $code
     * @param float $balance
     * @param string $status
     * @param string|null $expiresAt
     * @param float|null $appliedAmount
     */
    public function __construct(
        private readonly string $code,
        private readonly float $balance,
        private readonly string $status,
        private readonly ?string $expiresAt = null,
        private readonly ?float $appliedAmount = null
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritdoc
     */
    public function getBalance(): float
    {
        return $this->balance;
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @inheritdoc
     */
    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    /**
     * @inheritdoc
     */
    public function getAppliedAmount(): ?float
    {
        return $this->appliedAmount;
    }
}
