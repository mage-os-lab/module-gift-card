<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use DateTimeImmutable;
use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Options;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves the status and expiry edited in the gift cards grid. The balance is changed on the card's history
 * page, against the balance shown there: the grid posts every column of the row, so it could not tell an
 * unchanged balance from a stale one.
 */
class InlineEdit extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards_edit';

    /**
     * @param Context $context
     * @param GiftCards $giftCards
     * @param JsonFactory $jsonFactory
     * @param Options $options
     */
    public function __construct(
        Context $context,
        private readonly GiftCards $giftCards,
        private readonly JsonFactory $jsonFactory,
        private readonly Options $options
    ) {
        parent::__construct($context);
    }

    /**
     * Rows with an invalid balance are skipped and reported; the others are saved.
     */
    public function execute()
    {
        $user = $this->_auth->getUser();
        $userName = $user instanceof DataObject ? (string) $user->getData('username') : null;
        $messages = [];
        foreach ((array) $this->getRequest()->getParam('items', []) as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $data = [];
            if (isset($row['is_active'])) {
                $data['is_active'] = (bool) $row['is_active'];
            }
            if (array_key_exists('expires_at', $row)) {
                $date = $this->date((string) $row['expires_at']);
                if ($date === false) {
                    $messages[] = (string) __('Gift card %1: the expiry date is not valid.', (int) $id);
                    continue;
                }
                if (!$this->sameDay($date, $this->giftCards->get((int) $id)['expires_at'] ?? null)) {
                    $data['expires_at'] = $date;
                }
            }
            $this->giftCards->update((int) $id, $data, $userName);
        }
        return $this->jsonFactory->create()->setData(['messages' => $messages, 'error' => (bool) $messages]);
    }

    /**
     * The grid's date editor posts "MM/dd/y" whatever the admin locale; empty removes the expiry.
     *
     * @param string $value
     * @return string|null|false UTC end of that day, null for no expiry, false when not a date
     */
    private function date(string $value): string|null|false
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!m/d/Y', $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        try {
            return $date ? $this->options->toUtc($date->format('Y-m-d'), 0, true) : false;
        } catch (LocalizedException) {
            return false;
        }
    }

    /**
     * Whether a posted expiry (end of a store-local day, UTC) falls on the same day as the current one.
     *
     * The grid posts every column of the row: an expiry left as is must keep its time, not move to the end
     * of the day and add a line to the history.
     *
     * @param string|null $posted
     * @param string|null $current UTC
     */
    private function sameDay(?string $posted, ?string $current): bool
    {
        if ($posted === null || $current === null) {
            return $posted === $current;
        }
        $dayStart = gmdate('Y-m-d H:i:s', (int) strtotime($posted . ' UTC') - 86399);
        return $current >= $dayStart && $current <= $posted;
    }
}
