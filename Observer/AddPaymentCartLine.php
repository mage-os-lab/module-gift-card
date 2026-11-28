<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\Cart;

/**
 * Adds a "Gift Card" line, negative, to the cart that payment methods send with the payment (PayPal), so that
 * its lines add up to what the customer pays.
 *
 * Base currency, like the rest of the payment cart. A cart whose lines PayPal cannot take (gift card larger
 * than the items, when it also pays shipping) is sent by core with its totals only, which still add up.
 */
class AddPaymentCartLine implements ObserverInterface
{
    /**
     * Add the line when the quote or order was paid in part with gift cards.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $cart = $observer->getData('cart');
        if (!$cart instanceof Cart) {
            return;
        }
        $amount = (float) $cart->getSalesModel()->getDataUsingMethod('base_giftcard_amount');
        if ($amount > 0) {
            $cart->addCustomItem((string) __('Gift Card'), 1, -$amount, 'giftcard');
        }
    }
}
