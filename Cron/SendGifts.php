<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Cron;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Mailer;

/**
 * E-mails the gift cards whose send date has come to their recipients; a failed e-mail is tried again next run.
 */
class SendGifts
{
    /**
     * @param GiftCards $giftCards
     * @param Mailer $mailer
     */
    public function __construct(private readonly GiftCards $giftCards, private readonly Mailer $mailer)
    {
    }

    /**
     * Send every due card.
     */
    public function execute(): void
    {
        foreach ($this->giftCards->getDue() as $card) {
            $this->mailer->send($card);
        }
    }
}
