<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use MageOS\GiftCard\Model\AdminCards;
use MageOS\GiftCard\Model\GiftCards;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Creates the gift card of the "New Gift Card" form; one with a recipient is e-mailed by the cron.
 */
class Create extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards_create';

    /**
     * @param Context $context
     * @param GiftCards $giftCards
     * @param AdminCards $adminCards
     */
    public function __construct(
        Context $context,
        private readonly GiftCards $giftCards,
        private readonly AdminCards $adminCards
    ) {
        parent::__construct($context);
    }

    /**
     * Back to the form on invalid input, else to the grid.
     */
    public function execute()
    {
        $request = $this->getRequest();
        $redirect = $this->resultRedirectFactory->create();
        $fields = [];
        foreach (AdminCards::COLUMNS as $column) {
            $fields[$column] = $request->getParam($column);
        }
        try {
            [$balance, $card] = $this->adminCards->build($fields, (int) $request->getParam('store_id'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/new');
        }
        $user = $this->_auth->getUser();
        $code = $this->giftCards->create(
            $balance,
            $card,
            null,
            $user instanceof DataObject ? (string) $user->getData('username') : null
        );
        $this->messageManager->addSuccessMessage(__(
            'Gift card %1 was created.',
            $this->_authorization->isAllowed(GiftCards::ACL_CODES) ? $code : $this->giftCards->mask($code)
        ));
        return $redirect->setPath('*/*/');
    }
}
