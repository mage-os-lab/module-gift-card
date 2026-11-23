<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Gift card balances, in base currency; every change is written to the card's history. Dates are UTC.
 */
class GiftCards
{
    public const ISSUED = 'issued';
    public const USED = 'used';
    public const REFUNDED = 'refunded';
    public const CANCELED = 'canceled';
    public const ADJUSTED = 'adjusted';
    public const EXPIRY = 'expiry';
    public const ENABLED = 'enabled';
    public const DISABLED = 'disabled';
    public const SENT = 'sent';

    public const XML_VALIDITY = 'mageos_giftcard/general/validity_months';
    public const MAX_SEND_ATTEMPTS = 5;
    /** Days an expired card can be used again after an order gives money back to it. */
    public const CREDIT_GRACE_DAYS = 30;
    /** Admins without this resource see only the end of the codes: a code is all it takes to spend a card. */
    public const ACL_CODES = 'MageOS_GiftCard::cards_resend';

    /**
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $config
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Code shortened to its last four characters, for order comments and e-mails to someone else than the holder.
     *
     * @param string $code
     */
    public function mask(string $code): string
    {
        return '…' . substr($code, -4);
    }

    /**
     * Create a gift card with a random code and return the code.
     *
     * Without an owner, a card for a recipient belongs to the customer account with the recipient's e-mail.
     * Without "expires_at", the card expires after the configured validity.
     *
     * @param float $balance
     * @param array<string,mixed> $card other columns: order_id, order_item_id, customer_id, store_id, sender_name,
     *     recipient_name, recipient_email, message, send_at, expires_at
     * @param string|null $orderNumber
     * @param string|null $adminUser
     */
    public function create(
        float $balance,
        array $card = [],
        ?string $orderNumber = null,
        ?string $adminUser = null
    ): string {
        if (empty($card['customer_id']) && !empty($card['recipient_email'])) {
            $card['customer_id'] = $this->customerId((string) $card['recipient_email'], (int) ($card['store_id'] ?? 0));
        }
        if (!array_key_exists('expires_at', $card)) {
            $card['expires_at'] = $this->validityEnd();
        }
        $code = strtoupper(bin2hex(random_bytes(8)));
        $this->resource->getConnection()->insert(
            $this->table(),
            ['code' => $code, 'balance' => $balance, 'initial_balance' => $balance] + $card
        );
        $this->log(['code = ?' => $code], self::ISSUED, $balance, $orderNumber, $adminUser);
        return $code;
    }

