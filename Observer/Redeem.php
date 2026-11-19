<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Debits the applied gift cards when the order is placed, and copies the amounts to the order.
 *
 * "sales_model_service_quote_submit_before" fires before core's try block: an exception thrown later on that
 * event (by another module) would leave the cards debited with no order and no failure event. So it only notes
 * the cards on the order; they are debited on "sales_order_place_before", inside the try, where a failure fires
 * "sales_model_service_quote_submit_failure" and Restore gives the money back.
 */
class Redeem implements ObserverInterface
{
    private const PENDING = 'mageos_giftcard_pending';

    /**
     * @param GiftCards $giftCards
     */
    public function __construct(private readonly GiftCards $giftCards)
    {
    }

    /**
     * Cards are debited in the order applied, as the quote total took them. If they no longer cover the amount,
     * the debits already made are given back before refusing the order. Amounts are copied to the order only
     * after the debits, because Restore relies on them.
     *
     * Shortcut: one order per quote; multi-address checkout does not use gift cards.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getData('order');
        $quote = $observer->getData('quote');
        if ($quote) {
            if ((float) $quote->getData('base_giftcard_amount') > 0) {
                $order->setData(self::PENDING, [
                    'codes' => array_filter(explode(',', (string) $quote->getData('giftcard_code'))),
                    'base_giftcard_amount' => $quote->getData('base_giftcard_amount'),
                    'giftcard_amount' => $quote->getData('giftcard_amount'),
                ]);
            }
            return;
        }
        $pending = $order->getData(self::PENDING);
        if (!$pending) {
            return;
        }
        $order->unsetData(self::PENDING);
        $left = round((float) $pending['base_giftcard_amount'], 4);
        $number = (string) $order->getIncrementId();
        $debited = [];
        foreach ($pending['codes'] as $code) {
            $amount = round(min((float) $this->giftCards->getBalance($code), $left), 4);
            if ($amount > 0 && $this->giftCards->debit($code, $amount, $number)) {
                $debited[$code] = $amount;
                $left = round($left - $amount, 4);
            }
        }
        if ($left > 0) {
            foreach ($debited as $code => $amount) {
                $this->giftCards->credit($code, $amount, $number, GiftCards::CANCELED);
            }
            throw new LocalizedException(__('The gift card balance has changed. Please review your cart.'));
        }
        $order->setData('giftcard_code', implode(',', array_keys($debited)));
        $order->setData('base_giftcard_amount', $pending['base_giftcard_amount']);
        $order->setData('giftcard_amount', $pending['giftcard_amount']);
    }
}
