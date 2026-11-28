<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Observer\AddPaymentCartLine;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\Cart;
use Magento\Payment\Model\Cart\SalesModel\Factory;
use Magento\Payment\Model\Cart\SalesModel\SalesModelInterface;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the gift card line of the cart sent to PayPal, whose total must be what the customer pays.
 */
class PaypalLineTest extends TestCase
{
    /**
     * A cart of 100 with 30 paid by gift card is sent as 100 of items and a "Gift Card" line of -30: 70.
     */
    public function testGiftCardIsSentAsANegativeLine(): void
    {
        $cart = $this->cart(30.0);

        $lines = array_map(
            fn (DataObject $item): array => [(string) $item->getData('name'), $item->getData('amount')],
            $cart->getAllItems()
        );

        $this->assertSame([['Gift card 100', 100.0], ['Gift Card', -30.0]], $lines);
        $this->assertSame(70.0, $cart->getSubtotal());
    }

    /**
     * Without a gift card, the cart is sent as it is.
     */
    public function testNoLineWithoutGiftCard(): void
    {
        $cart = $this->cart(null);

        $this->assertCount(1, $cart->getAllItems());
        $this->assertSame(100.0, $cart->getSubtotal());
    }

    /**
     * Payment cart of one item of 100, in base currency, whose collect event runs the observer.
     *
     * @param float|null $giftCardAmount base_giftcard_amount of the quote or order
     * @return Cart
     */
    private function cart(?float $giftCardAmount): Cart
    {
        $item = new DataObject([
            'name' => 'Gift card 100',
            'qty' => 1,
            'price' => 100.0,
            'original_item' => new DataObject(['id' => 1]),
        ]);
        $salesModel = $this->createStub(SalesModelInterface::class);
        $salesModel->method('getAllItems')->willReturn([$item]);
        $salesModel->method('getBaseSubtotal')->willReturn(100.0);
        $salesModel->method('getDataUsingMethod')->willReturnMap([['base_giftcard_amount', null, $giftCardAmount]]);
        $factory = $this->createStub(Factory::class);
        $factory->method('create')->willReturn($salesModel);
        $events = $this->createStub(ManagerInterface::class);
        $observer = new AddPaymentCartLine();
        $events->method('dispatch')->willReturnCallback(
            function (string $name, array $data) use ($observer): void {
                if ($name === 'payment_cart_collect_items_and_amounts') {
                    $observer->execute(new Observer($data));
                }
            }
        );
        return new Cart($factory, $events, $this->createStub(CartInterface::class));
    }
}
