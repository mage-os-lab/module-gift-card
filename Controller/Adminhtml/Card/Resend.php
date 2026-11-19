<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Mailer;
use MageOS\GiftCard\Model\Options;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\DataObject;

/**
 * E-mails a card's code again, to the address typed on the history page.
 */
class Resend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards_resend';

    /**
     * @param Context $context
     * @param GiftCards $giftCards
     * @param Mailer $mailer
     * @param Options $options
     */
    public function __construct(
        Context $context,
        private readonly GiftCards $giftCards,
        private readonly Mailer $mailer,
        private readonly Options $options
    ) {
        parent::__construct($context);
    }

    /**
     * Back to the card's history, which records the e-mail.
     */
    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('giftcard_id');
        $email = trim((string) $this->getRequest()->getParam('email'));
        $card = $this->giftCards->get($id);
        $user = $this->_auth->getUser();
        if (!$card) {
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        if (!$this->options->isEmail($email)) {
            $this->messageManager->addErrorMessage(__('Enter a valid e-mail address.'));
        } elseif ($this->mailer->send(
            $card,
            $email,
            $user instanceof DataObject ? (string) $user->getData('username') : null
        )) {
            $this->messageManager->addSuccessMessage(__('The code was sent to %1.', $email));
        } else {
            $this->messageManager->addErrorMessage(__('The e-mail could not be sent. See var/log/system.log.'));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/history', ['giftcard_id' => $id]);
    }
}