    /**
     * Balance the customer can spend.
     *
     * @param string $code
     * @return float|null null when the code does not exist, or the card is disabled or expired
     */
    public function getBalance(string $code): ?float
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->table(), 'balance')->where('code = ?', $code);
        foreach ($this->spendable() as $condition => $value) {
            $select->where($condition, $value);
        }
        $balance = $connection->fetchOne($select);
        return is_numeric($balance) ? (float) $balance : null;
    }

    /**
     * Atomic: returns false, and changes nothing, when the card cannot pay the whole amount.
     *
     * @param string $code
     * @param float $amount
     * @param string|null $orderNumber order paid with the card
     */
    public function debit(string $code, float $amount, ?string $orderNumber = null): bool
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $debited = (bool) $connection->update(
                $this->table(),
                ['balance' => new Expression(sprintf('balance - %.4F', $amount))],
                ['code = ?' => $code, 'balance >= ?' => sprintf('%.4F', $amount)] + $this->spendable()
            );
            if ($debited) {
                $this->log(['code = ?' => $code], self::USED, -$amount, $orderNumber);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return $debited;
    }

    /**
     * Add the amount to the balance and return whether the card can spend it.
     *
     * An expired card can be used for CREDIT_GRACE_DAYS more days, so the money is not lost; not for a whole
     * new validity, or an order placed and canceled around the expiry would renew the card for ever.
     * A disabled card stays disabled, and false tells the caller to warn the merchant.
     *
     * @param string $code
     * @param float $amount
     * @param string|null $orderNumber order refunded or canceled
     * @param string $action REFUNDED or CANCELED
     */
    public function credit(
        string $code,
        float $amount,
        ?string $orderNumber = null,
        string $action = self::REFUNDED
    ): bool {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $where = ['code = ?' => $code];
            $credited = $connection->update(
                $this->table(),
                ['balance' => new Expression(sprintf('balance + %.4F', $amount))],
                $where
            );
            if ($credited) {
                $this->log($where, $action, $amount, $orderNumber);
                $expires = gmdate('Y-m-d H:i:s', time() + self::CREDIT_GRACE_DAYS * 86400);
                $extended = $connection->update(
                    $this->table(),
                    ['expires_at' => $expires],
                    $where + ['expires_at <= ?' => gmdate('Y-m-d H:i:s')]
                );
                if ($extended) {
                    $this->log($where, self::EXPIRY, 0.0, $orderNumber, null, $this->formatExpiry($expires));
                }
            }
            $active = (bool) $connection->fetchOne(
                $connection->select()->from($this->table(), 'is_active')->where('code = ?', $code)
            );
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return $active;
    }

    /**
     * What the order took from the card and has not given back yet, from the history.
     *
     * @param string $code
     * @param string $orderNumber
     * @return float|null null when the history knows nothing of this order (placed before 1.1.0)
     */
    public function getUsedByOrder(string $code, string $orderNumber): ?float
    {
        $connection = $this->resource->getConnection();
        $sum = $connection->fetchOne(
            $connection->select()
                ->from(['h' => $this->resource->getTableName('mageos_giftcard_history')], 'SUM(h.amount)')
                ->join(['c' => $this->table()], 'c.giftcard_id = h.giftcard_id', [])
                ->where('c.code = ?', $code)
                ->where('h.order_increment_id = ?', $orderNumber)
                ->where('h.action IN (?)', [self::USED, self::REFUNDED, self::CANCELED])
        );
        return is_numeric($sum) ? max(0.0, -(float) $sum) : null;
    }

    /**
     * Admin change of a card: balance, status and / or expiry, recorded with the admin's user name.
     *
     * A new balance needs "expected_balance", the balance the admin saw: if the card was used meanwhile, nothing
     * changes and an exception says so.
     *
     * @param int $id
     * @param array<string,mixed> $data "balance" and "expected_balance" (float), "is_active" (bool),
     *     "expires_at" (UTC string or null)
     * @param string|null $adminUser
     * @throws LocalizedException when the balance changed since the admin saw it
     */
    public function update(int $id, array $data, ?string $adminUser = null): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $card = $connection->fetchRow(
                $connection->select()->from($this->table())->where('giftcard_id = ?', $id)->forUpdate()
            );
            if (!$card) {
                $connection->commit();
                return;
            }
            if (isset($data['balance'])
                && round((float) ($data['expected_balance'] ?? -1), 4) !== round((float) $card['balance'], 4)
            ) {
                throw new LocalizedException(
                    __('The balance of gift card %1 changed meanwhile. Check it and try again.', $card['code'])
                );
            }
            $delta = isset($data['balance']) ? round((float) $data['balance'] - (float) $card['balance'], 4) : 0.0;
            $active = isset($data['is_active']) ? (int) $data['is_active'] : (int) $card['is_active'];
            $expires = array_key_exists('expires_at', $data) ? $data['expires_at'] : $card['expires_at'];
            $connection->update(
                $this->table(),
                [
                    'balance' => sprintf('%.4F', (float) $card['balance'] + $delta),
                    'is_active' => $active,
                    'expires_at' => $expires,
                ],
                ['giftcard_id = ?' => $id]
            );
            $where = ['giftcard_id = ?' => $id];
            if ($delta != 0) {
                $this->log($where, self::ADJUSTED, $delta, null, $adminUser);
            }
            if ($active !== (int) $card['is_active']) {
                $this->log($where, $active ? self::ENABLED : self::DISABLED, 0.0, null, $adminUser);
            }
            if ($expires !== $card['expires_at']) {
                $this->log($where, self::EXPIRY, 0.0, null, $adminUser, $this->formatExpiry($expires));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Disable $count cards issued by a refunded order line and return the ones already partly spent.
     *
     * Unspent cards go first, gifts not yet e-mailed before them, so the buyer cannot keep a full card
     * by having a spent one disabled.
     *
     * @param int $orderItemId
     * @param int $count
     * @param string $comment
     * @return array<int, array<string, mixed>> code, balance and initial_balance of the spent cards disabled
     */
    public function revoke(int $orderItemId, int $count, string $comment): array
    {
        $connection = $this->resource->getConnection();
        $cards = $connection->fetchAll(
            $connection->select()->from($this->table(), ['giftcard_id', 'code', 'balance', 'initial_balance'])
                ->where('order_item_id = ?', $orderItemId)
                ->where('is_active = 1')
                ->order(new Expression('balance < initial_balance'))
                ->order(new Expression('recipient_email IS NOT NULL AND sent_at IS NULL DESC'))
                ->order('balance DESC')
                ->order('giftcard_id')
                ->limit($count)
        );
        $spent = [];
        foreach ($cards as $card) {
            $connection->update($this->table(), ['is_active' => 0], ['giftcard_id = ?' => $card['giftcard_id']]);
            $this->log(['giftcard_id = ?' => $card['giftcard_id']], self::DISABLED, 0.0, null, null, $comment);
            if ((float) $card['balance'] < (float) $card['initial_balance']) {
                $spent[] = $card;
            }
        }
        return $spent;
    }

    /**
     * Number of cards an order line already issued, disabled ones included.
     *
     * @param int $orderItemId
     */
    public function countByOrderItem(int $orderItemId): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()->from($this->table(), 'COUNT(*)')->where('order_item_id = ?', $orderItemId)
        );
    }

    /**
     * Cards whose gift e-mail is due.
     *
     * Spendable, with a recipient, send date reached, not sent yet, and fewer than MAX_SEND_ATTEMPTS failures.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDue(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->table())
            ->where('recipient_email IS NOT NULL')
            ->where('sent_at IS NULL')
            ->where('send_at <= ?', gmdate('Y-m-d H:i:s'))
            ->where('send_attempts < ?', self::MAX_SEND_ATTEMPTS);
        foreach ($this->spendable() as $condition => $value) {
            $select->where($condition, $value);
        }
        return $connection->fetchAll($select);
    }

    /**
     * Count a failed gift e-mail; the cron stops trying after MAX_SEND_ATTEMPTS.
     *
     * @param int $id
     */
    public function markFailed(int $id): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['send_attempts' => new Expression('send_attempts + 1')],
            ['giftcard_id = ?' => $id]
        );
    }

    /**
     * Record that the card's code was e-mailed.
     *
     * @param int $id
     * @param string $email
     * @param string|null $adminUser who sent it again from the admin
     * @param bool $gift false when sent to someone else than the recipient: the gift itself is still to send
     */
    public function markSent(int $id, string $email, ?string $adminUser = null, bool $gift = true): void
    {
        if ($gift) {
            $this->resource->getConnection()->update(
                $this->table(),
                ['sent_at' => gmdate('Y-m-d H:i:s')],
                ['giftcard_id = ?' => $id, 'sent_at IS NULL']
            );
        }
        $this->log(['giftcard_id = ?' => $id], self::SENT, 0.0, null, $adminUser, $email);
    }

    /**
     * The card row, for the admin and the e-mails.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $connection = $this->resource->getConnection();
        $card = $connection->fetchRow($connection->select()->from($this->table())->where('giftcard_id = ?', $id));
        return $card ?: null;
    }

    /**
     * Cards of a customer account, newest first; a gift shows up only once e-mailed to its recipient.
     *
     * @param int $customerId
     * @return array<int, array<string, mixed>>
     */
    public function getByCustomer(int $customerId): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()->from($this->table())
                ->where('customer_id = ?', $customerId)
                ->where('recipient_email IS NULL OR sent_at IS NOT NULL')
                ->order('giftcard_id DESC')
        );
    }

    /**
     * Account with this e-mail in the website of the store, if any.
     *
     * @param string $email
     * @param int $storeId
     */
    private function customerId(string $email, int $storeId): ?int
    {
        try {
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            return (int) $this->customerRepository->get($email, $websiteId)->getId();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * End of the configured validity from now (UTC), or null when cards never expire.
     */
    private function validityEnd(): ?string
    {
        $months = (int) $this->config->getValue(self::XML_VALIDITY);
        return $months > 0 ? gmdate('Y-m-d H:i:s', (int) strtotime("+$months months")) : null;
    }

    /**
     * Store-local date of an expiry, as written in the history; "Never" without one.
     *
     * @param string|null $expires UTC
     */
    private function formatExpiry(?string $expires): string
    {
        return $expires === null
            ? (string) __('Never')
            : $this->timezone->formatDate(
                new \DateTime($expires, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM
            );
    }

    /**
     * Conditions on a card the customer can spend.
     *
     * @return array<string, mixed>
     */
    private function spendable(): array
    {
        return ['is_active = ?' => 1, 'expires_at IS NULL OR expires_at > ?' => gmdate('Y-m-d H:i:s')];
    }

    /**
     * One INSERT ... SELECT, so the balance recorded is the one the change left on the card.
     *
     * @param array<string,mixed> $where condition on the card row
     * @param string $action
     * @param float $amount
     * @param string|null $orderNumber
     * @param string|null $adminUser
     * @param string|null $comment
     */
    private function log(
        array $where,
        string $action,
        float $amount,
        ?string $orderNumber = null,
        ?string $adminUser = null,
        ?string $comment = null
    ): void {
        $connection = $this->resource->getConnection();
        $quote = fn (?string $value) => new Expression($value === null ? 'NULL' : $connection->quote($value));
        $select = $connection->select()->from($this->table(), [
            'giftcard_id',
            'action' => $quote($action),
            'amount' => new Expression(sprintf('%.4F', $amount)),
            'balance',
            'order_increment_id' => $quote($orderNumber),
            'admin_user' => $quote($adminUser),
            'comment' => $quote($comment === null ? null : mb_substr($comment, 0, 255)),
        ]);
        foreach ($where as $condition => $value) {
            $select->where($condition, $value);
        }
        $connection->query($connection->insertFromSelect(
            $select,
            $this->resource->getTableName('mageos_giftcard_history'),
            ['giftcard_id', 'action', 'amount', 'balance', 'order_increment_id', 'admin_user', 'comment']
        ));
    }

    /**
     * Gift card table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName('mageos_giftcard');
    }
}
