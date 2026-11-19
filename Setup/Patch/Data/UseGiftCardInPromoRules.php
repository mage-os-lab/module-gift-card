<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Offers the "Gift Card" flag in cart price rule conditions, so that gift cards can be kept out of discounts.
 */
class UseGiftCardInPromoRules implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $setup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    /**
     * The note follows the card value, which is now what the unit was paid.
     */
    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
        $eavSetup->updateAttribute(Product::ENTITY, 'is_giftcard', 'is_used_for_promo_rules', 1);
        $eavSetup->updateAttribute(
            Product::ENTITY,
            'is_giftcard',
            'note',
            'Virtual products, or configurable products with virtual variants (one per amount). '
            . 'Each unit sold creates a gift card worth what it was paid, e-mailed to the buyer.'
        );
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [RestrictGiftCardAttribute::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
