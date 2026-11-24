<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Api;

/**
 * Gift cards of a cart and of a customer account, for the REST API.
 *
 * A code is refused with the message the cart form shows; after too many wrong codes in a cart, codes are
 * refused in that cart for a few minutes.
 *
 * @api
 */
interface GiftCardManagementInterface
{
    /**
     * Gift cards applied to the customer's cart, in the order they pay.
     *
     * @param int $cartId
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getList(int $cartId): array;

    /**
     * Apply a gift card code to the customer's cart and return the cards applied.
     *
     * @param int $cartId
     * @param string $code
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function apply(int $cartId, string $code): array;

    /**
     * Remove a gift card code from the customer's cart and return the cards still applied.
     *
     * @param int $cartId
     * @param string $code
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function remove(int $cartId, string $code): array;

    /**
     * Gift cards applied to a guest cart, in the order they pay.
     *
     * @param string $cartId masked ID of the cart
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getGuestList(string $cartId): array;

    /**
     * Apply a gift card code to a guest cart and return the cards applied.
     *
     * @param string $cartId masked ID of the cart
     * @param string $code
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function applyToGuestCart(string $cartId, string $code): array;

    /**
     * Remove a gift card code from a guest cart and return the cards still applied.
     *
     * @param string $cartId masked ID of the cart
     * @param string $code
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function removeFromGuestCart(string $cartId, string $code): array;

    /**
     * Gift cards of the customer's account, newest first; a gift shows only the end of its code.
     *
     * @param int $customerId
     * @return \MageOS\GiftCard\Api\Data\GiftCardInterface[]
     */
    public function getCustomerCards(int $customerId): array;
}
