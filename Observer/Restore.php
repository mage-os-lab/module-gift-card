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
     * @inheritdoc
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
        if ($amount > 0 && $order->getData('giftcard_code')) {
            $this->giftCards->credit((string) $order->getData('giftcard_code'), $amount);
        }
    }
}
