<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Mailer;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests what the gift and expiry e-mails show.
 */
class MailerTest extends TestCase
{
    /**
     * Variables given to the last e-mail template.
     *
     * @var array<string, mixed>
     */
    private array $vars = [];

    /**
     * Template of the last e-mail.
     *
     * @var string
     */
    private string $template = '';

    /**
     * A card bought in an order shows the image of its product, from the store's media URL.
     */
    public function testGiftEmailShowsTheProductImage(): void
    {
        $sent = $this->mailer(['small_image' => 'no_selection', 'image' => '/g/c/gift.jpg', 'name' => 'Gift'])
            ->send($this->card(['order_item_id' => 3]));

        $this->assertTrue($sent);
        $this->assertSame('https://shop.example/media/catalog/product/g/c/gift.jpg', $this->vars['image']);
        $this->assertSame('Gift', $this->vars['product_name']);
    }

    /**
     * A card created in the admin, or whose product has no image, is e-mailed without one.
     *
     * @param array<string, mixed> $card
     * @param array<string, string> $product
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noImage')]
    public function testGiftEmailWithoutImage(array $card, array $product): void
    {
        $this->assertTrue($this->mailer($product)->send($this->card($card)));
        $this->assertSame('', $this->vars['image']);
    }

    /**
     * Cards e-mailed without an image.
     *
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function noImage(): array
    {
        return [
            'admin card' => [['order_item_id' => null], ['image' => '/g/c/gift.jpg']],
            'product without image' => [['order_item_id' => 3], ['image' => 'no_selection']],
        ];
    }

    /**
     * The expiry reminder shows only the end of the code, the balance and the date.
     */
    public function testExpiryReminderShowsTheEndOfTheCode(): void
    {
        $this->assertTrue($this->mailer([])->remind($this->card([]), 'zoe@example.com'));

        $this->assertSame('mageos_giftcard_expiry', $this->template);
        $this->assertSame('…CDEF', $this->vars['code']);
        $this->assertSame('$20.00', $this->vars['amount']);
        $this->assertSame('December 1, 2026', $this->vars['expires']);
    }

    /**
     * Card row with these columns.
     *
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    private function card(array $columns): array
    {
        return $columns + [
            'giftcard_id' => 1,
            'code' => '0123456789ABCDEF',
            'balance' => '20.0000',
            'store_id' => 1,
            'recipient_email' => 'zoe@example.com',
            'recipient_name' => 'Zoé',
            'sender_name' => 'Max',
            'message' => '',
            'expires_at' => '2026-12-01 23:59:59',
            'order_item_id' => null,
        ];
    }

    /**
     * Mailer on fakes, whose product has these raw attribute values.
     *
     * @param array<string, string> $product
     * @return Mailer
     */
    private function mailer(array $product): Mailer
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('formatTxt')->willReturnCallback(fn ($amount): string => sprintf('$%.2F', $amount));
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseCurrency')->willReturn($currency);
        $store->method('getBaseUrl')->willReturn('https://shop.example/media/');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $transport = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $transport->method($method)->willReturnSelf();
        }
        $transport->method('setTemplateIdentifier')->willReturnCallback(function (string $id) use ($transport) {
            $this->template = $id;
            return $transport;
        });
        $transport->method('setTemplateVars')->willReturnCallback(function (array $vars) use ($transport) {
            $this->vars = $vars;
            return $transport;
        });
        $transport->method('getTransport')->willReturn($this->createStub(TransportInterface::class));
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturn('December 1, 2026');
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('mask')->willReturnCallback(fn (string $code): string => '…' . substr($code, -4));
        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getProductId')->willReturn(9);
        $items = $this->createStub(OrderItemRepositoryInterface::class);
        $items->method('get')->willReturn($item);
        $products = $this->createStub(ProductResource::class);
        $products->method('getAttributeRawValue')->willReturn($product);
        return new Mailer(
            $transport,
            $stores,
            $timezone,
            $giftCards,
            $this->createStub(LoggerInterface::class),
            $this->createStub(ScopeConfigInterface::class),
            $items,
            $products
        );
    }
}
