<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Gives the balance back: failed order placement, canceled order (the part not invoiced), credit memo.
 *
 * "order_cancel_after" does not fire when a payment in review is denied (core cancels the order without it),
 * so an order saved as newly canceled is handled too, unless its cancellation was already handled in this request.
 */
class Restore implements ObserverInterface
{
    private const RESTORED = 'mageos_giftcard_restored';

    /**
     * @param GiftCards $giftCards
     * @param OrderResource $orderResource
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly OrderResource $orderResource
    ) {
    }

    /**
     * With several cards, the last one used is refilled first, each up to what the order took from it and has
     * not got back yet (from the card history). An order the history does not know (placed before 1.1.0) gets
     * the whole amount on a cancellation or credit memo, and nothing on a failed checkout.
     * Money put back on a disabled card is noted on the order for the merchant.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getData('creditmemo');
        $order = $creditmemo ? $creditmemo->getOrder() : $observer->getData('order');
        $event = $observer->getData('event');
        if ($event instanceof Event && $event->getName() === 'sales_order_save_before'
            && ($order->getData(self::RESTORED)
                || $order->getState() !== Order::STATE_CANCELED
                || $order->getOrigData('state') === Order::STATE_CANCELED)
        ) {
            return;
        }
        if ($observer->getData('quote') && $order->getId() && $this->isSaved($order)) {
            // core also reports a failure that comes after the order was saved: the order stays, paid by the card
            return;
        }
        if (!$creditmemo) {
            // the history cap alone would let a second pass give back an invoiced share that was not refunded
            $order->setData(self::RESTORED, true);
        }
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
            $used = $this->giftCards->getUsedByOrder($code, $number);
            $credit = min($amount, $used ?? ($observer->getData('quote') ? 0.0 : $amount));
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

    /**
     * Whether the order is really in the database: a save that failed can leave an ID on the object.
     *
     * @param Order $order
     */
    private function isSaved(Order $order): bool
    {
        $connection = $this->orderResource->getConnection();
        return $connection && $connection->fetchOne(
            $connection->select()
                ->from($this->orderResource->getMainTable(), 'entity_id')
                ->where('entity_id = ?', $order->getId())
        );
    }
}
