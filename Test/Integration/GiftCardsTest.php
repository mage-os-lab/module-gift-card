<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Integration;

use MageOS\GiftCard\Model\GiftCards;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests the gift card balances against the database.
 */
#[DbIsolation(true)]
class GiftCardsTest extends TestCase
{
    /**
     * A debit larger than the balance changes nothing; the others move the balance.
     */
    public function testDebitNeverOverdrawsTheCard(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(50.0);

        $this->assertFalse($giftCards->debit($code, 60.0));
        $this->assertSame(50.0, $giftCards->getBalance($code));
        $this->assertTrue($giftCards->debit($code, 20.0));
        $giftCards->credit($code, 5.0);
        $this->assertSame(35.0, $giftCards->getBalance($code));
    }

    /**
     * An unknown code has no balance and cannot be debited.
     */
    public function testUnknownCodeHasNoBalance(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);

        $this->assertNull($giftCards->getBalance('NO-SUCH-CODE'));
        $this->assertFalse($giftCards->debit('NO-SUCH-CODE', 1.0));
    }
}
