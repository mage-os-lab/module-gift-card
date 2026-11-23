<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Admin grid collection of gift cards, with the number of the order they were bought in and their owner's e-mail.
 */
class Collection extends SearchResult
{
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
