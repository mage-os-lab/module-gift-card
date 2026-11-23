<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Balance history of one gift card (giftcard_id).
 */
class History extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards';

    /**
     * @param Context $context
     * @param GiftCards $giftCards
     */
    public function __construct(Context $context, private readonly GiftCards $giftCards)
    {
        parent::__construct($context);
    }

    /**
     * Show the history, or go back to the grid when the card does not exist.
     */
    public function execute()
    {
        $card = $this->giftCards->get((int) $this->getRequest()->getParam('giftcard_id'));
        if (!$card) {
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        if ($page instanceof Page) {
            $page->setActiveMenu('MageOS_GiftCard::cards');
            $page->getConfig()->getTitle()->prepend((string) __(
                'Gift Card %1',
                $this->_authorization->isAllowed(GiftCards::ACL_CODES)
                    ? $card['code']
                    : $this->giftCards->mask((string) $card['code'])
            ));
        }
        return $page;
    }
}
