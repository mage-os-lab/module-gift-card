<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Gift card balances, in base currency.
 */
class GiftCards
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Create a gift card with a random code and return the code.
     *
     * @param float $balance
     */
    public function create(float $balance): string
    {
        $code = strtoupper(bin2hex(random_bytes(8)));
        $this->resource->getConnection()->insert($this->table(), ['code' => $code, 'balance' => $balance]);
        return $code;
    }

    /**
     * Balance of the code.
     *
     * @param string $code
     * @return float|null null when the code does not exist
     */
    public function getBalance(string $code): ?float
    {
        $connection = $this->resource->getConnection();
        $balance = $connection->fetchOne(
            $connection->select()->from($this->table(), 'balance')->where('code = ?', $code)
        );
        return is_numeric($balance) ? (float) $balance : null;
    }

    /**
     * Atomic: returns false, and changes nothing, when the balance does not cover the amount.
     *
     * @param string $code
     * @param float $amount
     */
    public function debit(string $code, float $amount): bool
    {
        return (bool) $this->resource->getConnection()->update(
            $this->table(),
            ['balance' => new Expression(sprintf('balance - %.4F', $amount))],
            ['code = ?' => $code, 'balance >= ?' => sprintf('%.4F', $amount)]
        );
    }

    /**
     * Add the amount to the balance.
     *
     * @param string $code
     * @param float $amount
     */
    public function credit(string $code, float $amount): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['balance' => new Expression(sprintf('balance + %.4F', $amount))],
            ['code = ?' => $code]
        );
    }

    /**
     * Gift card table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName('mageos_giftcard');
    }
}
