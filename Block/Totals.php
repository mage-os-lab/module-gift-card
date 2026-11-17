<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Block;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Sales\Block\Order\Totals as TotalsBlock;

/**
 * Adds the gift card line to order / invoice / credit memo totals (storefront, admin, e-mails).
 */
class Totals extends AbstractBlock
{
    /**
     * Add the gift card line before the grand total when the document has one.
     */
    public function initTotals(): self
    {
        $parent = $this->getParentBlock();
        if (!$parent instanceof TotalsBlock) {
            return $this;
        }
        $source = $parent->getSource();
        if ((float) $source->getData('giftcard_amount') > 0) {
            $parent->addTotalBefore(new DataObject([
                'code' => 'giftcard',
                'label' => __('Gift Card'),
                'value' => -$source->getData('giftcard_amount'),
                'base_value' => -$source->getData('base_giftcard_amount'),
            ]), 'grand_total');
        }
        return $this;
    }
}
