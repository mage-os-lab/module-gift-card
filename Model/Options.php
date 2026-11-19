<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The gift card fields of the product page (amount, recipient, message, send date), as sent in the buy request.
 */
class Options
{
    public const AMOUNT = 'giftcard_amount';
    public const RECIPIENT_NAME = 'giftcard_recipient_name';
    public const RECIPIENT_EMAIL = 'giftcard_recipient_email';
    public const SENDER_NAME = 'giftcard_sender_name';
    public const MESSAGE = 'giftcard_message';
    public const SEND_DATE = 'giftcard_send_date';

    public const MESSAGE_LENGTH = 500;
    public const NAME_LENGTH = 100;

    /**
     * @param TimezoneInterface $timezone
     */
    public function __construct(private readonly TimezoneInterface $timezone)
    {
    }

    /**
     * Whether the customer chooses the amount of this product.
     *
     * @param Product $product
     */
    public function hasCustomAmount(Product $product): bool
    {
        return (float) $product->getData('giftcard_amount_max') > 0;
    }

    /**
     * Check the fields posted with the product and return the lines shown with the cart item.
     *
     * @param Product $product
     * @param DataObject $buyRequest
     * @return array<int, array{label: string, value: string}>
     * @throws LocalizedException when a field is missing or invalid
     */
    public function validate(Product $product, DataObject $buyRequest): array
    {
        if ($this->hasCustomAmount($product)) {
            $amount = $buyRequest->getData(self::AMOUNT);
            $min = (float) $product->getData('giftcard_amount_min');
            $max = (float) $product->getData('giftcard_amount_max');
            if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount < $min || (float) $amount > $max) {
                throw new LocalizedException(__('Choose a gift card amount between %1 and %2.', $min, $max));
            }
        }
        return $this->validateGift($buyRequest, (int) $product->getStoreId());
    }

    /**
     * Check the recipient fields (shared with the admin's new card form) and return them as lines.
     *
     * @param DataObject $data
     * @param int $storeId
     * @return array<int, array{label: string, value: string}>
     * @throws LocalizedException when a field is invalid
     */
    public function validateGift(DataObject $data, int $storeId): array
    {
        $lines = [];
        $email = trim((string) $data->getData(self::RECIPIENT_EMAIL));
        $name = $this->text($data->getData(self::RECIPIENT_NAME));
        $sender = $this->text($data->getData(self::SENDER_NAME));
        $message = $this->text($data->getData(self::MESSAGE));
        $date = trim((string) $data->getData(self::SEND_DATE));
        if ($email === '') {
            if ($name !== '' || $message !== '' || $date !== '') {
                throw new LocalizedException(__('Enter the e-mail address of the gift card recipient.'));
            }
            return $lines;
        }
        if (!$this->isEmail($email)) {
            throw new LocalizedException(__('The gift card recipient e-mail address is not valid.'));
        }
        if (mb_strlen($name) > self::NAME_LENGTH || mb_strlen($sender) > self::NAME_LENGTH) {
            throw new LocalizedException(__('Names are limited to %1 characters.', self::NAME_LENGTH));
        }
        if (mb_strlen($message) > self::MESSAGE_LENGTH) {
            throw new LocalizedException(__('The gift message is limited to %1 characters.', self::MESSAGE_LENGTH));
        }
        $lines[] = [
            'label' => (string) __('Gift Card For'),
            'value' => $name === '' ? $email : $name . ' (' . $email . ')',
        ];
        if ($sender !== '') {
            $lines[] = ['label' => (string) __('From'), 'value' => $sender];
        }
        if ($message !== '') {
            $lines[] = ['label' => (string) __('Message'), 'value' => $message];
        }
        if ($date !== '') {
            $this->checkDate($date, $storeId);
            $lines[] = ['label' => (string) __('Send On'), 'value' => $date];
        }
        return $lines;
    }

    /**
     * Whether this is a plain e-mail address.
     *
     * Quoted local parts like "<b>x</b>"@example.com, valid for PHP, are refused because the address is shown
     * in order comments, which allow some HTML.
     *
     * @param string $email
     */
    public function isEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/^[^\s"<>()\[\]\\\\,;:@]+@[^\s"<>()\[\]\\\\,;:@]+$/', $email) === 1;
    }

    /**
     * Customer text without HTML: the cart and order pages render item options with some tags allowed.
     *
     * @param mixed $value
     */
    public function text(mixed $value): string
    {
        return trim(strip_tags((string) $value));
    }

    /**
     * Amount chosen by the customer, within the product's range; null when the price is the amount.
     *
     * @param Product $product
     * @param DataObject $buyRequest
     */
    public function getAmount(Product $product, DataObject $buyRequest): ?float
    {
        if (!$this->hasCustomAmount($product)) {
            return null;
        }
        return min(
            max((float) $buyRequest->getData(self::AMOUNT), (float) $product->getData('giftcard_amount_min')),
            (float) $product->getData('giftcard_amount_max')
        );
    }

    /**
     * Start (or last second) of a store-local "Y-m-d" date, in UTC; now for an empty date.
     *
     * @param string $date
     * @param int $storeId
     * @param bool $endOfDay for expiry dates, so the card still works on the date shown
     */
    public function toUtc(string $date, int $storeId, bool $endOfDay = false): string
    {
        if ($date === '') {
            return gmdate('Y-m-d H:i:s');
        }
        $zone = new DateTimeZone(
            (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string) $storeId)
        );
        $local = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$local || $local->format('Y-m-d') !== $date) {
            throw new LocalizedException(__('Enter the date as YYYY-MM-DD.'));
        }
        if ($endOfDay) {
            $local = $local->setTime(23, 59, 59);
        }
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The send date must be a real date, today or within a year.
     *
     * @param string $date
     * @param int $storeId
     * @throws LocalizedException
     */
    private function checkDate(string $date, int $storeId): void
    {
        try {
            $sendAt = new DateTimeImmutable($this->toUtc($date, $storeId), new DateTimeZone('UTC'));
            $today = new DateTimeImmutable($this->toUtc($this->today($storeId), $storeId), new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new LocalizedException(__('Enter the date as YYYY-MM-DD.'));
        }
        if ($sendAt < $today || $sendAt > $today->modify('+1 year')) {
            throw new LocalizedException(__('Choose a send date between today and one year from now.'));
        }
    }

    /**
     * Today in the store's time zone, as "Y-m-d".
     *
     * @param int $storeId
     */
    public function today(int $storeId): string
    {
        return $this->timezone->scopeDate($storeId)->format('Y-m-d');
    }
}
