<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Plugin;

use Magento\Catalog\Model\Product\Configuration\Item\Option\OptionInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Copies the gift card lines (amount, recipient, message, send date) from the cart item to the order item,
 * which core does not do for "additional_options", so they show in the order, invoices and e-mails.
 *
 * Also notes on the order line whether it sells a gift card: the cards are issued when the invoice is paid,
 * and the product may be unflagged or deleted by then.
 */
class CopyGiftOptions
{
    /**
     * @param Json $json
     */
    public function __construct(private readonly Json $json)
    {
    }

    /**
     * Add the cart item's "additional_options" to the order item's product options, and note the gift card.
     *
     * @param ToOrderItem $subject
     * @param OrderItem $orderItem
     * @param AbstractItem $item
     * @return OrderItem
     */
    public function afterConvert(ToOrderItem $subject, OrderItem $orderItem, AbstractItem $item)
    {
        // only ever "yes": a cart item whose product came without the attribute must not rule the card out
        if ($item->getProduct()->getData('is_giftcard')) {
            $orderItem->setData('is_giftcard', 1);
        }
        /** @var OptionInterface|null $option null when the item has no such option, whatever core declares */
        $option = $item->getOptionByCode('additional_options');
        $value = $option?->getValue();
        if ($value) {
            $orderItem->setProductOptions(
                ['additional_options' => $this->json->unserialize((string) $value)]
                + (array) $orderItem->getProductOptions()
            );
        }
        return $orderItem;
    }
}
