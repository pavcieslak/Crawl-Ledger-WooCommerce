<?php
/**
 * Plugin Name:       Crawl Ledger for WooCommerce
 * Plugin URI:        https://github.com/pavcieslak/Crawl-Ledger
 * Description:       WooCommerce module for Crawl Ledger — adds price, currency, availability, SKU, GTIN, brand, attributes and variants to product Markdown, hides cart/checkout/account pages from AI agents and reports which products AI bots fetch.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Lucmedia
 * Author URI:        https://www.lucmedia.pl/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       crawl-ledger-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.3
 *
 * @package CrawlLedger\WooCommerce
 */

namespace CrawlLedger\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRAWL_LEDGER_WC_VERSION', '1.0.0' );
define( 'CRAWL_LEDGER_WC_FILE', __FILE__ );
define( 'CRAWL_LEDGER_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRAWL_LEDGER_WC_MIN_CORE', '1.0.1' );

// Only reads products — declare compatibility with HPOS and block-based cart/checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CRAWL_LEDGER_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CRAWL_LEDGER_WC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$missing = [];

		if ( ! class_exists( '\CrawlLedger\Plugin' ) || ! defined( 'CRAWL_LEDGER_VERSION' ) ) {
			$missing[] = 'Crawl Ledger ' . CRAWL_LEDGER_WC_MIN_CORE . '+';
		} elseif ( version_compare( CRAWL_LEDGER_VERSION, CRAWL_LEDGER_WC_MIN_CORE, '<' ) ) {
			$missing[] = 'Crawl Ledger ' . CRAWL_LEDGER_WC_MIN_CORE . '+ (installed: ' . CRAWL_LEDGER_VERSION . ')';
		}
		if ( ! class_exists( '\WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}

		if ( $missing ) {
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						printf(
							'<div class="notice notice-warning"><p><strong>Crawl Ledger for WooCommerce</strong> %s <strong>%s</strong>.</p></div>',
							esc_html__( 'is inactive. Required:', 'crawl-ledger-woocommerce' ),
							esc_html( implode( ', ', $missing ) )
						);
					}
				}
			);
			return;
		}

		require_once CRAWL_LEDGER_WC_PATH . 'includes/class-crawl-ledger-wc.php';
		( new Integration() )->hooks();
	},
	20 // After Crawl Ledger core (10).
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( '\CrawlLedger\Plugin' ) ) {
			\CrawlLedger\Plugin::bump_cache();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( '\CrawlLedger\Plugin' ) ) {
			\CrawlLedger\Plugin::bump_cache();
		}
	}
);
