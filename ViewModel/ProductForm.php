<?php
declare(strict_types=1);

namespace MageOS\GiftCard\ViewModel;

use MageOS\GiftCard\Model\Options;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Gift card fields of the product page.
 */
class ProductForm implements ArgumentInterface
{
    /**
     * @param Options $options
     */
    public function __construct(private readonly Options $options)
    {
    }

    /**
     * Whether the product sells gift cards.
     *
     * @param Product $product
     */
    public function isGiftCard(Product $product): bool
    {
        return (bool) $product->getData('is_giftcard');
    }

    /**
     * Range of the amount the customer chooses, or null when the price is the amount.
     *
     * @param Product $product
     * @return array{float, float}|null
     */
    public function getAmountRange(Product $product): ?array
    {
        return $this->options->hasCustomAmount($product)
            ? [(float) $product->getData('giftcard_amount_min'), (float) $product->getData('giftcard_amount_max')]
            : null;
    }

    /**
     * Latest send date, "Y-m-d" in the store's time zone.
     *
     * No earliest date in the page: a cached page would offer yesterday; the server checks it.
     *
     * @param Product $product
     */
    public function getLastSendDate(Product $product): string
    {
        $today = $this->options->today((int) $product->getStoreId());
        return date('Y-m-d', (int) strtotime($today . ' +1 year'));
    }
}
