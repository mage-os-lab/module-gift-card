# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-18

### Added

- *Gift Card* product attribute: when the invoice is paid and committed, one code per unit is created with a balance equal to the unit price, e-mailed to the buyer and recorded as an order comment.
- Gift cards never need shipping: the *Gift Card* attribute applies to virtual products and to configurable products whose variants are all virtual (one variant per amount); saving a gift card that would need shipping is refused.
- Gift card code in the cart, deducted after tax like a means of payment; the balance is debited atomically when the order is placed and credited back on cancellation or credit memo.
- Gift card line in cart totals, the checkout summary, order views, the order e-mail and admin invoices / credit memos.
- Storefront: Hyvä default theme and Luma; the module registers itself for Hyvä's Tailwind compilation.
- Admin and storefront texts translated into French, German, Spanish and Italian.
- Supports Mage-OS 3.6 and 4.x / Magento Open Source 2.4.9 (`magento/framework` ^103.0.9), PHP 8.3 to 8.5.
