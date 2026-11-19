<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Refunding a gift card that was bought disables as many of the cards the order issued.
 */
class Revoke implements ObserverInterface
{
    /**
     * @param GiftCards $giftCards
     */
    public function __construct(private readonly GiftCards $giftCards)
    {
    }

    /**
     * For each refunded gift card line, disable as many of its cards as units refunded.
     *
     * A refunded card that was already partly spent is noted on the order: the merchant refunded more than
     * what was left on it.
     *
     * Shortcut: an adjustment refund without quantities disables nothing.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getData('creditmemo');
        $order = $creditmemo->getOrder();
        $comment = (string) __('Credit memo for order %1', $order->getIncrementId());
        foreach ($creditmemo->getAllItems() as $item) {
            $qty = (int) $item->getQty();
            if ($qty <= 0 || $item->getOrderItem()->getParentItemId()) {
                continue;
            }
            foreach ($this->giftCards->revoke((int) $item->getOrderItemId(), $qty, $comment) as $card) {
                $order->addCommentToStatusHistory((string) __(
                    'Gift card %1 was refunded and disabled, but only %2 of its %3 was left on it.',
                    $this->giftCards->mask((string) $card['code']),
                    $order->getBaseCurrency()->formatTxt((float) $card['balance']),
                    $order->getBaseCurrency()->formatTxt((float) $card['initial_balance'])
                ));
            }
        }
    }
}
