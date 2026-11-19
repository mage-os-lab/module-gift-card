<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\Options;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * When a gift card is added to the cart, checks its amount and gift fields and shows them with the cart item.
 *
 * Different recipients or amounts make different cart lines, because the item options differ.
 */
class PrepareOptions implements ObserverInterface
{
    /**
     * @param Options $options
     * @param Json $json
     */
    public function __construct(private readonly Options $options, private readonly Json $json)
    {
    }

    /**
     * "additional_options" is copied by core to the order item and shown in the cart, order and e-mails.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getData('product');
        if (!$product->getData('is_giftcard')) {
            return;
        }
        $lines = $this->options->validate($product, $observer->getData('buy_request'));
        $amount = $this->options->getAmount($product, $observer->getData('buy_request'));
        if ($amount !== null) {
            array_unshift($lines, [
                'label' => (string) __('Amount'),
                'value' => $product->getStore()->getBaseCurrency()->formatTxt($amount),
            ]);
        }
        if ($lines) {
            $product->addCustomOption('additional_options', $this->json->serialize($lines));
        }
    }
}
