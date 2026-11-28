<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Cron;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Mailer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * E-mails the holders of cards that expire within the configured number of days, once per card.
 *
 * Each card is claimed before its e-mail is sent, so that two runs never send it twice; a failed e-mail is
 * logged by the mailer and released, so that the next run tries again.
 */
class RemindExpiry
{
    private const LOCK = 'mageos_giftcard_remind_expiry';

    /**
     * @param GiftCards $giftCards
     * @param Mailer $mailer
     * @param LockManagerInterface $lockManager
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly Mailer $mailer,
        private readonly LockManagerInterface $lockManager,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Send the due reminders, unless reminders are off or another run is at it.
     */
    public function execute(): void
    {
        $days = (int) $this->config->getValue(GiftCards::XML_REMINDER_DAYS);
        if ($days <= 0 || !$this->lockManager->lock(self::LOCK, 0)) {
            return;
        }
        try {
            foreach ($this->giftCards->getExpiring($days) as $card) {
                $id = (int) $card['giftcard_id'];
                if (!$this->giftCards->claimReminder($id)) {
                    continue;
                }
                $sent = false;
                try {
                    $sent = $this->mailer->remind($card, (string) $card['reminder_email']);
                } finally {
                    if (!$sent) {
                        $this->giftCards->releaseReminder($id);
                    }
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }
}
