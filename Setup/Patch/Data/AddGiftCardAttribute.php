<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "Gift Card" product flag.
 */
class AddGiftCardAttribute implements DataPatchInterface
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
     * Create the attribute in the General group.
     */
    public function apply(): self
    {
        $this->eavSetupFactory->create(['setup' => $this->setup])->addAttribute(Product::ENTITY, 'is_giftcard', [
            'type' => 'int',
            'input' => 'boolean',
            'source' => Boolean::class,
            'label' => 'Gift Card',
            'note' => 'Each unit sold creates a gift card worth the product price, e-mailed to the buyer.',
            'required' => false,
            'default' => 0,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'group' => 'General',
        ]);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
