<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Debits the gift card right before the order is placed, and copies the amounts to the order.
 */
class Redeem implements ObserverInterface
{
    /**
     * @param GiftCards $giftCards
     */
    public function __construct(private readonly GiftCards $giftCards)
    {
    }

    /**
     * Amounts are copied to the order only after a successful debit, because Restore relies on them.
     *
     * Shortcut: one order per quote; multi-address checkout would debit once per order.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getData('quote');
        $order = $observer->getData('order');
        $baseAmount = (float) $quote->getData('base_giftcard_amount');
        if ($baseAmount <= 0) {
            return;
        }
        if (!$this->giftCards->debit((string) $quote->getData('giftcard_code'), $baseAmount)) {
            throw new LocalizedException(__('The gift card balance has changed. Please review your cart.'));
        }
        $order->setData('giftcard_code', $quote->getData('giftcard_code'));
        $order->setData('base_giftcard_amount', $baseAmount);
        $order->setData('giftcard_amount', $quote->getData('giftcard_amount'));
    }
}
