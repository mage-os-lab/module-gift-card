# Gift Card

`mage-os/module-gift-card` (`MageOS_GiftCard`) — a module for Mage-OS / Magento Open Source.

Gift cards that are sold as regular products and redeemed in the cart.

- Flag a virtual product, or a configurable product whose variants are all virtual (one variant per amount), with the *Gift Card* attribute. Gift cards never need shipping: the attribute is not offered on other product types, and saving a gift card that would need shipping (a variant with a weight, for example) is refused. When its invoice is paid, one code per unit is created with a balance equal to the unit price, e-mailed to the buyer and recorded as an order comment.
- Customers enter a code in the cart. The amount is deducted after tax, like a means of payment; the remaining balance stays on the card.
- The balance is debited atomically when the order is placed, and credited back on order cancellation or credit memo.
- The gift card line is shown in cart totals, the checkout summary, order views, the order e-mail and admin invoices / credit memos.

Limitations: one gift card per cart, no multi-address checkout, no admin grid of gift cards, and no integration with Hyvä Checkout.

## Compatibility

Mage-OS 3.6 and 4.x, and Magento Open Source 2.4.9: all share the Magento 2.4.9 base (`magento/framework` ^103.0.9). PHP 8.3 to 8.5. CI runs the check-extension workflow on the currently supported Mage-OS versions (today Mage-OS 3.6.0 with PHP 8.4; Mage-OS 4.0 joins once the check-extension actions know it); developed on Mage-OS 4.0.0 with PHP 8.5. Storefront: Hyvä default theme (1.5) and Luma; Hyvä Checkout and GraphQL are not supported.

## Installation

```bash
composer config repositories.mageos-gift-card vcs https://github.com/mage-os-lab/module-gift-card
composer require mage-os/module-gift-card
bin/magento module:enable MageOS_GiftCard
bin/magento setup:upgrade
```

## Configuration

No configuration section. The *Gift Card* product attribute (global, on virtual and configurable products only) marks the products that sell gift cards.

![Gift Card product attribute](docs/images/product-flag.png)

The e-mail with the codes uses the *Gift Card Codes* template (`mageos_giftcard_issued`), sent from the *Sales Representative* identity of the store.

## Getting started

1. Create a virtual product, for example "Gift card 25", priced 25, with no tax class, and set *Gift Card* to Yes.
   To sell several amounts in one product, create a configurable product instead, with *Weight* set to *This item has no weight* so that the generated variants are virtual, one variant per amount (25, 50, 100...). Set *Gift Card* to Yes on the configurable product itself, not on its variants.
2. Order it and invoice the order. Once the invoice is saved, the buyer receives the code by e-mail and the code is added as an order comment.
3. In another cart, open *Apply Gift Card*, enter the code and apply it: the amount is deducted from the grand total.

![Gift card form in the cart](docs/images/cart-form.png)

## Troubleshooting

- **No code after invoicing**: the product's *Gift Card* attribute is No at the global scope, or the invoice was not paid (offline "Not capture").
- **Code e-mail missing**: the codes are still in the order comments; check mail sending and `var/log/system.log` for "Gift card e-mail failed".
- **"The gift card balance has changed" at checkout**: the balance was used in another order meanwhile; the customer must re-apply the card.
- **"All variants of a gift card must be virtual products"** or **"must stay a virtual product"**: a variant has a weight, which makes it a simple product that needs shipping. Remove the weight (*This item has no weight*).
- **"Only a virtual product, or a configurable product with virtual variants, can be a gift card"**: a weight was given to a virtual gift card. Set *Gift Card* to No first if the product should become a shipped product.

## More

- [CHANGELOG](CHANGELOG.md)
- [Security policy](SECURITY.md)
- [License](LICENSE.txt): Open Software License 3.0. This module contains no code from Adobe Commerce.
