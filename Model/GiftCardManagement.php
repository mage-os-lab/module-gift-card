<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use MageOS\GiftCard\Api\GiftCardManagementInterface;
use MageOS\GiftCard\Model\Data\GiftCard;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * REST face of the gift cards: finds the cart, then leaves the rules to CartGiftCards.
 */
class GiftCardManagement implements GiftCardManagementInterface
{
    /**
     * @param CartGiftCards $cartGiftCards
     * @param GiftCards $giftCards
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        private readonly CartGiftCards $cartGiftCards,
        private readonly GiftCards $giftCards,
        private readonly CartRepositoryInterface $quoteRepository
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getList(int $cartId): array
    {
        return $this->cards($this->quote($cartId));
    }

    /**
     * @inheritdoc
     */
    public function apply(int $cartId, string $code): array
    {
        $quote = $this->quote($cartId);
        $this->cartGiftCards->apply($quote, $code);
        return $this->cards($quote);
    }

    /**
     * @inheritdoc
     */
    public function remove(int $cartId, string $code): array
    {
        $quote = $this->quote($cartId);
        $this->cartGiftCards->remove($quote, $code);
        return $this->cards($quote);
    }

    /**
     * @inheritdoc
     */
    public function getGuestList(string $cartId): array
    {
        return $this->cards($this->cartGiftCards->getQuote($cartId, 0));
    }

    /**
     * @inheritdoc
     */
    public function applyToGuestCart(string $cartId, string $code): array
    {
        $quote = $this->cartGiftCards->getQuote($cartId, 0);
        $this->cartGiftCards->apply($quote, $code);
        return $this->cards($quote);
    }

    /**
     * @inheritdoc
     */
    public function removeFromGuestCart(string $cartId, string $code): array
    {
        $quote = $this->cartGiftCards->getQuote($cartId, 0);
        $this->cartGiftCards->remove($quote, $code);
        return $this->cards($quote);
    }

    /**
     * @inheritdoc
     */
    public function getCustomerCards(int $customerId): array
    {
        return array_map(
            fn (array $card) => new GiftCard(
                $this->giftCards->ownerCode($card),
                (float) $card['balance'],
                $this->giftCards->status($card),
                $card['expires_at'] === null ? null : (string) $card['expires_at']
            ),
            $this->giftCards->getByCustomer($customerId)
        );
    }

    /**
     * The customer's active cart; the web API puts the ID of the logged-in customer's cart in $cartId.
     *
     * @param int $cartId
     * @throws NoSuchEntityException
     */
    private function quote(int $cartId): Quote
    {
        $quote = $this->quoteRepository->getActive($cartId);
        if (!$quote instanceof Quote) {
            throw NoSuchEntityException::singleField('cartId', $cartId);
        }
        return $quote;
    }

    /**
     * Cards applied to the cart, as API data.
     *
     * @param Quote $quote
     * @return GiftCard[]
     */
    private function cards(Quote $quote): array
    {
        return array_map(
            fn (array $card) => new GiftCard(
                $card['code'],
                $card['balance'],
                $card['status'],
                $card['expires_at'],
                $card['applied']
            ),
            $this->cartGiftCards->getApplied($quote)
        );
    }
}
