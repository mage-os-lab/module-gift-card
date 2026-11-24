<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\DataObject;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Creates one gift card per unit of a paid invoice line that sold a gift card (balance = what the unit was
 * paid: price incl. tax less its share of the discount, base currency) and e-mails the codes to the buyer.
 * A card with a recipient is e-mailed to them by the cron (MageOS\GiftCard\Cron\SendGifts) on its send date.
 *
 * Called when the invoice is paid (Observer\Issue), and again by the cron for the cards that step left out
 * (Cron\IssueMissing).
 */
class Issuer
{
    /**
     * @param GiftCards $giftCards
     * @param TransportBuilder $transportBuilder
     * @param LoggerInterface $logger
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param Options $options
     * @param Emulation $emulation
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly TransportBuilder $transportBuilder,
        private readonly LoggerInterface $logger,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly Options $options,
        private readonly Emulation $emulation
    ) {
    }

    /**
     * Create the cards of a paid invoice and e-mail the buyer.
     *
     * The order comment records the issued cards, codes shortened: the full codes are in the gift card grid.
     * The buyer gets the full code of the cards they keep, and only the end of the ones e-mailed to a recipient.
     * The e-mail's lines are written in the order's store view: the invoice is often paid from the admin, in
     * the admin's language.
     *
     * @param DataObject $invoice the paid invoice (Order\Invoice), as the event carries it
     * @param array<int,int>|null $missing cards still to issue per order line ID, counted down here; without
     *     it, the units invoiced on the line are the only cap
     * @return int cards issued
     */
    public function issue($invoice, ?array &$missing = null): int
    {
        $order = $invoice->getOrder();
        $issued = [];
        $comments = [];
        foreach ($invoice->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ($orderItem->getParentItemId() || !$this->isGiftCard($orderItem)) {
                continue;
            }
            $id = (int) $orderItem->getId();
            $gift = (array) $orderItem->getProductOptionByCode('info_buyRequest');
            $card = $this->card($order, $gift) + ['order_item_id' => $id];
            // never more cards than units invoiced on the line, should an invoice be paid twice
            $qty = min(
                (int) $item->getQty(),
                (int) $orderItem->getQtyInvoiced() - $this->giftCards->countByOrderItem($id)
            );
            if ($missing !== null) {
                $qty = min($qty, $missing[$id] ?? 0);
            }
            if ($qty <= 0) {
                continue;
            }
            // per unit of the invoice line: fewer cards than units may be left to issue
            $balance = round(
                ((float) $item->getBaseRowTotalInclTax() - (float) $item->getBaseDiscountAmount())
                / (float) $item->getQty(),
                2
            );
            if ($balance <= 0) {
                continue;
            }
            if ($missing !== null) {
                $missing[$id] -= $qty;
            }
            $amount = $order->getBaseCurrency()->formatTxt($balance);
            for ($i = 0; $i < $qty; $i++) {
                $code = $this->giftCards->create($balance, $card, (string) $order->getIncrementId());
                $comments[] = $this->giftCards->mask($code) . ' — ' . $amount;
                $issued[] = [
                    'code' => $code,
                    'balance' => $balance,
                    'recipient' => $card['recipient_email'] ?? null,
                    'date' => (string) ($gift[Options::SEND_DATE] ?? ''),
                ];
            }
        }
        if (!$issued) {
            return 0;
        }
        $this->historyRepository->save(
            $order->addCommentToStatusHistory((string) __('Gift cards issued: %1', implode(', ', $comments)))
        );
        $this->emulation->startEnvironmentEmulation((int) $order->getStoreId(), Area::AREA_FRONTEND, true);
        try {
            $this->transportBuilder
                ->setTemplateIdentifier('mageos_giftcard_issued')
                ->setTemplateOptions(['area' => 'frontend', 'store' => $order->getStoreId()])
                ->setTemplateVars([
                    'codes' => implode("\n", $this->lines($order, $issued)),
                    'order_id' => $order->getIncrementId(),
                ])
                ->setFromByScope('sales', $order->getStoreId())
                ->addTo($order->getCustomerEmail())
                ->getTransport()
                ->sendMessage();
        } catch (Exception $e) {
            $this->logger->error('Gift card e-mail failed for order ' . $order->getIncrementId(), ['exception' => $e]);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        return count($issued);
    }

    /**
     * Whether the order line sold a gift card.
     *
     * The line remembers it (Plugin\CopyGiftOptions, orders after 2.0.0): the product may be unflagged or deleted
     * before the invoice is paid. A line that does not say so asks the product, as all lines did before.
     *
     * @param Item $orderItem
     */
    private function isGiftCard($orderItem): bool
    {
        return $orderItem->getData('is_giftcard') || $orderItem->getProduct()?->getData('is_giftcard');
    }

    /**
     * Lines of the buyer's e-mail, one per card; call it inside the emulation of the order's store view.
     *
     * @param Order $order
     * @param array<int,array<string,mixed>> $issued code, balance, recipient (e-mail or null) and send date
     * @return string[]
     */
    private function lines(Order $order, array $issued): array
    {
        $lines = [];
        foreach ($issued as $card) {
            $amount = $order->getBaseCurrency()->formatTxt((float) $card['balance']);
            $lines[] = $card['recipient'] === null
                ? $card['code'] . ' — ' . $amount
                : $this->giftCards->mask((string) $card['code']) . ' — ' . $amount . ' — ' . __(
                    'for %1, e-mailed on %2',
                    $card['recipient'],
                    $card['date'] ?: __('the invoice date')
                );
        }
        return $lines;
    }

    /**
     * Columns of the cards of one order line.
     *
     * A card kept by a logged-in buyer goes to their account; a gift goes to the recipient's account,
     * found by GiftCards::create.
     *
     * @param Order $order
     * @param array<string,mixed> $gift buy request of the order line
     * @return array<string, mixed>
     */
    private function card(Order $order, array $gift): array
    {
        $card = ['order_id' => (int) $order->getId(), 'store_id' => (int) $order->getStoreId()];
        $email = trim((string) ($gift[Options::RECIPIENT_EMAIL] ?? ''));
        if ($email === '') {
            return $card + ['customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null];
        }
        $sender = $this->options->text($gift[Options::SENDER_NAME] ?? '');
        return $card + [
            'recipient_email' => $email,
            'recipient_name' => $this->options->text($gift[Options::RECIPIENT_NAME] ?? '') ?: null,
            'sender_name' => $sender ?: trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()),
            'message' => $this->options->text($gift[Options::MESSAGE] ?? '') ?: null,
            'send_at' => $this->options->toUtc(
                trim((string) ($gift[Options::SEND_DATE] ?? '')),
                (int) $order->getStoreId()
            ),
        ];
    }
}
