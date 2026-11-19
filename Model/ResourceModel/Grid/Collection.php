<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Admin grid collection of gift cards, with the number of the order they were bought in.
 */
class Collection extends SearchResult
{
    /**
     * Join the order as "increment_id"; columns that also exist in sales_order are filtered on the card.
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->joinLeft(
            ['o' => $this->getTable('sales_order')],
            'o.entity_id = main_table.order_id',
            ['increment_id']
        );
        $this->addFilterToMap('created_at', 'main_table.created_at');
        $this->addFilterToMap('increment_id', 'o.increment_id');
    }
}
