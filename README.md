# Crawl Ledger for WooCommerce

WooCommerce module for **[Crawl Ledger](https://github.com/pavcieslak/Crawl-Ledger)**: turns product pages into rich Markdown for AI shopping agents and shows which products AI bots actually read.

Requires **Crawl Ledger 1.0.1+**, **WooCommerce 8.0+**, WordPress 6.2+, PHP 8.1+. HPOS and block cart/checkout compatible (it only reads products).

## Installation

1. Install and activate [Crawl Ledger](https://github.com/pavcieslak/Crawl-Ledger) (1.0.1 or newer).
2. Download [`dist/crawl-ledger-woocommerce-1.0.0.zip`](https://github.com/pavcieslak/Crawl-Ledger-WooCommerce/raw/main/dist/crawl-ledger-woocommerce-1.0.0.zip) → *Plugins → Add New → Upload Plugin* → activate.
3. *Settings → Crawl Ledger* → tick **Products** in *Post types* → save. WooCommerce options appear in the same form.

If Crawl Ledger or WooCommerce is missing, the module stays inactive and shows an admin notice.

## What it adds

**Product Markdown** (`/product/glass-jar.md`):

```markdown
---
title: "Glass jar 500 ml – Shop"
type: "Product"
product_type: "simple"
price: "19.90"
regular_price: "24.90"
currency: "PLN"
price_includes_tax: true
on_sale: true
availability: "InStock"
sku: "SL-500"
gtin: "5901234123457"
brand: ["Lucmedia Jars"]
rating: 4.75
review_count: 12
attributes:
  - name: "Capacity"
    value: "500 ml, 1 l"
…
---

# Glass jar 500 ml

Screw-top jar for preserves.

- **Price:** 19.90 PLN (Regular price: 24.90 PLN)
- **Stock:** In stock
- **SKU:** SL-500
- **GTIN:** 5901234123457
- **Brand:** Lucmedia Jars
- **Rating:** 4.75/5 (12)

## Additional information

- **Capacity:** 500 ml, 1 l
- **Weight:** 0.4 kg
- **Dimensions:** 10 × 20 × 5 cm

## Variations

| Variation | Price | Stock | SKU |
| --- | --- | --- | --- |
| Capacity: 1 l | 29.90 PLN | In stock | SL-1000 |

## Description

…
```

- Prices follow the shop's tax display setting (`price_includes_tax`), variable products show `price`–`price_max`.
- Labels use WooCommerce's own translations, so they match the shop language.
- Brands from WooCommerce Brands (`product_brand`), Perfect Brands or YITH Brands.
- GTIN from WooCommerce's *GTIN, UPC, EAN or ISBN* field (9.2+).
- External/affiliate products include the purchase URL.

**llms.txt** lines get price and stock:
`- [Glass jar 500 ml](…/glass-jar.md): Screw-top jar… — 19.90 PLN (Regular price: 24.90 PLN) · In stock`

**Exclusions**
- Cart, Checkout and My account are never exposed.
- Products hidden from the catalog (or out of stock when the shop hides them) are never exposed.
- Optional: hide all out-of-stock products.

**Always fresh** — price, sale, stock and variation prices are part of the cache key, so documents update when an order changes stock or a scheduled sale starts, without editing the product. Stock-status changes and scheduled sales also rebuild `llms.txt`.

**Analytics** — *Settings → Crawl Ledger → Analytics* gets a *WooCommerce* section: AI hits per product, live assistant fetches (`ChatGPT-User`, `Claude-User`, `Perplexity-User` — someone asked an AI about your product right now), Markdown hits, last AI visit. The same data is added to the REST report under `woocommerce`:

```bash
curl -u "login:app-password" "https://shop.example/wp-json/crawl-ledger/v1/stats?days=30" | jq .woocommerce
```

## Settings

| Option | Default |
| --- | --- |
| Hide out-of-stock products from AI agents | off |
| Include variations table | on |
| Max variations per product | 50 |
| Include rating and review count | on |
| Show price and stock in llms.txt | on |

## Hooks

| Hook | Purpose |
| --- | --- |
| `crawl_ledger_wc_product_data( array $data, WC_Product $product )` | Modify normalized product data before it is rendered. |

All other customization goes through the Crawl Ledger core filters.

## License

GPL-2.0-or-later · © [Lucmedia](https://www.lucmedia.pl)
