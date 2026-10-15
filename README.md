# Coinsnap for Easy Digital Downloads

![Coinsnap for Easy Digital Downloads](https://resources.coinsnap.org/products/easydigitaldownloads/images/1.png)

**Bitcoin payment add-on for Easy Digital Downloads — accept Bitcoin and Lightning for digital products, software licenses, memberships, subscriptions, downloads, bundles and other EDD checkout flows.**

Coinsnap for Easy Digital Downloads adds Bitcoin as a payment option directly to the Easy Digital Downloads checkout.

Customers can pay with **Bitcoin via Lightning or on-chain** for ebooks, software, media files, templates, access rights, memberships, subscriptions, software licenses, bundles and other digital products.

Coinsnap can be used alongside existing Easy Digital Downloads payment gateways such as Stripe or PayPal, or as a standalone Bitcoin payment option.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning payments** in Easy Digital Downloads
- Support for **Lightning and Bitcoin on-chain**
- Works with the native Easy Digital Downloads checkout
- Support for **Easy Digital Downloads Free and Pro**
- Sell digital downloads, ebooks, software, media files and templates
- Sell software licenses, upgrades and renewals
- Support memberships, subscriptions and paid access models
- Sell bundles and packaged digital offers
- Accept donations and tips
- Support low-priced downloads and Lightning micropayments
- Use Bitcoin alongside Stripe, PayPal and other gateways
- Support for **Coinsnap** and **BTCPay Server**
- BTCPay Server setup wizard
- Custom return URL after successful payment
- Payment-status mapping for Expired, Processing and Settled
- Optional **Bitcoin discount** as a fixed amount or percentage
- Customizable Bitcoin checkout name, icon and text
- No own Lightning node required when using Coinsnap
- **Non-custodial / self-custody** payment model
- Optional settlement to a bank account through supported Coinsnap partners
- Open source and free to install

## Current public release

The current WordPress.org release is **1.3.5**.

WordPress.org currently lists:

- WordPress **5.2 or newer**
- PHP **7.4 or newer**
- Tested up to **WordPress 7.0.7**
- Easy Digital Downloads compatibility tested up to **3.6.8**

> **Developer note:** the current GitHub `main` branch still identifies the plugin as version **1.3.4**, tested with WordPress 6.9 and earlier Easy Digital Downloads compatibility metadata. The repository code metadata and public WordPress.org release should be synchronized separately.

## Typical use cases

Coinsnap for Easy Digital Downloads can be used for:

- ebooks and PDFs
- software downloads
- software licenses
- upgrades and renewals
- audio and video files
- templates and design assets
- digital bundles
- memberships
- subscriptions
- paid access keys
- restricted content
- donations and tips
- low-priced digital products
- micropayment-based downloads
- global digital sales

## How it works

1. Install and activate **Easy Digital Downloads**.
2. Install and activate **Coinsnap for Easy Digital Downloads**.
3. Go to **Downloads → Settings → Payments**.
4. Enable **Coinsnap** as an active gateway.
5. Open **Downloads → Settings → Payments → Coinsnap**.
6. Choose **Coinsnap** or your own **BTCPay Server**.
7. Enter the required Store ID and API credentials.
8. Configure the checkout text, return URL and payment-status mapping.
9. Configure your EDD products and pricing.
10. Make a test Bitcoin payment before going live.

## Coinsnap or BTCPay Server

The add-on supports two payment-provider modes:

- **Coinsnap** — for merchants who want to accept Bitcoin without operating their own Bitcoin or Lightning payment infrastructure.
- **BTCPay Server** — for merchants who want to connect their own self-hosted BTCPay Server.

## Easy Digital Downloads integration

Coinsnap integrates directly into the Easy Digital Downloads checkout.

The merchant continues to use EDD for:

- product management
- customer management
- orders
- downloads
- licenses
- memberships and subscriptions
- bundles
- checkout

Coinsnap handles the Bitcoin payment while Easy Digital Downloads continues to manage the digital-commerce workflow.

## Bitcoin discount

The current Coinsnap Easy Digital Downloads product page supports an optional **Bitcoin discount**.

A fixed or percentage-based discount can be applied at checkout to encourage customers to choose Bitcoin when multiple payment methods are available.

## Micropayments and digital commerce

Lightning makes small-value digital sales practical.

This is useful for:

- low-priced downloads
- pay-per-download products
- access keys
- small donations and tips
- low-value software or digital assets
- pay-per-access content
- other micropayment-based digital products

## Payment status mapping

Coinsnap uses the payment states:

- **Expired**
- **Processing**
- **Settled**

These can be mapped to the corresponding Easy Digital Downloads payment/order statuses.

## Checkout customization

The Bitcoin payment method can be adapted to the EDD checkout.

Current Coinsnap product documentation describes options for the payment-method name, icon/logo, checkout text and success redirect.

## Settlement

With Coinsnap, payments can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a **non-custodial / self-custody** approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin payments can also be automatically converted and settled to a bank account.

## Fees

Coinsnap charges a **1% transaction fee**. The fee is handled separately through the merchant's Coinsnap balance and is not deducted from the Bitcoin payment itself.

## Installation

### Install from WordPress.org

1. Install and activate **Easy Digital Downloads**.
2. In WordPress, go to **Plugins → Add New**.
3. Search for **Bitcoin payment for Easy Digital Downloads**.
4. Install and activate the Coinsnap add-on.
5. Open **Downloads → Settings → Payments**.
6. Enable Coinsnap as an active gateway.
7. Open the Coinsnap payment settings.
8. Connect Coinsnap or BTCPay Server.
9. Configure checkout and status settings.
10. Make a test Bitcoin payment.

### Install from GitHub

1. Download this repository as a ZIP archive.
2. Upload it through **Plugins → Add New → Upload Plugin**.
3. Install and activate the add-on.
4. Make sure Easy Digital Downloads is installed and active.
5. Configure Coinsnap in the EDD payment settings.

## Resources

- [Coinsnap for Easy Digital Downloads product page](https://coinsnap.io/wp-add-ons/easy-digital-downloads/)
- [Easy Digital Downloads Bitcoin User Guide](https://coinsnap.io/user-guides/easy-digital-downloads-bitcoin-user-guide/)
- [WordPress Plugin Directory](https://wordpress.org/plugins/coinsnap-for-easy-digital-downloads/)
- [Easy Digital Downloads demo](https://easydigitaldownloads.coinsnap.org/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### Can Easy Digital Downloads accept Bitcoin payments?

Yes. Coinsnap adds Bitcoin and Lightning directly to the Easy Digital Downloads checkout.

### Can customers pay with both Lightning and Bitcoin on-chain?

Yes. The integration supports both Lightning and Bitcoin on-chain.

### What can I sell with Bitcoin in EDD?

Digital downloads, ebooks, software, software licenses, memberships, subscriptions, bundles, access rights, donations and other EDD-based digital products.

### Does Coinsnap work with Easy Digital Downloads Pro?

Yes. EDD Pro support was added in version 1.3.0.

### Can Bitcoin be offered alongside Stripe or PayPal?

Yes. Coinsnap can be used alongside existing EDD payment gateways or as a standalone Bitcoin option.

### Can I use my own BTCPay Server?

Yes. BTCPay Server is supported as an alternative to Coinsnap.

### Does the plugin support a Bitcoin discount?

Yes. The current Coinsnap product page documents an optional fixed or percentage-based Bitcoin discount.

### Can I use Lightning for low-priced downloads?

Yes. Lightning is particularly suitable for low-value downloads, micropayments and pay-per-access products.

### Can I customize the Bitcoin checkout?

Yes. The current product documentation describes customization for the payment name, icon, text and success redirect.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Does Coinsnap hold my Bitcoin?

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

### Can payments be settled to a bank account?

Depending on the merchant setup and supported partner integrations, Bitcoin payments can be converted and settled to a bank account.

### What does Coinsnap cost?

Coinsnap charges a 1% transaction fee.

## Changelog

### 1.3.5 — 2026-05-24
- Compatibility with Easy Digital Downloads 3.6.8 tested.
- Compatibility with WordPress 7.0 tested.

### 1.3.4 — 2026-04-07
- Updated the Coinsnap Invoice client.
- Compatibility with Easy Digital Downloads 3.6.6.1 tested.
- Compatibility with WordPress 6.9.4 tested.

### 1.3.3 — 2026-03-06
- Compatibility with Easy Digital Downloads 3.6.4–3.6.5 tested.
- Updated graphic files.

### 1.3.2 — 2025-12-28
- Compatibility with Easy Digital Downloads 3.6.3 tested.
- Updated PHP file protection.

### 1.3.1 — 2025-12-04
- Compatibility with WordPress 6.9 tested.

### 1.3.0 — 2025-11-26
- Added Easy Digital Downloads Pro support.
- Updated the Coinsnap Store client.
- Compatibility with Easy Digital Downloads 3.6.1.1 tested.
- Compatibility with Easy Digital Downloads Pro 3.3.5.2 tested.

### 1.2.0 — 2025-10-13
- Added handling for currencies not supported by BTCPay Server.
- Added custom redirect after payment.

### 1.1.1 — 2025-09-21
- Improved webhook handling and server-log warnings.
- Compatibility with Easy Digital Downloads 3.5.2 tested.

### 1.1.0 — 2025-08-18
- Updated BTCPay Server minimum-payment calculations.
- Updated BTCPay Server webhook handling.
- Added SATS-to-BTC conversion for BTCPay Server.
- Compatibility with Easy Digital Downloads 3.5.1 tested.

### 1.0.2 — 2025-07-20
- Added nonce validation to the BTCPay Server setup flow.
- Compatibility with Easy Digital Downloads 3.5 tested.
- Compatibility with WordPress 6.8.2 tested.

### 1.0.1 — 2025-07-10
- Improved connection-error handling.
- Improved API-key generation.
- Improved connection-status indication.

### 1.0.0 — 2025-07-04
- Initial release.
