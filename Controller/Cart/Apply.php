<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Cart;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * Applies a gift card code to the cart, or removes it (parameter "remove").
 *
 * shortcut: one gift card per cart; store a list of codes if customers need to combine cards.
 */
class Apply implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param GiftCards $giftCards
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Session $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly GiftCards $giftCards,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        $code = $this->request->getParam('remove')
            ? ''
            : strtoupper(trim((string) $this->request->getParam('giftcard_code')));

        if (!$quote->getId()) {
            $this->messages->addErrorMessage(__('Your cart is empty.'));
        } elseif ($code !== '' && !$this->giftCards->getBalance($code)) {
            $this->messages->addErrorMessage(__('This gift card code is not valid or has no balance left.'));
        } else {
            $quote->setData('giftcard_code', $code ?: null);
            $this->quoteRepository->save($quote->collectTotals());
            $this->messages->addSuccessMessage(
                $code ? __('The gift card was applied.') : __('The gift card was removed.')
            );
        }
        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
