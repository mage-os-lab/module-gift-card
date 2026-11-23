<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Ui\Component\Listing\Column\Balance;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class BalanceColumnTest extends TestCase
{
    public function testFormatsKnownAmountsOnly(): void
    {
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(fn (float $amount) => '$' . number_format($amount, 2));
        $column = new Balance(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $priceCurrency,
            [],
            ['name' => 'initial_balance']
        );

        $items = $column->prepareDataSource(['data' => ['items' => [
            ['initial_balance' => '25.0000'],
            ['initial_balance' => null],
        ]]])['data']['items'];

        self::assertSame('$25.00', $items[0]['initial_balance']);
        self::assertNull($items[1]['initial_balance']);
    }
}
