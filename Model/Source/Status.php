<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Gift card statuses: a disabled card cannot be used in the cart.
 */
class Status implements OptionSourceInterface
{
    /**
     * @inheritdoc
     *
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        return [['value' => 1, 'label' => __('Active')], ['value' => 0, 'label' => __('Disabled')]];
    }
}
