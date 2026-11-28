<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Erases the personal data of a deleted customer account from its gift cards; balances and history stay.
 *
 * On "customer_delete_before", inside the delete transaction: a delete that fails leaves the cards as they
 * were, and the customer's orders still name the account (core empties sales_order.customer_id on delete).
 */
class ForgetCustomer implements ObserverInterface
{
    /**
     * @param GiftCards $giftCards
     */
    public function __construct(private readonly GiftCards $giftCards)
    {
    }

    /**
     * Erase the data of the customer being deleted.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $customer = $observer->getData('customer');
        if ($customer instanceof DataObject && (int) $customer->getId() > 0) {
            $this->giftCards->forgetCustomer((int) $customer->getId(), (string) $customer->getData('email'));
        }
    }
}
