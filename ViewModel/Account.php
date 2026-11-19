<?php
declare(strict_types=1);

namespace MageOS\GiftCard\ViewModel;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Customer\Model\Session;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Gift cards of the logged-in customer.
 */
class Account implements ArgumentInterface
{
    /**
     * @param GiftCards $giftCards
     * @param Session $customerSession
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly Session $customerSession,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * Cards of the logged-in customer, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCards(): array
    {
        return $this->giftCards->getByCustomer((int) $this->customerSession->getCustomerId());
    }

    /**
     * Code shown in the account, shortened for a gift.
     *
     * A gift's code went to the recipient's e-mail: someone who opened an account with that address first
     * would see it otherwise. A card the customer bought for themselves shows whole.
     *
     * @param array<string,mixed> $card
     */
    public function getCode(array $card): string
    {
        return $card['recipient_email'] ? $this->giftCards->mask((string) $card['code']) : (string) $card['code'];
    }

    /**
     * Balance in the current currency.
     *
     * @param array<string,mixed> $card
     */
    public function getBalance(array $card): string
    {
        return $this->priceCurrency->convertAndFormat((float) $card['balance'], false);
    }

    /**
     * What the customer can still do with the card.
     *
     * @param array<string,mixed> $card
     */
    public function getStatus(array $card): Phrase
    {
        return match (true) {
            !$card['is_active'] => __('Disabled'),
            $card['expires_at'] && $card['expires_at'] <= gmdate('Y-m-d H:i:s') => __('Expired'),
            (float) $card['balance'] <= 0 => __('Used up'),
            default => __('Active'),
        };
    }
}
