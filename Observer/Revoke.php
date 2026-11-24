<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

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
     * The credit memo is refused when the refunded cards were spent for more than its adjustment fee: the
     * merchant would give back money the customer already used. The exception rolls back core's transaction,
     * and with it the cards disabled here.
     *
     * An adjustment refund without quantities disables nothing. So whatever the credit memo holds, it is
     * also refused when the customer would be left with more than was paid: the money refunded on the order
     * so far, plus the value of the cards the order issued that are still active.
     *
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getData('creditmemo');
        $order = $creditmemo->getOrder();
        $comment = (string) __('Credit memo for order %1', $order->getIncrementId());
        $spent = 0.0;
        foreach ($creditmemo->getAllItems() as $item) {
            $qty = (int) $item->getQty();
            if ($qty <= 0 || $item->getOrderItem()->getParentItemId()) {
                continue;
            }
            foreach ($this->giftCards->revoke((int) $item->getOrderItemId(), $qty, $comment) as $card) {
                $spent += (float) $card['initial_balance'] - (float) $card['balance'];
                $order->addCommentToStatusHistory((string) __(
                    'Gift card %1 was refunded and disabled, but only %2 of its %3 was left on it.',
                    $this->giftCards->mask((string) $card['code']),
                    $order->getBaseCurrency()->formatTxt((float) $card['balance']),
                    $order->getBaseCurrency()->formatTxt((float) $card['initial_balance'])
                ));
            }
        }
        if (round($spent - (float) $creditmemo->getData('base_adjustment_negative'), 4) > 0) {
            throw new LocalizedException(__(
                'The refunded gift cards were already spent for %1. Enter at least that amount as Adjustment Fee, '
                . 'or refund fewer of them.',
                $order->getBaseCurrency()->formatTxt($spent)
            ));
        }
        // core added this credit memo to the order's refunded total before the event
        $held = $this->giftCards->getHeldByOrder((int) $order->getId());
        $paid = (float) $order->getBaseTotalPaid() + (float) $order->getData('base_giftcard_amount');
        if ($held > 0 && round((float) $order->getBaseTotalRefunded() + $held - $paid, 4) > 0) {
            throw new LocalizedException(__(
                'This refund would leave the customer with more than was paid: gift cards of this order worth %1 '
                . 'are still active. Refund the gift card lines with their quantities, or disable the cards first.',
                $order->getBaseCurrency()->formatTxt($held)
            ));
        }
    }
}
