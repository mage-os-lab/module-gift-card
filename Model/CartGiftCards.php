<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;

/**
 * The gift card codes of a cart: the rules shared by the cart form, the REST API and GraphQL.
 *
 * Wrong codes are counted per cart: after MAX_FAILURES in FAILURE_WINDOW seconds, codes are refused without
 * being looked up. Per cart and not per IP address: behind a proxy all customers can share one address, and
 * one visitor would lock the others out. Codes are 64 random bits, so the counter is only a second barrier.
 */
class CartGiftCards
{
    public const MAX_CARDS = 5;
    public const MAX_FAILURES = 10;
    public const FAILURE_WINDOW = 900;

    /**
     * @param GiftCards $giftCards
     * @param CartRepositoryInterface $quoteRepository
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteId
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteId,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * The active cart behind a masked ID, for the one it belongs to.
     *
     * A cart of a customer account is only given to that customer, never to someone who merely holds its
     * masked ID: the answer lists the codes applied to it.
     *
     * @param string $maskedId
     * @param int $customerId 0 for a guest
     * @throws NoSuchEntityException when there is no such cart, or it belongs to someone else
     */
    public function getQuote(string $maskedId, int $customerId): Quote
    {
        $quote = $this->quoteRepository->getActive($this->maskedQuoteId->execute($maskedId));
        if (!$quote instanceof Quote || (int) $quote->getData('customer_id') !== $customerId) {
            throw NoSuchEntityException::singleField('cartId', $maskedId);
        }
        return $quote;
    }

    /**
     * Add a gift card code to the cart.
     *
     * @param Quote $quote
     * @param string $code
     * @throws AlreadyExistsException when the code is already applied: nothing to report as an error
     * @throws LocalizedException when the code is refused, with the reason for the customer
     */
    public function apply(Quote $quote, string $code): void
    {
        $code = strtoupper(trim($code));
        $codes = $this->codes($quote);
        $quoteId = (int) $quote->getId();
        if (in_array($code, $codes, true)) {
            throw new AlreadyExistsException(__('This gift card is already applied.'));
        }
        if (count($codes) >= self::MAX_CARDS) {
            throw new LocalizedException(__('You can use up to %1 gift cards per order.', self::MAX_CARDS));
        }
        if ($this->failures($quoteId) >= self::MAX_FAILURES) {
            throw new LocalizedException(__('Too many wrong gift card codes. Try again in a few minutes.'));
        }
        // the format check also keeps out spellings the database would match to a code already applied
        if (!preg_match('/^[0-9A-F]{16}$/', $code) || !$this->giftCards->getBalance($code)) {
            $this->cache->save(
                (string) ($this->failures($quoteId) + 1),
                'mageos_giftcard_failures_' . $quoteId,
                [],
                self::FAILURE_WINDOW
            );
            throw new LocalizedException(__('This gift card code is not valid or has no balance left.'));
        }
        $this->save($quote, [...$codes, $code]);
    }

    /**
     * Take a gift card code off the cart; a code that is not applied changes nothing.
     *
     * @param Quote $quote
     * @param string $code
     */
    public function remove(Quote $quote, string $code): void
    {
        $this->save($quote, array_diff($this->codes($quote), [strtoupper(trim($code))]));
    }

    /**
     * The cards applied to the cart, in the order they pay, with what each pays of it.
     *
     * Amounts in base currency. A code whose card can no longer be spent stays listed, paying nothing, so
     * that it can be removed.
     *
     * @param Quote $quote
     * @return array<int, array{code: string, balance: float, applied: float, status: string, expires_at: ?string}>
     */
    public function getApplied(Quote $quote): array
    {
        $left = (float) $quote->getData('base_giftcard_amount');
        $cards = [];
        foreach ($this->codes($quote) as $code) {
            $card = $this->giftCards->getByCode($code);
            $applied = round(min((float) $this->giftCards->getBalance($code), $left), 4);
            $left -= $applied;
            $cards[] = [
                'code' => $code,
                'balance' => (float) ($card['balance'] ?? 0),
                'applied' => $applied,
                'status' => $card ? $this->giftCards->status($card) : 'disabled',
                'expires_at' => isset($card['expires_at']) ? (string) $card['expires_at'] : null,
            ];
        }
        return $cards;
    }

    /**
     * Codes applied to the cart, in the order applied.
     *
     * @param Quote $quote
     * @return string[]
     */
    private function codes(Quote $quote): array
    {
        return array_values(array_filter(explode(',', (string) $quote->getData('giftcard_code'))));
    }

    /**
     * Store the codes on the quote and recollect its totals.
     *
     * @param Quote $quote
     * @param string[] $codes
     */
    private function save(Quote $quote, array $codes): void
    {
        $quote->setData('giftcard_code', $codes ? implode(',', $codes) : null);
        $this->quoteRepository->save($quote->collectTotals());
    }

    /**
     * Wrong codes entered in this cart in the current window; the window starts again with each one.
     *
     * @param int $quoteId
     */
    private function failures(int $quoteId): int
    {
        return (int) $this->cache->load('mageos_giftcard_failures_' . $quoteId);
    }
}
