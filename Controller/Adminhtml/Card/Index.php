<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Admin grid of all gift cards.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards';

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        if ($page instanceof Page) {
            $page->setActiveMenu('MageOS_GiftCard::cards');
            $page->getConfig()->getTitle()->prepend((string) __('Gift Cards'));
        }
        return $page;
    }
}
