<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Source;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Actions of the gift card history.
 */
class Action implements OptionSourceInterface
{
    /**
     * @inheritdoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        return [
            ['value' => GiftCards::ISSUED, 'label' => __('Issued')],
            ['value' => GiftCards::USED, 'label' => __('Used in an order')],
            ['value' => GiftCards::REFUNDED, 'label' => __('Refunded')],
            ['value' => GiftCards::CANCELED, 'label' => __('Order canceled')],
            ['value' => GiftCards::ADJUSTED, 'label' => __('Balance changed')],
            ['value' => GiftCards::EXPIRY, 'label' => __('Expiry changed')],
            ['value' => GiftCards::SENT, 'label' => __('Code e-mailed')],
            ['value' => GiftCards::ENABLED, 'label' => __('Enabled')],
            ['value' => GiftCards::DISABLED, 'label' => __('Disabled')],
        ];
    }
}
