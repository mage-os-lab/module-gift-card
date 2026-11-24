<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Resolver;

use MageOS\GiftCard\Model\CartGiftCards;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote;

/**
 * Field "applied_gift_cards" of a cart. The cart resolver already checked whose cart it is.
 */
class AppliedGiftCards implements ResolverInterface
{
    /**
     * @param CartGiftCards $cartGiftCards
     */
    public function __construct(private readonly CartGiftCards $cartGiftCards)
    {
    }

    /**
     * The cards applied to the cart, amounts in its base currency.
     *
     * @param Field $field
     * @param mixed $context Magento_GraphQl's context, a class this module does not require
     * @param ResolveInfo $info
     * @param array<string,mixed>|null $value
     * @param array<string,mixed>|null $args
     * @return array<int, array<string,mixed>>
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $quote = $value['model'] ?? null;
        if (!$quote instanceof Quote) {
            return [];
        }
        $currency = $quote->getBaseCurrencyCode();
        return array_map(
            fn (array $card) => [
                'code' => $card['code'],
                'applied_balance' => ['value' => $card['applied'], 'currency' => $currency],
                'current_balance' => ['value' => $card['balance'], 'currency' => $currency],
                'expiration_date' => $card['expires_at'],
            ],
            $this->cartGiftCards->getApplied($quote)
        );
    }
}
