<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use Exception;
use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * When an invoice is paid, creates one gift card per purchased unit of a product flagged "Gift Card"
 * (balance = unit price incl. tax, base currency) and e-mails the codes to the buyer.
 *
 * "sales_order_invoice_pay" fires before the invoice is saved, so it only marks the invoice; the codes are created
 * and sent on "sales_order_invoice_save_commit_after", once the paid invoice is really in the database.
 */
class Issue implements ObserverInterface
{
    private const PENDING = 'mageos_giftcard_pending';

    /**
     * @param GiftCards $giftCards
     * @param TransportBuilder $transportBuilder
     * @param LoggerInterface $logger
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly TransportBuilder $transportBuilder,
        private readonly LoggerInterface $logger,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository
    ) {
    }

    /**
     * The order comment is the merchant's record of the issued codes, even if the e-mail fails.
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
        $order = $invoice->getOrder();
        $codes = [];
        foreach ($invoice->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ($orderItem->getParentItemId() || !$orderItem->getProduct()?->getData('is_giftcard')) {
                continue;
            }
            $qty = (int) $item->getQty();
            for ($i = 0; $i < $qty; $i++) {
                $codes[] = $this->giftCards->create((float) $item->getBasePriceInclTax())
                    . ' — ' . $order->getBaseCurrency()->formatTxt($item->getBasePriceInclTax());
            }
        }
        if (!$codes) {
            return;
        }
        $this->historyRepository->save(
            $order->addCommentToStatusHistory((string) __('Gift cards issued: %1', implode(', ', $codes)))
        );
        try {
            $this->transportBuilder
                ->setTemplateIdentifier('mageos_giftcard_issued')
                ->setTemplateOptions(['area' => 'frontend', 'store' => $order->getStoreId()])
                ->setTemplateVars(['codes' => implode("\n", $codes), 'order_id' => $order->getIncrementId()])
                ->setFromByScope('sales', $order->getStoreId())
                ->addTo($order->getCustomerEmail())
                ->getTransport()
                ->sendMessage();
        } catch (Exception $e) {
            $this->logger->error('Gift card e-mail failed for order ' . $order->getIncrementId(), ['exception' => $e]);
        }
    }
}
