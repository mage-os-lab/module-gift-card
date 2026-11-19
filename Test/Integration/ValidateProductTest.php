<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as SimpleFixture;
use Magento\Catalog\Test\Fixture\Virtual as VirtualFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableFixture;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests that only virtual products, or configurables with virtual variants only, can be gift cards.
 *
 * The attribute fixture gets "extension_attributes" null: its default empty array makes core build the wrong
 * extension class for the attribute interceptor on Mage-OS 4 / PHP 8.5 (TypeError in setExtensionAttributes).
 */
#[DbIsolation(true)]
class ValidateProductTest extends TestCase
{
    /**
     * A configurable whose variants are all virtual can be flagged.
     */
    #[
        DataFixture(ConfigurableAttributeFixture::class, ['extension_attributes' => null], 'attr'),
        DataFixture(VirtualFixture::class, as: 'v1'),
        DataFixture(VirtualFixture::class, as: 'v2'),
        DataFixture(ConfigurableFixture::class, ['_options' => ['$attr$'], '_links' => ['$v1$', '$v2$']], 'conf'),
    ]
    public function testConfigurableWithVirtualVariantsCanBeAGiftCard(): void
    {
        $this->repository()->save($this->load('conf')->setData('is_giftcard', 1));

        $this->assertSame('1', (string) $this->load('conf')->getData('is_giftcard'));
    }

    /**
     * One variant that needs shipping is enough to refuse the flag.
     */
    #[
        DataFixture(ConfigurableAttributeFixture::class, ['extension_attributes' => null], 'attr'),
        DataFixture(VirtualFixture::class, as: 'v1'),
        DataFixture(SimpleFixture::class, as: 's1'),
        DataFixture(ConfigurableFixture::class, ['_options' => ['$attr$'], '_links' => ['$v1$', '$s1$']], 'conf'),
    ]
    public function testConfigurableWithASimpleVariantCannotBeAGiftCard(): void
    {
        $this->expectException(LocalizedException::class);

        $this->repository()->save($this->load('conf')->setData('is_giftcard', 1));
    }

    /**
     * Giving a weight to a virtual gift card turns it into a simple product, which is refused.
     */
    #[DataFixture(VirtualFixture::class, ['is_giftcard' => 1], 'card')]
    public function testVirtualGiftCardCannotBecomeSimple(): void
    {
        $this->expectException(LocalizedException::class);

        $this->repository()->save($this->load('card')->setTypeId('simple')->setWeight(1));
    }

    /**
     * A variant of a gift card cannot become a simple product either.
     */
    #[
        DataFixture(ConfigurableAttributeFixture::class, ['extension_attributes' => null], 'attr'),
        DataFixture(VirtualFixture::class, as: 'v1'),
        DataFixture(
            ConfigurableFixture::class,
            ['_options' => ['$attr$'], '_links' => ['$v1$'], 'is_giftcard' => 1],
            'conf'
        ),
    ]
    public function testVariantOfAGiftCardMustStayVirtual(): void
    {
        $this->expectException(LocalizedException::class);

        $this->repository()->save($this->load('v1')->setTypeId('simple')->setWeight(1));
    }

    /**
     * The fixture product, reloaded for editing at the default scope.
     *
     * @param string $fixture
     */
    private function load(string $fixture): ProductInterface
    {
        $sku = DataFixtureStorageManager::getStorage()->get($fixture)->getSku();
        return $this->repository()->get($sku, true, 0, true);
    }

    /**
     * Product repository.
     */
    private function repository(): ProductRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
    }
}
