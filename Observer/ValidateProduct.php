<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Relation;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refuses to save a gift card that could need shipping: only virtual products, or configurables whose variants
 * are all virtual, can be gift cards, and a variant of a gift card cannot stop being virtual. A customer-chosen
 * amount needs a minimum above 0, so that no card is free.
 */
class ValidateProduct implements ObserverInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param Relation $relation
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Relation $relation
    ) {
    }

    /**
     * For other types Magento drops the flag before saving (apply_to) but keeps the stored value, so the original
     * value tells a gift card being turned into a simple product. Variants come from the links being saved (admin
     * wizard, REST), else from the database.
     *
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getData('product');
        $id = (int) $product->getId();
        $type = $product->getTypeId();

        if ($product->getData('is_giftcard') ?? $product->getOrigData('is_giftcard')) {
            if ($type === Configurable::TYPE_CODE) {
                /** @var ProductExtensionInterface $extension */
                $extension = $product->getExtensionAttributes();
                $childIds = $extension->getConfigurableProductLinks()
                    ?? ($id ? $this->relation->getRelationsByParent([$id])[$id] ?? [] : []);
                if ($childIds && $this->collectionFactory->create()->addIdFilter($childIds)
                    ->addFieldToFilter('type_id', ['neq' => Type::TYPE_VIRTUAL])->getSize()) {
                    throw new LocalizedException(
                        __('All variants of a gift card must be virtual products (no weight).')
                    );
                }
            } elseif ($type !== Type::TYPE_VIRTUAL) {
                throw new LocalizedException(
                    __('Only a virtual product, or a configurable product with virtual variants, can be a gift card.')
                );
            }
            $min = (float) $product->getData('giftcard_amount_min');
            $max = (float) $product->getData('giftcard_amount_max');
            if ($max > 0 && ($min <= 0 || $min > $max)) {
                throw new LocalizedException(
                    __('Set a gift card minimum amount above 0 and not above the maximum amount.')
                );
            }
            return;
        }

        if ($id && $type !== Type::TYPE_VIRTUAL) {
            $parentIds = $this->relation->getRelationsByChildren([$id])[$id] ?? [];
            if ($parentIds && $this->collectionFactory->create()->addIdFilter($parentIds)
                ->addAttributeToFilter('is_giftcard', ['eq' => 1])->getSize()) {
                throw new LocalizedException(
                    __('This product is a variant of a gift card: it must stay a virtual product (no weight).')
                );
            }
        }
    }
}
