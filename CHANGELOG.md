# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [2.1.0] - 2026-11-24

Gift cards can be applied to a cart and listed over REST and GraphQL, and two cron jobs repair what a request that died half-way leaves behind: a card debited for an order that was never saved, a paid invoice that issued no card.

Upgrade: the update adds a column to `sales_order_item` and two indexes to the module's own tables; on a large shop, plan it like any change to the order tables. One behaviour changes: a credit memo is refused when it would leave the customer with active gift cards and refunded money for more than was paid (see *Changed*). Magento's cron must run for the two new jobs.

### Added

- REST API: the gift cards applied to a cart (list, apply, remove), for customers (`/V1/carts/mine/gift-cards`) and guests (`/V1/guest-carts/:cartId/gift-cards`), and the cards of a customer account (`/V1/customers/me/gift-cards`). See *API* in the README.
- GraphQL: mutations `applyGiftCardToCart` and `removeGiftCardFromCart`, field `applied_gift_cards` on the cart and field `gift_cards` on the customer.
- Cron job `mageos_giftcard_give_back_orphans`, every hour: a card debited for an order that was never saved (the request died between the two) gets its money back an hour later, with *The order was never saved* in its history.
- Cron job `mageos_giftcard_issue_missing`, every 15 minutes: gift cards that a paid invoice should have issued and did not (an error, a request that died) are issued and e-mailed, for invoices paid in the last 7 days.

### Changed

- Credit memos: a refund that would leave the customer with more than was paid is refused, counting the money refunded on the order and the value of the gift cards it issued that are still active. It closes the refund of a gift card by *Adjustment Refund* alone, without quantity, which gave the money back and left the card usable. Refund the gift card lines with their quantities, or disable the cards first.
- The API and the cart form share the same rules: five cards per cart, 10 wrong codes block the cart for 15 minutes, whatever the way in.

### Fixed

- An order line remembers that it sold a gift card: a product unflagged or deleted between the order and the payment of its invoice no longer leaves the customer without the card they paid for. Applies to orders placed from this version on.
- When some of the cards of an invoice line were already issued, each remaining card got the value of the whole line instead of one unit.
- The gift e-mails are sent by one cron run at a time, 100 per run: two runs overlapping could e-mail the same card twice.

### Compatibility

- REST and GraphQL: covered by unit tests of their shared rules, not yet called on a running store.

## [2.0.0] - 2026-11-23

Security and money fixes from a full review of the module: full codes are hidden from admins who only consult the cards, and several ways to get back, or lose, more than was paid are closed.

Upgrade: three behaviours change. Admin roles that must read or export full gift card codes need the resource *Sales > Operations > Gift Cards > See Full Codes and E-mail Them* (formerly *E-mail Codes*; roles that already had it keep it). A credit memo that refunds a gift card the customer already spent is now refused: enter the spent amount as *Adjustment Fee*, or refund fewer units. Money given back to an expired card can be spent for 30 days, no longer for a whole new validity. Also anonymise the gift card code columns in copies of your database (see *Personal data* in the README).

### Changed

- Admin: full codes (grid, export, history page title, creation message) are shown only with the resource *See Full Codes and E-mail Them*, the former *E-mail Codes*. Other roles see the last four characters and find a card by typing its whole code; nobody sorts the grid by code any more.
- Credit memos: refunding a gift card the customer already spent is refused unless the *Adjustment Fee* covers what was spent. It was accepted before, with a comment on the order afterwards.
- Money given back to an expired card can be spent for 30 more days, instead of a whole new validity.
- Wrong codes are counted per cart instead of per IP address: 10 wrong codes block the cart for 15 minutes.
- Web addresses are refused in the recipient name, sender name and gift message.
- A customer-chosen amount is rounded to cents.

### Fixed

- Multi-address checkout: a gift card applied in the cart reduced the orders without being debited. Gift cards are no longer deducted there.
- An order canceled because its payment was denied after review did not give the gift card amount back. It does now.
- A cancellation or credit memo could give back more than the order took from a card. It is now capped by the card's history.
- A card whose code has only digits was left debited, with an error page, when another card of the same order could no longer pay. It is given back.
- A checkout error raised after the order was saved gave the gift card amount back while the order stayed. The card now stays debited, as the order exists.
- The buyer's e-mail with the codes had its lines about gifts in the language of the admin who created the invoice. It is written entirely in the language of the order's store view.
- French, Spanish and Italian: the buyer's e-mail read wrongly for a gift sent on the invoice date.
- *Send the Code To* another address than the recipient's canceled the scheduled gift e-mail, and a failure there counted against its five attempts. Neither happens now.
- The history page of a card whose order was deleted showed an error. It opens again.
- Admin: an absurdly large balance gave an error page. It is refused with a message.
- The e-mail template names (*Gift Card Codes*, *Gift Card for a Recipient*) were not translated in the admin.

### Security

- Admins who could only consult the cards could read and export every full code, which is all it takes to spend a card. See *Changed* and the upgrade note.
- Behind a proxy, one visitor entering wrong codes could block gift cards for every customer of the shop.
- An expired card could be renewed again and again through orders placed and then canceled.
- The gift e-mail could carry links to an address chosen by the buyer, under the shop's name; no card is issued or e-mailed any more for a line paid 0.
- An invoice paid twice could issue a second set of cards.
- Gift card codes are stored as they are in three database columns: the README (*Personal data*) lists them, with the personal data the module keeps, so that copies of the database are anonymised.

### Compatibility

- The README gives the status of each storefront target. This version was run on Mage-OS 4.0.0: in a browser on Luma (product page, cart, checkout and order, *My Gift Cards*, multi-address checkout), and through Magento's own services with the Hyvä default theme (totals, order placement, cancellation, invoice, credit memo, e-mails). Elasticsuite 2.12 was the search engine and finds gift card products. Not run: Hyvä Checkout (written from its layouts, no licensed install), Loki Checkout again since 1.2.1, and the Hyvä CSP theme.
- Luma: *Check Out with Multiple Addresses* does not use gift cards.
- `magento/module-config` is declared as a dependency.

## [1.2.1] - 2026-11-23

### Fixed

- The gift card total segment is listed before the grand total: Loki Checkout showed the gift card line under the grand total.

## [1.2.0] - 2026-11-23

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
