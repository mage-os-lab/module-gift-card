<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Cron;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives back what gift cards paid for orders that were never saved.
 *
 * Cards are debited just before the order is saved. When the request dies in between (PHP killed, fatal error,
 * server restart), core reports no failure and Observer\Restore never runs: the money is gone with no order.
 *
 * Only an order number that is in no order at all, an hour after the debit, is given back: saving an order takes
 * seconds. An order that exists is never touched, whatever its state; its cancellation and credit memos give the
 * money back.
 */
class GiveBackOrphans
{
    /** Seconds after which a debited order that is still not saved will never be. */
    public const AFTER = 3600;
    /** Seconds a debit is looked at. */
    public const WINDOW = 604800;

    private const LOCK = 'mageos_giftcard_give_back_orphans';

    /**
     * @param GiftCards $giftCards
     * @param ResourceConnection $resource
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check the debits of the last week against the orders, unless another run is at it.
     *
     * An error while reading the orders stops the run before anything is given back.
     */
    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK, 0)) {
            return;
        }
        try {
            // ponytail: reads a week of debits again each hour; keep a high-water mark if that gets slow
            $debits = $this->giftCards->getDebitsBetween(
                gmdate('Y-m-d H:i:s', time() - self::WINDOW),
                gmdate('Y-m-d H:i:s', time() - self::AFTER)
            );
            $saved = $this->savedOrders(array_column($debits, 'order_increment_id'));
            foreach ($debits as $debit) {
                $number = (string) $debit['order_increment_id'];
                if (isset($saved[strtolower($number)])) {
                    continue;
                }
                $amount = $this->giftCards->creditOrphan((string) $debit['code'], $number);
                if ($amount > 0) {
                    $this->logger->warning(sprintf(
                        'Gift card %s: %.2F given back, order %s was debited but never saved.',
                        $this->giftCards->mask((string) $debit['code']),
                        $amount,
                        $number
                    ));
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }

    /**
     * Which of these order numbers are in the orders table, as lower-case keys.
     *
     * Any store: the history keeps the number alone, and a number used by another store's order is not given back.
     *
     * @param string[] $numbers
     * @return array<string, true>
     */
    private function savedOrders(array $numbers): array
    {
        $connection = $this->resource->getConnection('sales');
        $saved = [];
        foreach (array_chunk(array_unique($numbers), 1000) as $chunk) {
            $found = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('sales_order', 'sales'), 'increment_id')
                    ->where('increment_id IN (?)', $chunk)
            );
            foreach ($found as $number) {
                $saved[strtolower((string) $number)] = true;
            }
        }
        return $saved;
    }
}
