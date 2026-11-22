# Changelog

All notable changes to Crawl Ledger for WooCommerce. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [1.0.1] — 2026-11-22

### Added
- **Top products chart** on the Analytics tab: the ten most-fetched products as horizontal stacked bars (AI bots vs other bots). Uses the Chart.js copy bundled with Crawl Ledger 1.0.2+; with older core versions the chart is simply skipped.
- **Product categories table:** bot hits, AI hits, distinct bots and last hit per product category, built from the archive logging introduced in Crawl Ledger 1.0.2. Paginated archives (`/category/page/2/`) count towards their category.
- REST `/stats` → `woocommerce.categories`.

### Changed
- Declared compatibility: WooCommerce tested up to 11.3 (verified on a live store running WooCommerce 11.3.1 with HPOS).

## [1.0.0] — 2026-11-11

### Added
- Initial release: product Markdown (price, currency, availability, SKU, GTIN, brand, attributes, variations, rating), price and stock in `llms.txt`, cart/checkout/account exclusion, product analytics.

[1.0.1]: https://github.com/pavcieslak/Crawl-Ledger-WooCommerce/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/pavcieslak/Crawl-Ledger-WooCommerce/releases/tag/v1.0.0
