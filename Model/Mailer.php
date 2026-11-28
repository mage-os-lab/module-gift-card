<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Exception;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * E-mails a gift card's code to its recipient (or another address), or its expiry reminder to its holder,
 * and records it in the card's history.
 */
class Mailer
{
    /**
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param TimezoneInterface $timezone
     * @param GiftCards $giftCards
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $config
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param ProductResource $productResource
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly GiftCards $giftCards,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $config,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly ProductResource $productResource
    ) {
    }

    /**
     * E-mail the card's code and record it in the history.
     *
     * Mail failures are logged and counted on the card, not thrown, so that one bad address does not stop
     * the others. The expiry is written in the language and time zone of the card's store view.
     * Only an e-mail to the card's recipient counts as the gift being sent, or as a failed attempt at it.
     * A card bought in an order shows the image of the gift card product.
     *
     * @param array<string,mixed> $card row of mageos_giftcard
     * @param string|null $email default: the card's recipient
     * @param string|null $adminUser who sent it from the admin
     * @return bool whether the e-mail was sent
     */
    public function send(array $card, ?string $email = null, ?string $adminUser = null): bool
    {
        $email = $email ?: (string) $card['recipient_email'];
        $gift = strcasecmp($email, (string) $card['recipient_email']) === 0;
        try {
            $store = $this->storeManager->getStore((int) $card['store_id'] ?: null);
            [$image, $productName] = $this->image($card, $store);
            $this->mail('mageos_giftcard_gift', $store, $email, (string) $card['recipient_name'], [
                'code' => (string) $card['code'],
                'amount' => $this->amount($card, $store),
                'sender_name' => (string) $card['sender_name'],
                'recipient_name' => (string) $card['recipient_name'],
                'message' => (string) $card['message'],
                'expires' => $this->expires($card, $store),
                'image' => $image,
                'product_name' => $productName,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Gift card e-mail failed for card ' . $card['giftcard_id'], ['exception' => $e]);
            if ($gift) {
                $this->giftCards->markFailed((int) $card['giftcard_id']);
            }
            return false;
        }
        $this->giftCards->markSent((int) $card['giftcard_id'], $email, $adminUser, $gift);
        return true;
    }

    /**
     * E-mail the holder that the card expires soon, with the end of its code, and record it in the history.
     *
     * A failure is logged, not thrown.
     *
     * @param array<string,mixed> $card row of mageos_giftcard
     * @param string $email the holder
     * @return bool whether the e-mail was sent
     */
    public function remind(array $card, string $email): bool
    {
        try {
            $store = $this->storeManager->getStore((int) $card['store_id'] ?: null);
            $name = $card['recipient_email'] ? (string) $card['recipient_name'] : '';
            $this->mail('mageos_giftcard_expiry', $store, $email, $name, [
                'code' => $this->giftCards->mask((string) $card['code']),
                'amount' => $this->amount($card, $store),
                'expires' => $this->expires($card, $store),
                'recipient_name' => $name,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Gift card expiry reminder failed for card ' . $card['giftcard_id'], [
                'exception' => $e,
            ]);
            return false;
        }
        $this->giftCards->markReminded((int) $card['giftcard_id']);
        return true;
    }

    /**
     * Send one of the module's e-mails from the store's sales identity.
     *
     * @param string $template
     * @param StoreInterface $store
     * @param string $email
     * @param string $name
     * @param array<string,string> $vars
     */
    private function mail(string $template, StoreInterface $store, string $email, string $name, array $vars): void
    {
        $this->transportBuilder
            ->setTemplateIdentifier($template)
            ->setTemplateOptions(['area' => 'frontend', 'store' => (int) $store->getId()])
            ->setTemplateVars($vars)
            ->setFromByScope('sales', (int) $store->getId())
            ->addTo($email, $name)
            ->getTransport()
            ->sendMessage();
    }

    /**
     * Balance of the card, in the base currency of the store.
     *
     * @param array<string,mixed> $card
     * @param StoreInterface $store
     */
    private function amount(array $card, StoreInterface $store): string
    {
        return $store instanceof Store
            ? $store->getBaseCurrency()->formatTxt((float) $card['balance'])
            : (string) $card['balance'];
    }

    /**
     * Expiry date in the language and time zone of the store, empty without one.
     *
     * @param array<string,mixed> $card
     * @param StoreInterface $store
     */
    private function expires(array $card, StoreInterface $store): string
    {
        if (!$card['expires_at']) {
            return '';
        }
        return $this->timezone->formatDateTime(
            new \DateTime((string) $card['expires_at'], new \DateTimeZone('UTC')),
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            (string) $this->config->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $store->getId()),
            $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string) $store->getId())
        );
    }

    /**
     * URL and name of the image of the product the card was bought as; empty strings without one.
     *
     * The original image from the media folder, not a resized copy: resized copies are made by the theme of
     * the store front, which a cron run does not load. Any error leaves the e-mail without the image.
     *
     * @param array<string,mixed> $card
     * @param StoreInterface $store
     * @return array{string, string}
     */
    private function image(array $card, StoreInterface $store): array
    {
        if (empty($card['order_item_id']) || !$store instanceof Store) {
            return ['', ''];
        }
        try {
            $productId = (int) $this->orderItemRepository->get((int) $card['order_item_id'])->getProductId();
            $values = $productId ? $this->productResource->getAttributeRawValue(
                $productId,
                ['small_image', 'image', 'name'],
                (int) $store->getId()
            ) : [];
        } catch (Exception $e) {
            $this->logger->warning('Gift card e-mail without image for card ' . $card['giftcard_id'], [
                'exception' => $e,
            ]);
            return ['', ''];
        }
        $values = is_array($values) ? $values : [];
        foreach (['small_image', 'image'] as $attribute) {
            $file = (string) ($values[$attribute] ?? '');
            if ($file !== '' && $file !== 'no_selection') {
                return [
                    $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product/' . ltrim($file, '/'),
                    (string) ($values['name'] ?? ''),
                ];
            }
        }
        return ['', ''];
    }
}
