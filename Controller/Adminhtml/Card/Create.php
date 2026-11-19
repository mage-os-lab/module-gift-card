<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Options;
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
     * @param Options $options
     */
    public function __construct(
        Context $context,
        private readonly GiftCards $giftCards,
        private readonly Options $options
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
        $balance = $request->getParam('balance');
        $storeId = (int) $request->getParam('store_id');
        $gift = new DataObject([
            Options::RECIPIENT_NAME => $request->getParam('recipient_name'),
            Options::RECIPIENT_EMAIL => $request->getParam('recipient_email'),
            Options::SENDER_NAME => $request->getParam('sender_name'),
            Options::MESSAGE => $request->getParam('message'),
            Options::SEND_DATE => $request->getParam('send_date'),
        ]);
        try {
            if (!is_numeric($balance) || (float) $balance <= 0) {
                throw new LocalizedException(__('Enter a balance greater than 0.'));
            }
            $this->options->validateGift($gift, $storeId);
            $card = ['store_id' => $storeId];
            $expires = trim((string) $request->getParam('expires_date'));
            if ($expires !== '') {
                $card['expires_at'] = $this->options->toUtc($expires, $storeId, true);
            }
            $email = trim((string) $gift->getData(Options::RECIPIENT_EMAIL));
            if ($email !== '') {
                $card += [
                    'recipient_email' => $email,
                    'recipient_name' => $this->options->text($gift->getData(Options::RECIPIENT_NAME)) ?: null,
                    'sender_name' => $this->options->text($gift->getData(Options::SENDER_NAME)) ?: null,
                    'message' => $this->options->text($gift->getData(Options::MESSAGE)) ?: null,
                    'send_at' => $this->options->toUtc(trim((string) $gift->getData(Options::SEND_DATE)), $storeId),
                ];
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/new');
        }
        $user = $this->_auth->getUser();
        $code = $this->giftCards->create(
            (float) $balance,
            $card,
            null,
            $user instanceof DataObject ? (string) $user->getData('username') : null
        );
        $this->messageManager->addSuccessMessage(__('Gift card %1 was created.', $code));
        return $redirect->setPath('*/*/');
    }
}
