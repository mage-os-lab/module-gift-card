<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Backend\Price;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Minimum and maximum of the amount a customer may choose for a virtual gift card.
 */
class AddCustomAmountAttributes implements DataPatchInterface
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
     * Both in the General group, on virtual products only.
     */
    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
        $attributes = [
            'giftcard_amount_min' => [
                'Gift Card Minimum Amount',
                'With a maximum: the customer chooses the amount between the two, in base currency. '
                . 'Set the price to the minimum.',
            ],
            'giftcard_amount_max' => ['Gift Card Maximum Amount', 'Empty: the price is the amount.'],
        ];
        foreach ($attributes as $code => [$label, $note]) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type' => 'decimal',
                'input' => 'price',
                'backend' => Price::class,
                'label' => $label,
                'note' => $note,
                'required' => false,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'apply_to' => 'virtual',
                'group' => 'General',
            ]);
        }
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
