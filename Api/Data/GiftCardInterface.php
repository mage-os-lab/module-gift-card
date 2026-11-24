<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Api\Data;

/**
 * A gift card as its holder sees it. Amounts are in the base currency, dates in UTC.
 *
 * @api
 */
interface GiftCardInterface
{
    /**
     * Code of the card; only its last characters for a gift listed in the recipient's account.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Balance left on the card.
     *
     * @return float
     */
    public function getBalance(): float;

    /**
     * What can still be done with the card: "active", "disabled", "expired" or "used_up".
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Expiry date, null when the card never expires.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string;

    /**
     * What the card pays of the cart it is applied to; null outside a cart.
     *
     * @return float|null
     */
    public function getAppliedAmount(): ?float;
}
