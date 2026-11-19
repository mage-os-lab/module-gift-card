<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Sets a card's balance from its history page, only if the balance is still the one the page showed.
 */
class Balance extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards_edit';

    /**
     * @param Context $context
     * @param GiftCards $giftCards
     */
    public function __construct(Context $context, private readonly GiftCards $giftCards)
    {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('giftcard_id');
        $balance = $this->getRequest()->getParam('balance');
        $user = $this->_auth->getUser();
        if (!is_numeric($balance) || (float) $balance < 0) {
            $this->messageManager->addErrorMessage(__('The balance must be a number, 0 or more.'));
        } else {
            try {
                $this->giftCards->update(
                    $id,
                    [
                        'balance' => (float) $balance,
                        'expected_balance' => (float) $this->getRequest()->getParam('expected_balance'),
                    ],
                    $user instanceof DataObject ? (string) $user->getData('username') : null
                );
                $this->messageManager->addSuccessMessage(__('The balance was saved.'));
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/history', ['giftcard_id' => $id]);
    }
}
