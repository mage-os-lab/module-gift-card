<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Resolver;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Field "gift_cards" of the logged-in customer: the customer resolver hands over the account it checked.
 */
class CustomerGiftCards implements ResolverInterface
{
    /**
     * @param GiftCards $giftCards
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly GiftCards $giftCards,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The account's cards as "My Gift Cards" lists them, balances in the base currency of the current store.
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
        $customer = $value['model'] ?? null;
        if (!$customer instanceof CustomerInterface) {
            return [];
        }
        $store = $this->storeManager->getStore();
        $currency = $store instanceof Store ? $store->getBaseCurrencyCode() : null;
        return array_map(
            fn (array $card) => [
                'code' => $this->giftCards->ownerCode($card),
                'balance' => ['value' => (float) $card['balance'], 'currency' => $currency],
                'status' => strtoupper($this->giftCards->status($card)),
                'expiration_date' => $card['expires_at'],
            ],
            $this->giftCards->getByCustomer((int) $customer->getId())
        );
    }
}
