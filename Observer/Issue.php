<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Observer;

use Exception;
use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Options;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * When an invoice is paid, creates one gift card per purchased unit of a product flagged "Gift Card"
 * (balance = what the unit was paid: price incl. tax less its share of the discount, base currency)
 * and e-mails the codes to the buyer.
 * A card with a recipient is e-mailed to them by the cron (MageOS\GiftCard\Cron\SendGifts) on its send date.
 *
 * "sales_order_invoice_pay" fires before the invoice is saved, so it only marks the invoice; the codes are created
 * and sent on "sales_order_invoice_save_commit_after", once the paid invoice is really in the database.
 */
class Issue implements ObserverInterface
{
    private const PENDING = 'mageos_giftcard_pending';

    /**
     * @param GiftCards $giftCards
     * @param TransportBuilder $transportBuilder
     * @param LoggerInterface $logger
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param Options $options
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly TransportBuilder $transportBuilder,
        private readonly LoggerInterface $logger,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly Options $options
    ) {
    }

    /**
     * Create the cards of a paid invoice and e-mail the buyer.
     *
     * The order comment records the issued cards, codes shortened: the full codes are in the gift card grid.
     * The buyer gets the full code of the cards they keep, and only the end of the ones e-mailed to a recipient.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $invoice = $observer->getData('invoice');
        if ($observer->getEvent()->getName() === 'sales_order_invoice_pay') {
            $invoice->setData(self::PENDING, true);
            return;
        }
        if (!$invoice->getData(self::PENDING)) {
            return;
        }
        $invoice->unsetData(self::PENDING);
        $order = $invoice->getOrder();
        $codes = [];
        $comments = [];
        foreach ($invoice->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ($orderItem->getParentItemId() || !$orderItem->getProduct()?->getData('is_giftcard')) {
                continue;
            }
            $gift = (array) $orderItem->getProductOptionByCode('info_buyRequest');
            $card = $this->card($order, $gift) + ['order_item_id' => (int) $orderItem->getId()];
            $qty = (int) $item->getQty();
            if ($qty <= 0) {
                continue;
            }
            $balance = round(
                ((float) $item->getBaseRowTotalInclTax() - (float) $item->getBaseDiscountAmount()) / $qty,
                2
            );
            $amount = $order->getBaseCurrency()->formatTxt($balance);
            for ($i = 0; $i < $qty; $i++) {
                $code = $this->giftCards->create($balance, $card, (string) $order->getIncrementId());
                $comments[] = $this->giftCards->mask($code) . ' — ' . $amount;
                $line = (isset($card['recipient_email']) ? $this->giftCards->mask($code) : $code) . ' — ' . $amount;
                if (isset($card['recipient_email'])) {
                    $line .= ' — ' . __(
                        'for %1, e-mailed on %2',
                        $card['recipient_email'],
                        ($gift[Options::SEND_DATE] ?? '') ?: __('the invoice date')
                    );
                }
                $codes[] = $line;
            }
        }
        if (!$codes) {
            return;
        }
        $this->historyRepository->save(
            $order->addCommentToStatusHistory((string) __('Gift cards issued: %1', implode(', ', $comments)))
        );
        try {
            $this->transportBuilder
                ->setTemplateIdentifier('mageos_giftcard_issued')
                ->setTemplateOptions(['area' => 'frontend', 'store' => $order->getStoreId()])
                ->setTemplateVars(['codes' => implode("\n", $codes), 'order_id' => $order->getIncrementId()])
                ->setFromByScope('sales', $order->getStoreId())
                ->addTo($order->getCustomerEmail())
                ->getTransport()
                ->sendMessage();
        } catch (Exception $e) {
            $this->logger->error('Gift card e-mail failed for order ' . $order->getIncrementId(), ['exception' => $e]);
        }
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
