# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Hyvä Checkout: gift card line in the price summary, and the *Apply Gift Card* form under it.
- Loki Checkout: the *Apply Gift Card* form in the sidebar, after the coupon code (the gift card line was already shown).
- Admin grid: *Customer* column (e-mail of the account that owns the card), optional columns (recipient and sender names, send date, e-mailed date), bookmarks, column chooser and export.

### Changed

- Admin grids show amounts formatted in the base currency.

## [1.1.0] - 2026-11-19

Upgrade: run `bin/magento setup:upgrade` (new columns, tables and ACL resources) and make sure Magento's cron runs (gift e-mails). The admin roles that should create cards, change them or e-mail codes need the new resources under *Sales > Operations > Gift Cards*.

### Changed

- The buyer's e-mail says, for each code bought as a gift, to whom and when it is sent, and shows only the end of that code.
- Product-page text is stored without HTML tags; recipient e-mail addresses with a quoted local part are refused.
- A gift card is worth what its unit was paid: price including tax, less its share of the cart discount (it was the unit price before discount).
- Order comments show only the last characters of the issued codes.

### Fixed

- Gift cards were debited outside core's error handling: if another module refused the order afterwards, the cards stayed debited with no order. They are now debited when the order is placed.

### Added

- Admin grid of gift cards (*Sales > Operations > Gift Cards*): code, initial balance, balance, status, expiry, recipient, order of purchase (linked), creation date, with filters. Status and expiry are edited in place in the grid, the balance on the card's history page (refused if the card was used meanwhile); a disabled or expired card cannot be used in the cart.
- Gift cards now record their initial balance and the order they were bought in.
- Customer-chosen amount: *Gift Card Minimum Amount* and *Gift Card Maximum Amount* product attributes on virtual gift cards.
- Send as a gift: recipient name and e-mail, sender name, message and send date on the product page; the recipient is e-mailed on that date by a cron job. The lines are shown with the cart item, in the order and e-mails.
- Several gift cards per order (up to five), used in the order applied; cancellations and credit memos refill the last card used first.
- Refunding a purchased gift card disables the cards of the refunded line, unspent ones first, and notes on the order a card that was already partly spent.
- Money given back to an expired card comes with a new validity; to a disabled card, it is noted on the order.
- Gift cards cannot pay for gift cards bought in the same cart.
- After 10 wrong codes from one IP address in 15 minutes, codes are refused for a while.
- Separate ACL resources to create cards, change them and e-mail codes.
- Failed gift e-mails are tried five times at most.
- *My Gift Cards* in the customer account; cards belong to the logged-in buyer, or to the recipient's account, where a gift shows up once e-mailed, with the end of its code.
- Optional expiry: *Validity (Months)* in *Stores > Configuration > Sales > Gift Cards*; expiry editable in the grid.
- *Add Gift Card* in the admin grid: a card without an order, optionally e-mailed to a recipient.
- *Send the Code To* on the history page: e-mails a card's code again, to any address.
- Balance history of each card (*History* in the grid): issue, use in an order, refund, cancellation, admin balance and status changes with the admin's user name, each with the balance it left.

## [1.0.0] - 2026-11-18

### Added

- *Gift Card* product attribute: when the invoice is paid and committed, one code per unit is created with a balance equal to the unit price, e-mailed to the buyer and recorded as an order comment.
- Gift cards never need shipping: the *Gift Card* attribute applies to virtual products and to configurable products whose variants are all virtual (one variant per amount); saving a gift card that would need shipping is refused.
- Gift card code in the cart, deducted after tax like a means of payment; the balance is debited atomically when the order is placed and credited back on cancellation or credit memo.
- Gift card line in cart totals, the checkout summary, order views, the order e-mail and admin invoices / credit memos.
- Storefront: Hyvä default theme and Luma; the module registers itself for Hyvä's Tailwind compilation.
- Admin and storefront texts translated into French, German, Spanish and Italian.
- Supports Mage-OS 3.6 and 4.x / Magento Open Source 2.4.9 (`magento/framework` ^103.0.9), PHP 8.3 to 8.5.
