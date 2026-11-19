<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Gives the balance back: failed order placement, canceled order (the part not invoiced), credit memo.
 */
class Restore implements ObserverInterface
{
    /**
     * @param GiftCards $giftCards
     */
    public function __construct(private readonly GiftCards $giftCards)
    {
    }

    /**
     * With several cards, the last one used is refilled first, each up to what the order took from it
     * (from the card history). On a cancellation or credit memo a single card gets the whole amount, so orders
     * placed before 1.1.0 keep working; a failed checkout only gives back what the history shows was debited.
     * Money put back on a disabled card is noted on the order for the merchant.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getData('creditmemo');
        $order = $creditmemo ? $creditmemo->getOrder() : $observer->getData('order');
        if ($creditmemo) {
            $amount = (float) $creditmemo->getData('base_giftcard_amount');
        } else {
            $amount = (float) $order->getData('base_giftcard_amount');
            foreach ($order->getInvoiceCollection() as $invoice) {
                $amount -= $invoice->isCanceled() ? 0 : (float) $invoice->getData('base_giftcard_amount');
            }
        }
        $codes = array_filter(explode(',', (string) $order->getData('giftcard_code')));
        $number = (string) $order->getIncrementId();
        $action = $creditmemo ? GiftCards::REFUNDED : GiftCards::CANCELED;
        foreach (array_reverse($codes) as $code) {
            $credit = count($codes) === 1 && !$observer->getData('quote')
                ? $amount
                : min($amount, $this->giftCards->getUsedByOrder($code, $number));
            if ($credit > 0) {
                if (!$this->giftCards->credit($code, $credit, $number, $action) && $order->getId()) {
                    $order->addCommentToStatusHistory((string) __(
                        'Gift card %1 is disabled: the %2 given back stays on it until it is enabled again.',
                        $this->giftCards->mask($code),
                        $order->getBaseCurrency()->formatTxt($credit)
                    ));
                }
                $amount -= $credit;
            }
        }
    }
}
