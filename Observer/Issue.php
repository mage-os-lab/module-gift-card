<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\Issuer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Issues the gift cards of an invoice when it is paid.
 *
 * "sales_order_invoice_pay" fires before the invoice is saved, so it only marks the invoice; the codes are created
 * and sent on "sales_order_invoice_save_commit_after", once the paid invoice is really in the database.
 * Cards this step leaves out (an error, a request that died) are issued by MageOS\GiftCard\Cron\IssueMissing.
 */
class Issue implements ObserverInterface
{
    private const PENDING = 'mageos_giftcard_pending';

    /**
     * @param Issuer $issuer
     */
    public function __construct(private readonly Issuer $issuer)
    {
    }

    /**
     * Mark the invoice when it is paid, issue its cards once it is committed.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $invoice = $observer->getData('invoice');
        if ($observer->getEvent()->getName() === 'sales_order_invoice_pay') {
            $invoice->setData(self::PENDING, true);
            return;
        }
        if (!$invoice->getData(self::PENDING)) {
            return;
        }
        $invoice->unsetData(self::PENDING);
        $this->issuer->issue($invoice);
    }
}
