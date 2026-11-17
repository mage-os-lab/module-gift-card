<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Shows the "Gift Card" flag only on virtual and configurable products, and clears it on the other types.
 */
class RestrictGiftCardAttribute implements DataPatchInterface
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
     * A separate patch, because AddGiftCardAttribute has already run on existing stores.
     *
     * Magento keeps the stored value of an attribute that no longer applies, so the old flags are deleted.
     */
    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
        $eavSetup->updateAttribute(Product::ENTITY, 'is_giftcard', 'apply_to', 'virtual,configurable');
        $eavSetup->updateAttribute(
            Product::ENTITY,
            'is_giftcard',
            'note',
            'Virtual products, or configurable products with virtual variants (one per amount). '
            . 'Each unit sold creates a gift card worth the product price, e-mailed to the buyer.'
        );
        $connection = $this->setup->getConnection();
        $connection->delete($this->setup->getTable('catalog_product_entity_int'), [
            'attribute_id = ?' => (int) $eavSetup->getAttributeId(Product::ENTITY, 'is_giftcard'),
            'entity_id IN (?)' => $connection->select()
                ->from($this->setup->getTable('catalog_product_entity'), 'entity_id')
                ->where('type_id NOT IN (?)', ['virtual', 'configurable']),
        ]);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [AddGiftCardAttribute::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
