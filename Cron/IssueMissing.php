<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Cron;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Issuer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Issues the gift cards that a paid invoice should have issued and did not: an error while they were created,
 * a request that died after the invoice was saved. The customer paid, so the cards are owed.
 *
 * Only order lines that remember they sold a gift card are looked at: those placed since this job exists.
 */
class IssueMissing
{
    /** Seconds left to the invoice's own request to issue its cards. */
    public const AFTER = 300;
    /** Seconds a paid invoice is looked at. */
    public const WINDOW = 604800;

    private const LOCK = 'mageos_giftcard_issue_missing';

    /**
     * @param GiftCards $giftCards
     * @param Issuer $issuer
     * @param ResourceConnection $resource
     * @param OrderRepositoryInterface $orderRepository
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly Issuer $issuer,
        private readonly ResourceConnection $resource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Cards a line still lacks.
     *
     * Never more than the units paid less the cards ever issued, so a card disabled by an admin is not replaced;
     * and never more than the units paid and not refunded less the active cards, so a refunded unit gets no card.
     *
     * @param int $paid units of the line on paid invoices
     * @param int $refunded units of the line refunded
     * @param int $issued cards the line ever issued
     * @param int $active cards of the line that are active
     */
    public function missing(int $paid, int $refunded, int $issued, int $active): int
    {
        return max(0, min($paid - $issued, $paid - $refunded - $active));
    }

    /**
     * Issue the missing cards, unless another run is at it; an order in error does not stop the others.
     */
    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK, 0)) {
            return;
        }
        try {
            foreach ($this->missingByOrder() as $orderId => $missing) {
                try {
                    $order = $this->orderRepository->get($orderId);
                    $issued = 0;
                    foreach ($order instanceof Order ? $order->getInvoiceCollection() : [] as $invoice) {
                        if ((int) $invoice->getState() === Invoice::STATE_PAID) {
                            $issued += $this->issuer->issue($invoice, $missing);
                        }
                    }
                    if ($issued) {
                        $this->logger->warning(sprintf(
                            'Order %s: %d gift card(s) issued late, the paid invoice had not issued them.',
                            $order->getIncrementId(),
                            $issued
                        ));
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Missing gift cards of order ID ' . $orderId, ['exception' => $e]);
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }

    /**
     * Cards to issue per order and order line, for the gift card lines with an invoice paid in the last week.
     *
     * Invoices paid in the last minutes are left out, of the search and of the units paid: their own request
     * may still be issuing their cards.
     *
     * @return array<int, array<int, int>>
     */
    private function missingByOrder(): array
    {
        $connection = $this->resource->getConnection('sales');
        $until = gmdate('Y-m-d H:i:s', time() - self::AFTER);
        $paidLines = fn () => $connection->select()
            ->from(['i' => $this->resource->getTableName('sales_invoice', 'sales')], [])
            ->join(
                ['ii' => $this->resource->getTableName('sales_invoice_item', 'sales')],
                'ii.parent_id = i.entity_id',
                []
            )
            ->join(
                ['oi' => $this->resource->getTableName('sales_order_item', 'sales')],
                'oi.item_id = ii.order_item_id',
                []
            )
            ->where('i.state = ?', Invoice::STATE_PAID)
            ->where('i.updated_at <= ?', $until)
            ->where('oi.is_giftcard = 1')
            // a line paid 0 issues no card
            ->where('ii.base_row_total_incl_tax - COALESCE(ii.base_discount_amount, 0) > 0')
            ->where('oi.parent_item_id IS NULL');
        // ponytail: looks at a week of gift card orders again each run; keep a high-water mark if that gets slow
        $orderIds = $connection->fetchCol(
            $paidLines()->distinct()->columns('oi.order_id')
                ->where('i.updated_at >= ?', gmdate('Y-m-d H:i:s', time() - self::WINDOW))
        );
        if (!$orderIds) {
            return [];
        }
        $lines = $connection->fetchAll(
            $paidLines()->columns(['oi.item_id', 'oi.order_id', 'oi.qty_refunded', 'paid' => 'SUM(ii.qty)'])
                ->where('oi.order_id IN (?)', $orderIds)
                ->group(['oi.item_id', 'oi.order_id', 'oi.qty_refunded'])
        );
        $counts = $this->giftCards->countByOrderItems(array_map('intval', array_column($lines, 'item_id')));
        $orders = [];
        foreach ($lines as $line) {
            $count = $counts[(int) $line['item_id']] ?? ['all' => 0, 'active' => 0];
            $missing = $this->missing(
                (int) $line['paid'],
                (int) $line['qty_refunded'],
                $count['all'],
                $count['active']
            );
            if ($missing > 0) {
                $orders[(int) $line['order_id']][(int) $line['item_id']] = $missing;
            }
        }
        return $orders;
    }
}
