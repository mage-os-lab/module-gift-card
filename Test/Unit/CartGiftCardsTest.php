<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\CartGiftCards;
use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Tests the rules on the gift card codes of a cart, shared by the cart form, REST and GraphQL.
 */
class CartGiftCardsTest extends TestCase
{
    private const VALID = 'AAAAAAAAAAAAAAA';

    /**
     * Codes looked up on the fake gift card store.
     *
     * @var string[]
     */
    private array $lookedUp = [];

    /**
     * Wrong codes counted per cache key.
     *
     * @var array<string, string>
     */
    private array $failures = [];

    /**
     * A valid code is added after the ones already applied; applying it again is not an error to count.
     */
    public function testValidCodeIsAddedOnce(): void
    {
        $quote = $this->quote(self::VALID . '1');
        $service = $this->service($quote);

        $service->apply($quote, ' ' . strtolower(self::VALID) . '2 ');
        $this->assertSame(self::VALID . '1,' . self::VALID . '2', $quote->getData('giftcard_code'));

        $this->expectException(AlreadyExistsException::class);
        $service->apply($quote, self::VALID . '2');
    }

    /**
     * After MAX_FAILURES wrong codes the cart refuses codes without looking them up, valid ones included.
     */
    public function testTooManyWrongCodesBlockTheCart(): void
    {
        $quote = $this->quote();
        $service = $this->service($quote);
        for ($i = 0; $i < CartGiftCards::MAX_FAILURES; $i++) {
            try {
                $service->apply($quote, 'BBBBBBBBBBBBBBBB');
                $this->fail('A wrong code should be refused.');
            } catch (LocalizedException $e) {
                $this->assertNull($quote->getData('giftcard_code'));
            }
        }
        $this->lookedUp = [];

        try {
            $service->apply($quote, self::VALID . '1');
            $this->fail('The cart should be blocked.');
        } catch (LocalizedException $e) {
            $this->assertSame([], $this->lookedUp);
        }
    }

    /**
     * No more than MAX_CARDS cards per cart.
     */
    public function testSixthCardIsRefused(): void
    {
        $codes = array_map(fn (int $i) => self::VALID . $i, range(1, CartGiftCards::MAX_CARDS));
        $quote = $this->quote(implode(',', $codes));

        $this->expectException(LocalizedException::class);
        $this->service($quote)->apply($quote, self::VALID . '9');
    }

    /**
     * A customer's cart is not given to a guest, or to another customer, who holds its masked ID.
     */
    public function testCartOfACustomerIsOnlyGivenToThatCustomer(): void
    {
        $quote = $this->quote();
        $quote->setData('customer_id', 5);
        $service = $this->service($quote);

        $this->assertSame($quote, $service->getQuote('mask', 5));
        foreach ([0, 6] as $other) {
            try {
                $service->getQuote('mask', $other);
                $this->fail('The cart belongs to customer 5.');
            } catch (NoSuchEntityException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Each applied card shows what it pays of the cart, in the order applied.
     */
    public function testAppliedCardsShareTheAmountInOrder(): void
    {
        $quote = $this->quote(self::VALID . '1,' . self::VALID . '2');
        $quote->setData('base_giftcard_amount', 30.0);

        $applied = $this->service($quote)->getApplied($quote);

        $this->assertSame([20.0, 10.0], array_column($applied, 'applied'));
    }

    /**
     * Service on a fake gift card store where codes starting with "A" hold 20.
     *
     * @param Quote $quote the cart behind any masked ID
     * @return CartGiftCards
     */
    private function service(Quote $quote): CartGiftCards
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getBalance')->willReturnCallback(function (string $code): ?float {
            $this->lookedUp[] = $code;
            return $code[0] === 'A' ? 20.0 : null;
        });
        $giftCards->method('status')->willReturn('active');
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->failures[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $value, string $key): bool {
            $this->failures[$key] = $value;
            return true;
        });
        $repository = $this->createStub(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($quote);
        return new CartGiftCards(
            $giftCards,
            $repository,
            $this->createStub(MaskedQuoteIdToQuoteIdInterface::class),
            $cache
        );
    }

    /**
     * Cart 7 that keeps its data like a real one.
     *
     * @param string|null $codes applied codes
     * @return Quote&Stub
     */
    private function quote(?string $codes = null): Quote&Stub
    {
        $data = ['giftcard_code' => $codes];
        $quote = $this->createStub(Quote::class);
        $quote->method('getId')->willReturn(7);
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getData')->willReturnCallback(function (string $key) use (&$data) {
            return $data[$key] ?? null;
        });
        $quote->method('setData')->willReturnCallback(function (string $key, $value) use (&$data, $quote) {
            $data[$key] = $value;
            return $quote;
        });
        return $quote;
    }
}
