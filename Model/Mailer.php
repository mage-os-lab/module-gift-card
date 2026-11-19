<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * E-mails a gift card's code to its recipient (or another address), and records it in the card's history.
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
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly GiftCards $giftCards,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * E-mail the card's code and record it in the history.
     *
     * Mail failures are logged and counted on the card, not thrown, so that one bad address does not stop
     * the others. The expiry is written in the language and time zone of the card's store view.
     *
     * @param array<string,mixed> $card row of mageos_giftcard
     * @param string|null $email default: the card's recipient
     * @param string|null $adminUser who sent it from the admin
     * @return bool whether the e-mail was sent
     */
    public function send(array $card, ?string $email = null, ?string $adminUser = null): bool
    {
        $email = $email ?: (string) $card['recipient_email'];
        try {
            $store = $this->storeManager->getStore((int) $card['store_id'] ?: null);
            $expires = $card['expires_at']
                ? $this->timezone->formatDateTime(
                    new \DateTime((string) $card['expires_at'], new \DateTimeZone('UTC')),
                    \IntlDateFormatter::LONG,
                    \IntlDateFormatter::NONE,
                    (string) $this->config->getValue(
                        'general/locale/code',
                        ScopeInterface::SCOPE_STORE,
                        $store->getId()
                    ),
                    $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string) $store->getId())
                )
                : '';
            $this->transportBuilder
                ->setTemplateIdentifier('mageos_giftcard_gift')
                ->setTemplateOptions(['area' => 'frontend', 'store' => (int) $store->getId()])
                ->setTemplateVars([
                    'code' => (string) $card['code'],
                    'amount' => $store instanceof Store
                        ? $store->getBaseCurrency()->formatTxt((float) $card['balance'])
                        : (string) $card['balance'],
                    'sender_name' => (string) $card['sender_name'],
                    'recipient_name' => (string) $card['recipient_name'],
                    'message' => (string) $card['message'],
                    'expires' => $expires,
                ])
                ->setFromByScope('sales', (int) $store->getId())
                ->addTo($email, (string) $card['recipient_name'])
                ->getTransport()
                ->sendMessage();
        } catch (Exception $e) {
            $this->logger->error('Gift card e-mail failed for card ' . $card['giftcard_id'], ['exception' => $e]);
            $this->giftCards->markFailed((int) $card['giftcard_id']);
            return false;
        }
        $this->giftCards->markSent((int) $card['giftcard_id'], $email, $adminUser);
        return true;
    }
}
