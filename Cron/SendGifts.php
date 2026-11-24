<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Cron;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Mailer;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * E-mails the gift cards whose send date has come to their recipients; a failed e-mail is tried again next run.
 *
 * One run at a time, and GiftCards::SEND_BATCH cards per run: a second run started while a long one is still
 * sending (cron run by hand, a slow mail server) would e-mail the same cards twice.
 */
class SendGifts
{
    private const LOCK = 'mageos_giftcard_send_gifts';

    /**
     * @param GiftCards $giftCards
     * @param Mailer $mailer
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly Mailer $mailer,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Send the due cards, unless another run is at it.
     */
    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK, 0)) {
            return;
        }
        try {
            foreach ($this->giftCards->getDue() as $card) {
                $this->mailer->send($card);
            }
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }
}
