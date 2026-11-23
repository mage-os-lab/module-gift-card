<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\ResourceModel\Grid;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Admin grid collection of gift cards, with the number of the order they were bought in and their owner's e-mail.
 *
 * It also feeds the grid export. Without the resource GiftCards::ACL_CODES, an admin gets only the end of each
 * code, and can find a card only by its whole code: a "contains" filter would let them rebuild a code letter
 * by letter.
 */
class Collection extends SearchResult
{
    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param AuthorizationInterface $authorization
     * @param string $mainTable
     * @param string|null $resourceModel
     * @param string|null $identifierName
     * @param string|null $connectionName
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly AuthorizationInterface $authorization,
        $mainTable = 'mageos_giftcard',
        $resourceModel = null,
        $identifierName = 'giftcard_id',
        $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    /**
     * Without the resource, the code filter matches a whole code only.
     *
     * @param string|array<int|string,mixed> $field
     * @param mixed $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'code' && is_array($condition) && !$this->authorization->isAllowed(GiftCards::ACL_CODES)) {
            $value = reset($condition);
            $condition = ['eq' => is_scalar($value) ? trim((string) $value, '%') : ''];
        }
        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * Without the resource, keep only the end of each code.
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();
        if (!$this->authorization->isAllowed(GiftCards::ACL_CODES)) {
            foreach ($this->_items as $item) {
                $item->setData('code', '…' . substr((string) $item->getData('code'), -4));
            }
        }
        return $this;
    }

    /**
     * Join the order as "increment_id" and the owner as "customer_email".
     *
     * Columns that also exist in the joined tables are filtered and sorted on the card.
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->joinLeft(
            ['o' => $this->getTable('sales_order')],
            'o.entity_id = main_table.order_id',
            ['increment_id']
        )->joinLeft(
            ['c' => $this->getTable('customer_entity')],
            'c.entity_id = main_table.customer_id',
            ['customer_email' => 'email']
        );
        foreach (['created_at', 'is_active', 'store_id', 'customer_id'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        $this->addFilterToMap('increment_id', 'o.increment_id');
        $this->addFilterToMap('customer_email', 'c.email');
    }
}
