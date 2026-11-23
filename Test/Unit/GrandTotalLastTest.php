<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Plugin\GrandTotalLast;
use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Model\Cart\TotalsConverter;
use PHPUnit\Framework\TestCase;

class GrandTotalLastTest extends TestCase
{
    /**
     * @param string[] $codes
     * @return string[]
     */
    private function order(array $codes): array
    {
        $segments = array_fill_keys($codes, $this->createStub(TotalSegmentInterface::class));
        $plugin = new GrandTotalLast();
        return array_keys($plugin->afterProcess($this->createStub(TotalsConverter::class), $segments));
    }

    public function testGiftCardIsListedBeforeTheGrandTotal(): void
    {
        self::assertSame(
            ['subtotal', 'tax', 'giftcard', 'grand_total'],
            $this->order(['subtotal', 'tax', 'grand_total', 'giftcard'])
        );
    }

    public function testOrderIsKeptWithoutGiftCard(): void
    {
        self::assertSame(['subtotal', 'grand_total', 'fee'], $this->order(['subtotal', 'grand_total', 'fee']));
    }
}
