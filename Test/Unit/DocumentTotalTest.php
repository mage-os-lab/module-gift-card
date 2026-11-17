<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\Total\Document;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;

/**
 * Tests how the order's gift card amount is spread over invoices and credit memos.
 */
class DocumentTotalTest extends TestCase
{
    /**
     * Other documents of the order, as returned by its invoice / credit memo collection.
     *
     * @var array<int, Invoice|Creditmemo>
     */
    private array $siblings = [];

    /**
     * The first invoice uses the gift card up to its own grand total.
     */
    public function testFirstInvoiceUsesTheCardUpToItsTotal(): void
    {
        $invoice = $this->document(Invoice::class, ['base_grand_total' => 20.0, 'grand_total' => 20.0]);

        (new Document())->collect($invoice);

        $this->assertSame(20.0, $invoice->getData('base_giftcard_amount'));
        $this->assertSame(0.0, (float) $invoice->getData('base_grand_total'));
    }

    /**
     * The next invoice gets what is left; a canceled invoice does not count.
     */
    public function testNextInvoiceGetsTheRestAndCanceledInvoicesDoNotCount(): void
    {
        $this->siblings = [
            $this->document(Invoice::class, ['base_giftcard_amount' => 20.0, 'giftcard_amount' => 20.0], 1),
            $this->document(
                Invoice::class,
                ['base_giftcard_amount' => 5.0, 'giftcard_amount' => 5.0],
                2,
                Invoice::STATE_CANCELED
            ),
        ];
        $invoice = $this->document(Invoice::class, ['base_grand_total' => 50.0, 'grand_total' => 50.0]);

        (new Document())->collect($invoice);

        $this->assertSame(10.0, $invoice->getData('base_giftcard_amount'));
        $this->assertSame(40.0, (float) $invoice->getData('base_grand_total'));
    }

    /**
     * A credit memo gives back at most its own grand total.
     */
    public function testCreditMemoIsCappedByItsGrandTotal(): void
    {
        $creditmemo = $this->document(Creditmemo::class, ['base_grand_total' => 12.0, 'grand_total' => 12.0]);

        (new Document())->collect($creditmemo);

        $this->assertSame(12.0, $creditmemo->getData('giftcard_amount'));
        $this->assertSame(0.0, (float) $creditmemo->getData('grand_total'));
    }

    /**
     * Invoice or credit memo of an order paid with 30 of gift card, backed by an array.
     *
     * @param class-string<Invoice|Creditmemo> $class
     * @param array<string,float> $data
     * @param int|null $id
     * @param int $state
     * @return Invoice|Creditmemo
     */
    private function document(string $class, array $data, ?int $id = null, int $state = 1): object
    {
        $order = $this->createStub(Order::class);
        $order->method('getData')->willReturnMap([
            ['base_giftcard_amount', null, 30.0],
            ['giftcard_amount', null, 30.0],
        ]);
        $order->method('getInvoiceCollection')->willReturnCallback(fn () => $this->siblings);
        $order->method('getCreditmemosCollection')->willReturnCallback(fn () => $this->siblings);

        $document = $this->createStub($class);
        $document->method('getOrder')->willReturn($order);
        $document->method('getId')->willReturn($id);
        $document->method('getState')->willReturn($state);
        $document->method('getData')->willReturnCallback(function ($key) use (&$data) {
            return $data[$key] ?? null;
        });
        $document->method('setData')->willReturnCallback(function ($key, $value) use (&$data, $document) {
            $data[$key] = $value;
            return $document;
        });
        return $document;
    }
}
