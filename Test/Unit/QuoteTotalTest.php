<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Total\Quote as QuoteTotal;
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
     * Balance, grand total => grand total left, amount used.
     *
     * @return array<string, array{?float, float, float, float}>
     */
    public static function cases(): array
    {
        return [
            'balance below total' => [30.0, 100.0, 70.0, 30.0],
            'balance above total' => [150.0, 100.0, 0.0, 100.0],
            'unknown code' => [null, 100.0, 100.0, 0.0],
        ];
    }

    /**
     * Grand total and gift card amounts for a balance below, above, or without a valid code.
     */
    #[DataProvider('cases')]
    public function testCollect(?float $balance, float $grandTotal, float $expectedTotal, float $expectedUsed): void
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getBalance')->willReturn($balance);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndRound')->willReturnArgument(0);
        $assignment = $this->createStub(ShippingAssignmentInterface::class);
        $assignment->method('getItems')->willReturn(['item']);

        $data = ['giftcard_code' => 'ABC'];
        $quote = $this->createStub(Quote::class);
        $quote->method('getData')->willReturnCallback(fn ($key) => $data[$key] ?? null);
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
