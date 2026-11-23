<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Total\Quote as QuoteTotal;
use Magento\Framework\DataObject;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the gift card quote total collector.
 */
class QuoteTotalTest extends TestCase
{
    /**
     * Balance, grand total, total of the gift card items in the cart => grand total left, amount used.
     *
     * @return array<string, array{0: ?float, 1: float, 2: float, 3: float, 4: float, 5?: bool}>
     */
    public static function cases(): array
    {
        return [
            'balance below total' => [30.0, 100.0, 0.0, 70.0, 30.0],
            'balance above total' => [150.0, 100.0, 0.0, 0.0, 100.0],
            'unknown code' => [null, 100.0, 0.0, 100.0, 0.0],
            'gift card in the cart is not paid by a card' => [150.0, 100.0, 40.0, 40.0, 60.0],
            'multi-address checkout never debits, so nothing is deducted' => [150.0, 100.0, 0.0, 100.0, 0.0, true],
        ];
    }

    /**
     * Grand total and gift card amounts for a balance below, above, without a valid code, with a gift card
     * bought in the same cart, or in the multi-address checkout.
     */
    #[DataProvider('cases')]
    public function testCollect(
        ?float $balance,
        float $grandTotal,
        float $giftItems,
        float $expectedTotal,
        float $expectedUsed,
        bool $multiShipping = false
    ): void {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getBalance')->willReturn($balance);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndRound')->willReturnArgument(0);
        $assignment = $this->createStub(ShippingAssignmentInterface::class);
        $items = [new DataObject(['product' => new DataObject(), 'base_row_total_incl_tax' => 60.0])];
        if ($giftItems > 0) {
            $items[] = new DataObject([
                'product' => new DataObject(['is_giftcard' => 1]),
                'base_row_total_incl_tax' => $giftItems + 5.0,
                'base_discount_amount' => 5.0,
            ]);
        }
        $assignment->method('getItems')->willReturn($items);

        $data = ['giftcard_code' => 'ABC'];
        $quote = $this->createStub(Quote::class);
        $quote->method('getData')->willReturnCallback(fn ($key) => $data[$key] ?? null);
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('__call')->willReturnCallback(
            fn (string $method) => $method === 'getIsMultiShipping' ? $multiShipping : null
        );
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$data, $quote) {
            $data[$key] = $value;
            return $quote;
        });
        $total = new Total([], new Json());
        $total->setBaseGrandTotal($grandTotal)->setGrandTotal($grandTotal);

        (new QuoteTotal($giftCards, $priceCurrency))->collect($quote, $assignment, $total);

        $this->assertSame($expectedTotal, (float) $total->getGrandTotal());
        $this->assertSame($expectedTotal, (float) $total->getBaseGrandTotal());
        $this->assertSame($expectedUsed, $data['base_giftcard_amount']);
        $this->assertSame($expectedUsed, $data['giftcard_amount']);
    }
}
