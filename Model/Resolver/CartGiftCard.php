<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model\Resolver;

use MageOS\GiftCard\Model\CartGiftCards;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Phrase;

/**
 * Mutations "applyGiftCardToCart" and "removeGiftCardFromCart".
 *
 * The cart is found without Magento_QuoteGraphQl's classes, so the module compiles when GraphQL is not installed.
 */
class CartGiftCard implements ResolverInterface
{
    /**
     * @param CartGiftCards $cartGiftCards
     */
    public function __construct(private readonly CartGiftCards $cartGiftCards)
    {
    }

    /**
     * Apply or remove the code on the cart of the current customer or guest, and return the cart.
     *
     * Only a customer token counts as a customer: an admin or integration token is a guest here, and gets
     * no customer's cart.
     *
     * @param Field $field
     * @param mixed $context Magento_GraphQl's context, a class this module does not require
     * @param ResolveInfo $info
     * @param array<string,mixed>|null $value
     * @param array<string,mixed>|null $args
     * @return array<string,mixed>
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $customerId = (int) $context->getUserType() === UserContextInterface::USER_TYPE_CUSTOMER
            ? (int) $context->getUserId()
            : 0;
        $code = (string) ($args['input']['gift_card_code'] ?? '');
        try {
            $quote = $this->cartGiftCards->getQuote((string) ($args['input']['cart_id'] ?? ''), $customerId);
            if ($field->getName() === 'removeGiftCardFromCart') {
                $this->cartGiftCards->remove($quote, $code);
            } else {
                $this->cartGiftCards->apply($quote, $code);
            }
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(new Phrase($e->getMessage()), $e);
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(new Phrase($e->getMessage()), $e);
        }
        return ['cart' => ['model' => $quote]];
    }
}
