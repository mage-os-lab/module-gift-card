<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use MageOS\GiftCard\Model\Options;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Price of a gift card with a customer-chosen amount: the amount of its buy request.
 */
class ApplyAmount implements ObserverInterface
{
    /**
     * @param Options $options
     * @param Json $json
     */
    public function __construct(private readonly Options $options, private readonly Json $json)
    {
    }

    /**
     * Runs on every price calculation; only quote items carry a buy request, so catalog prices are untouched.
     *
     * Shortcut: the amount is in base currency; convert it if stores display another currency.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getData('product');
        $option = $product->getCustomOption('info_buyRequest');
        if (!$option || !$product->getData('is_giftcard') || !$this->options->hasCustomAmount($product)) {
            return;
        }
        $buyRequest = new DataObject((array) $this->json->unserialize((string) $option->getValue()));
        $product->setFinalPrice($this->options->getAmount($product, $buyRequest));
    }
}
