<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Plugin;

use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Model\Cart\TotalsConverter;

/**
 * Lists the gift card before the grand total it is deducted from.
 *
 * The gift card total is collected after the grand total, so it comes after it in the total segments; checkouts
 * that show the segments in that order (Loki Checkout) would end with the gift card line.
 */
class GrandTotalLast
{
    /**
     * Move the grand total to the end when a gift card is applied.
     *
     * @param TotalsConverter $subject
     * @param array<string,TotalSegmentInterface> $segments by total code
     * @return array<string, TotalSegmentInterface>
     */
    public function afterProcess(TotalsConverter $subject, array $segments): array
    {
        if (isset($segments['giftcard'], $segments['grand_total'])) {
            $grandTotal = $segments['grand_total'];
            unset($segments['grand_total']);
            $segments['grand_total'] = $grandTotal;
        }
        return $segments;
    }
}
