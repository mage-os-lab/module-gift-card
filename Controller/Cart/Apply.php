<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Cart;

use MageOS\GiftCard\Model\CartGiftCards;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;

/**
 * Adds a gift card code to the cart, or removes one (parameter "remove" holds the code), then goes back to the
 * cart, or to the checkout the form was posted from (parameter "checkout").
 *
 * The rules (how many cards, wrong codes counted per cart) are in Model\CartGiftCards, shared with the APIs.
 */
class Apply implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param Session $checkoutSession
     * @param CartGiftCards $cartGiftCards
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Session $checkoutSession,
        private readonly CartGiftCards $cartGiftCards,
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
        $remove = trim((string) $this->request->getParam('remove'));
        try {
            if (!$quote->getId()) {
                $this->messages->addErrorMessage(__('Your cart is empty.'));
            } elseif ($remove !== '') {
                $this->cartGiftCards->remove($quote, $remove);
                $this->messages->addSuccessMessage(__('The gift card was removed.'));
            } else {
                $this->cartGiftCards->apply($quote, (string) $this->request->getParam('giftcard_code'));
                $this->messages->addSuccessMessage(__('The gift card was applied.'));
                foreach ($quote->getAllVisibleItems() as $item) {
                    if ($item->getProduct()->getData('is_giftcard')) {
                        $this->messages->addNoticeMessage(
                            __('Gift cards in your cart cannot be paid with a gift card.')
                        );
                        break;
                    }
                }
            }
        } catch (AlreadyExistsException $e) {
            $this->messages->addNoticeMessage($e->getMessage());
        } catch (LocalizedException $e) {
            $this->messages->addErrorMessage($e->getMessage());
        }
        $redirect = $this->redirectFactory->create();
        // the form is also shown in Hyvä Checkout and Loki Checkout: go back there, on the same step
        return $this->request->getParam('checkout')
            ? $redirect->setRefererOrBaseUrl()
            : $redirect->setPath('checkout/cart');
    }
}
