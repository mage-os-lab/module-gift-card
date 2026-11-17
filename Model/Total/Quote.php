<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Total;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * Pays part or all of the quote with the applied gift card, capped by its balance and the grand total.
 */
class Quote extends AbstractTotal
{
    /**
     * @param GiftCards $giftCards
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
        $this->setCode('giftcard');
    }

    /**
     * The address that carries no items (billing, or shipping of a virtual cart) is skipped.
     *
     * @param QuoteModel $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     */
    public function collect(QuoteModel $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        if (!$shippingAssignment->getItems()) {
            return $this;
        }
        $code = (string) $quote->getData('giftcard_code');
        $baseAmount = $code === ''
            ? 0.0
            : min((float) $this->giftCards->getBalance($code), (float) $total->getBaseGrandTotal());
        $amount = min(
            $this->priceCurrency->convertAndRound($baseAmount, $quote->getStore()),
            (float) $total->getGrandTotal()
        );

        $total->setBaseGrandTotal($total->getBaseGrandTotal() - $baseAmount);
        $total->setGrandTotal($total->getGrandTotal() - $amount);
        $quote->setData('base_giftcard_amount', $baseAmount)->setData('giftcard_amount', $amount);
        return $this;
    }

    /**
     * Total segment shown in the cart and checkout summary.
     *
     * @param QuoteModel $quote
     * @param Total $total
     * @return array<string, mixed>
     */
    public function fetch(QuoteModel $quote, Total $total)
    {
        $amount = (float) $quote->getData('giftcard_amount');
        return $amount > 0
            ? ['code' => $this->getCode(), 'title' => __('Gift Card'), 'value' => -$amount]
            : [];
    }
}
