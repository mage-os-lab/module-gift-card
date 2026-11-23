<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Cart;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Adds a gift card code to the cart, or removes one (parameter "remove" holds the code), then goes back to the
 * cart, or to the checkout the form was posted from (parameter "checkout").
 *
 * Wrong codes are counted per cart: after MAX_FAILURES in FAILURE_WINDOW seconds, codes are refused without
 * being looked up. Per cart and not per IP address: behind a proxy all customers can share one address, and
 * one visitor would lock the others out. Codes are 64 random bits, so the counter is only a second barrier.
 */
class Apply implements HttpPostActionInterface
{
    public const MAX_CARDS = 5;
    public const MAX_FAILURES = 10;
    public const FAILURE_WINDOW = 900;

    /**
     * @param RequestInterface $request
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param GiftCards $giftCards
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Session $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly GiftCards $giftCards,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        $codes = array_filter(explode(',', (string) $quote->getData('giftcard_code')));
        $remove = strtoupper(trim((string) $this->request->getParam('remove')));
        $code = strtoupper(trim((string) $this->request->getParam('giftcard_code')));

        if (!$quote->getId()) {
            $this->messages->addErrorMessage(__('Your cart is empty.'));
        } elseif ($remove !== '') {
            $this->save($quote, array_diff($codes, [$remove]));
            $this->messages->addSuccessMessage(__('The gift card was removed.'));
        } elseif (in_array($code, $codes, true)) {
            $this->messages->addNoticeMessage(__('This gift card is already applied.'));
        } elseif (count($codes) >= self::MAX_CARDS) {
            $this->messages->addErrorMessage(__('You can use up to %1 gift cards per order.', self::MAX_CARDS));
        } elseif ($this->failures((int) $quote->getId()) >= self::MAX_FAILURES) {
            $this->messages->addErrorMessage(__('Too many wrong gift card codes. Try again in a few minutes.'));
        } elseif (!preg_match('/^[0-9A-F]{16}$/', $code) || !$this->giftCards->getBalance($code)) {
            // the format check also keeps out spellings the database would match to a code already applied
            $this->countFailure((int) $quote->getId());
            $this->messages->addErrorMessage(__('This gift card code is not valid or has no balance left.'));
        } else {
            $this->save($quote, [...$codes, $code]);
            $this->messages->addSuccessMessage(__('The gift card was applied.'));
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($item->getProduct()->getData('is_giftcard')) {
                    $this->messages->addNoticeMessage(__('Gift cards in your cart cannot be paid with a gift card.'));
                    break;
                }
            }
        }
        $redirect = $this->redirectFactory->create();
        // the form is also shown in Hyvä Checkout and Loki Checkout: go back there, on the same step
        return $this->request->getParam('checkout')
            ? $redirect->setRefererOrBaseUrl()
            : $redirect->setPath('checkout/cart');
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
     * Wrong codes entered in this cart in the current window.
     *
     * @param int $quoteId
     */
    private function failures(int $quoteId): int
    {
        return (int) $this->cache->load('mageos_giftcard_failures_' . $quoteId);
    }

    /**
     * Count one more wrong code; the window starts again with each one.
     *
     * @param int $quoteId
     */
    private function countFailure(int $quoteId): void
    {
        $this->cache->save(
            (string) ($this->failures($quoteId) + 1),
            'mageos_giftcard_failures_' . $quoteId,
            [],
            self::FAILURE_WINDOW
        );
    }
}
