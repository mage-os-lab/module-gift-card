<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use DateTime;
use MageOS\GiftCard\Model\Options;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the checks on the gift card fields posted from the product page.
 */
class OptionsTest extends TestCase
{
    /**
     * A gift shows its recipient, sender and message as cart lines, without HTML.
     */
    public function testGiftLinesAreShownWithoutHtml(): void
    {
        $lines = $this->options()->validateGift(new DataObject([
            Options::RECIPIENT_NAME => '<b>Zoé</b>',
            Options::RECIPIENT_EMAIL => 'zoe@example.com',
            Options::MESSAGE => 'Hi <a href="https://evil.example">there</a>',
        ]), 1);

        $this->assertSame(['Zoé (zoe@example.com)', 'Hi there'], array_column($lines, 'value'));
    }

    /**
     * Gift fields without a valid recipient e-mail, or a send date outside the next year, are refused.
     *
     * @param array<string, string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGifts')]
    public function testInvalidGiftIsRefused(array $fields): void
    {
        $this->expectException(LocalizedException::class);
        $this->options()->validateGift(new DataObject($fields), 1);
    }

    /**
     * Gift fields that must be refused.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidGifts(): array
    {
        return [
            'message without recipient' => [[Options::MESSAGE => 'Hello']],
            'invalid e-mail' => [[Options::RECIPIENT_EMAIL => 'not-an-email']],
            'link in the message' => [[Options::RECIPIENT_EMAIL => 'a@example.com', Options::MESSAGE => 'www.x.io']],
            'link in a name' => [[Options::RECIPIENT_EMAIL => 'a@example.com', Options::SENDER_NAME => 'http://x.io']],
            'quoted e-mail with HTML' => [[Options::RECIPIENT_EMAIL => '"<b>x</b>"@example.com']],
            'date in the past' => [[Options::RECIPIENT_EMAIL => 'a@example.com', Options::SEND_DATE => '2020-01-01']],
            'date too far' => [[Options::RECIPIENT_EMAIL => 'a@example.com', Options::SEND_DATE => '2099-01-01']],
            'impossible date' => [[Options::RECIPIENT_EMAIL => 'a@example.com', Options::SEND_DATE => '2026-02-31']],
        ];
    }

    /**
     * A chosen amount outside the product's range is refused; inside it, it is the price.
     */
    public function testAmountMustBeWithinTheRange(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnMap([
            ['giftcard_amount_min', null, 10.0],
            ['giftcard_amount_max', null, 200.0],
        ]);
        $options = $this->options();

        $this->assertSame([], $options->validate($product, new DataObject([Options::AMOUNT => '42'])));
        $this->assertSame(42.0, $options->getAmount($product, new DataObject([Options::AMOUNT => '42'])));
        $this->expectException(LocalizedException::class);
        $options->validate($product, new DataObject([Options::AMOUNT => '500']));
    }

    /**
     * A free card is refused, even when the product has no minimum.
     */
    public function testZeroAmountIsRefused(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnMap([
            ['giftcard_amount_min', null, null],
            ['giftcard_amount_max', null, 200.0],
        ]);

        $this->expectException(LocalizedException::class);
        $this->options()->validate($product, new DataObject([Options::AMOUNT => '0']));
    }

    /**
     * Options with the store in UTC.
     */
    private function options(): Options
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        $timezone->method('scopeDate')->willReturn(new DateTime('now', new \DateTimeZone('UTC')));
        return new Options($timezone);
    }
}
