<?php
/**
 * WooCommerce integration for Crawl Ledger. Works exclusively through core filters.
 *
 * @package CrawlLedger\WooCommerce
 */

namespace CrawlLedger\WooCommerce;

use CrawlLedger\Analytics;
use CrawlLedger\Plugin;
use CrawlLedger\Yoast_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integration {

	public const OPTION = 'crawl_ledger_wc';

	/** Common brand taxonomies: WooCommerce 9.6+ core, Perfect Brands, YITH. */
	private const BRAND_TAXONOMIES = [ 'product_brand', 'pwb-brand', 'yith_product_brand' ];

	private array $cache = [];

	/* ---------------------------------------------------------------------
	 * Bootstrap & settings
	 * ------------------------------------------------------------------- */

	public static function defaults(): array {
		return [
			'exclude_out_of_stock' => false,
			'include_variations'   => true,
			'max_variations'       => 50,
			'include_rating'       => true,
			'llms_price'           => true,
		];
	}

	public static function settings(): array {
		$stored = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	public function hooks(): void {
		add_filter( 'crawl_ledger_is_eligible', [ $this, 'eligible' ], 10, 2 );
		add_filter( 'crawl_ledger_content_html', [ $this, 'content_html' ], 10, 2 );
		add_filter( 'crawl_ledger_seo_data', [ $this, 'seo_data' ], 10, 2 );
		add_filter( 'crawl_ledger_frontmatter', [ $this, 'frontmatter' ], 10, 2 );
		add_filter( 'crawl_ledger_cache_key_parts', [ $this, 'cache_key' ], 10, 2 );
		add_filter( 'crawl_ledger_llms_line', [ $this, 'llms_line' ], 10, 2 );
		add_filter( 'crawl_ledger_report', [ $this, 'report' ], 10, 2 );
		add_action( 'crawl_ledger_analytics_after', [ $this, 'render_analytics' ], 10, 2 );
		add_action( 'crawl_ledger_settings_fields', [ $this, 'render_settings' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'update_option_' . self::OPTION, [ Plugin::class, 'bump_cache' ] );

		// Stock/sale changes don't touch post_modified: refresh the llms.txt manifest.
		foreach ( [ 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_scheduled_sales' ] as $hook ) {
			add_action( $hook, [ Plugin::class, 'flush_manifest' ] );
		}
	}

	public function register_settings(): void {
		register_setting(
			'crawl_ledger', // Core settings group: saved by the same form.
			self::OPTION,
			[
				'type'              => 'array',
				'default'           => self::defaults(),
				'sanitize_callback' => static function ( mixed $in ): array {
					$in = is_array( $in ) ? $in : [];
					return [
						'exclude_out_of_stock' => ! empty( $in['exclude_out_of_stock'] ),
						'include_variations'   => ! empty( $in['include_variations'] ),
						'max_variations'       => min( 500, max( 1, absint( $in['max_variations'] ?? 50 ) ) ),
						'include_rating'       => ! empty( $in['include_rating'] ),
						'llms_price'           => ! empty( $in['llms_price'] ),
					];
				},
			]
		);
	}

	/* ---------------------------------------------------------------------
	 * Eligibility
	 * ------------------------------------------------------------------- */

	public function eligible( bool $eligible, \WP_Post $post ): bool {
		if ( ! $eligible ) {
			return false;
		}

		// Transactional pages carry no content for an agent.
		if ( 'page' === $post->post_type ) {
			$ids = array_filter( array_map( 'wc_get_page_id', [ 'cart', 'checkout', 'myaccount' ] ), static fn( $id ) => $id > 0 );
			if ( in_array( (int) $post->ID, $ids, true ) ) {
				return false;
			}
		}

		$product = $this->product( $post );
		if ( $product ) {
			// Respects catalog visibility "hidden" and the store's "hide out of stock" option.
			if ( ! $product->is_visible() ) {
				return false;
			}
			if ( self::settings()['exclude_out_of_stock'] && ! $product->is_in_stock() ) {
				return false;
			}
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Product data
	 * ------------------------------------------------------------------- */

	private function product( \WP_Post $post ): ?\WC_Product {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		return $product instanceof \WC_Product ? $product : null;
	}

	/** Normalized product data, memoized per request. */
	private function data( \WC_Product $product ): array {
		$id = $product->get_id();
		if ( isset( $this->cache[ $id ] ) ) {
			return $this->cache[ $id ];
		}

		$s   = self::settings();
		$fmt = static fn( $v ): string => ( null === $v || '' === $v ) ? '' : (string) wc_format_decimal( $v, wc_get_price_decimals() );

		$d = [
			'type'          => $product->get_type(),
			'currency'      => get_woocommerce_currency(),
			'price'         => '',
			'price_max'     => '',
			'regular_price' => '',
			'on_sale'       => $product->is_on_sale(),
			'availability'  => self::schema_availability( $product->get_stock_status() ),
			'stock_label'   => self::stock_label( $product->get_stock_status() ),
			'sku'           => (string) $product->get_sku(),
			'gtin'          => method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '',
			'brands'        => $this->brands( $id ),
			'attributes'    => $this->attributes( $product ),
			'weight'        => $product->has_weight() ? $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' ) : '',
			'dimensions'    => $product->has_dimensions() ? Yoast_Bridge::clean_text( wc_format_dimensions( $product->get_dimensions( false ) ) ) : '',
			'rating'        => null,
			'review_count'  => 0,
			'short'         => (string) apply_filters( 'woocommerce_short_description', $product->get_short_description() ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			'external_url'  => '',
			'external_text' => '',
			'variations'    => [],
			'variation_total' => 0,
		];

		if ( $product->is_type( 'variable' ) ) {
			/** @var \WC_Product_Variable $product */
			$min              = $product->get_variation_price( 'min', true );
			$max              = $product->get_variation_price( 'max', true );
			$d['price']       = $fmt( $min );
			$d['price_max']   = ( $max !== $min ) ? $fmt( $max ) : '';
			$children         = $product->get_visible_children();
			$d['variation_total'] = count( $children );

			if ( $s['include_variations'] ) {
				foreach ( array_slice( $children, 0, (int) $s['max_variations'] ) as $child_id ) {
					$v = wc_get_product( $child_id );
					if ( ! $v instanceof \WC_Product_Variation ) {
						continue;
					}
					$d['variations'][] = [
						'name'         => Yoast_Bridge::clean_text( wc_get_formatted_variation( $v, true, true, false ) ),
						'price'        => $fmt( wc_get_price_to_display( $v ) ),
						'availability' => self::stock_label( $v->get_stock_status() ),
						'sku'          => (string) $v->get_sku(),
					];
				}
			}
		} else {
			$d['price'] = $fmt( '' === $product->get_price() ? '' : wc_get_price_to_display( $product ) );
			if ( $product->is_on_sale() && '' !== $product->get_regular_price() ) {
				$d['regular_price'] = $fmt( wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] ) );
			}
		}

		if ( $s['include_rating'] && function_exists( 'wc_review_ratings_enabled' ) && wc_review_ratings_enabled() && $product->get_review_count() > 0 ) {
			$d['rating']       = round( (float) $product->get_average_rating(), 2 );
			$d['review_count'] = (int) $product->get_review_count();
		}

		if ( $product instanceof \WC_Product_External ) {
			$d['external_url']  = (string) $product->get_product_url();
			$d['external_text'] = (string) $product->get_button_text();
		}

		/**
		 * Filters normalized product data used for Markdown and frontmatter.
		 *
		 * @param array       $d
		 * @param \WC_Product $product
		 */
		return $this->cache[ $id ] = (array) apply_filters( 'crawl_ledger_wc_product_data', $d, $product );
	}

	private function brands( int $product_id ): array {
		foreach ( self::BRAND_TAXONOMIES as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $product_id, $tax );
				if ( is_array( $terms ) && $terms ) {
					return array_values( array_map( static fn( $t ) => Yoast_Bridge::clean_text( $t->name ), $terms ) );
				}
			}
		}
		return [];
	}

	/** @return array<int, array{name:string, value:string}> visible attributes only */
	private function attributes( \WC_Product $product ): array {
		$out = [];
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr instanceof \WC_Product_Attribute || ! $attr->get_visible() ) {
				continue;
			}
			$values = $attr->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
				: $attr->get_options();
			$values = array_filter( array_map( static fn( $v ) => Yoast_Bridge::clean_text( (string) $v ), (array) $values ) );
			if ( $values ) {
				$out[] = [
					'name'  => Yoast_Bridge::clean_text( wc_attribute_label( $attr->get_name(), $product ) ),
					'value' => implode( ', ', $values ),
				];
			}
		}
		return $out;
	}

	private static function schema_availability( string $status ): string {
		return match ( $status ) {
			'instock'     => 'InStock',
			'outofstock'  => 'OutOfStock',
			'onbackorder' => 'BackOrder',
			default       => $status,
		};
	}

	/** Uses WooCommerce's own translations, so labels follow the shop language. */
	private static function stock_label( string $status ): string {
		return match ( $status ) {
			'instock'     => self::wc__( 'In stock' ),
			'outofstock'  => self::wc__( 'Out of stock' ),
			'onbackorder' => self::wc__( 'On backorder' ),
			default       => $status,
		};
	}

	private static function wc__( string $text ): string {
		return translate( $text, 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n
	}

	private function price_text( array $d ): string {
		if ( '' === $d['price'] ) {
			return '';
		}
		$text = $d['price'] . ( '' !== $d['price_max'] ? '–' . $d['price_max'] : '' ) . ' ' . $d['currency'];
		if ( '' !== $d['regular_price'] ) {
			$text .= ' (' . self::wc__( 'Regular price' ) . ': ' . $d['regular_price'] . ' ' . $d['currency'] . ')';
		}
		return $text;
	}

	/* ---------------------------------------------------------------------
	 * Core filters
	 * ------------------------------------------------------------------- */

	/** Prepends a product summary (converted to Markdown by the core parser). */
	public function content_html( string $html, \WP_Post $post ): string {
		$product = $this->product( $post );
		if ( ! $product ) {
			return $html;
		}

		$d     = $this->data( $product );
		$parts = [];

		if ( '' !== trim( wp_strip_all_tags( $d['short'] ) ) ) {
			$parts[] = $d['short'];
		}

		$details = [];
		$price   = $this->price_text( $d );
		if ( '' !== $price ) {
			$details[ self::wc__( 'Price' ) ] = $price;
		}
		$details[ self::wc__( 'Stock' ) ] = $d['stock_label'];
		if ( '' !== $d['sku'] ) {
			$details[ self::wc__( 'SKU' ) ] = $d['sku'];
		}
		if ( '' !== $d['gtin'] ) {
			$details['GTIN'] = $d['gtin'];
		}
		if ( $d['brands'] ) {
			$details[ self::wc__( 'Brand' ) ] = implode( ', ', $d['brands'] );
		}
		if ( null !== $d['rating'] ) {
			$details[ self::wc__( 'Rating' ) ] = $d['rating'] . '/5 (' . $d['review_count'] . ')';
		}

		$list = '';
		foreach ( $details as $label => $value ) {
			$list .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
		}
		if ( '' !== $d['external_url'] ) {
			$list .= '<li><a href="' . esc_url( $d['external_url'] ) . '">' . esc_html( '' !== $d['external_text'] ? $d['external_text'] : self::wc__( 'Buy product' ) ) . '</a></li>';
		}
		$parts[] = '<ul>' . $list . '</ul>';

		// Additional information (attributes, weight, dimensions).
		$info = $d['attributes'];
		if ( '' !== $d['weight'] ) {
			$info[] = [ 'name' => self::wc__( 'Weight' ), 'value' => $d['weight'] ];
		}
		if ( '' !== $d['dimensions'] ) {
			$info[] = [ 'name' => self::wc__( 'Dimensions' ), 'value' => $d['dimensions'] ];
		}
		if ( $info ) {
			$items = '';
			foreach ( $info as $row ) {
				$items .= '<li><strong>' . esc_html( $row['name'] ) . ':</strong> ' . esc_html( $row['value'] ) . '</li>';
			}
			$parts[] = '<h2>' . esc_html( self::wc__( 'Additional information' ) ) . '</h2><ul>' . $items . '</ul>';
		}

		// Variants table.
		if ( $d['variations'] ) {
			$rows = '';
			foreach ( $d['variations'] as $v ) {
				$rows .= '<tr><td>' . esc_html( $v['name'] ) . '</td><td>' . esc_html( '' !== $v['price'] ? $v['price'] . ' ' . $d['currency'] : '—' ) . '</td><td>' . esc_html( $v['availability'] ) . '</td><td>' . esc_html( $v['sku'] ) . '</td></tr>';
			}
			$more = $d['variation_total'] > count( $d['variations'] )
				? '<p>' . esc_html( sprintf( '+%d', $d['variation_total'] - count( $d['variations'] ) ) ) . '</p>'
				: '';
			$parts[] = '<h2>' . esc_html( self::wc__( 'Variations' ) ) . '</h2><table><thead><tr><th>' . esc_html( self::wc__( 'Variation' ) ) . '</th><th>' . esc_html( self::wc__( 'Price' ) ) . '</th><th>' . esc_html( self::wc__( 'Stock' ) ) . '</th><th>' . esc_html( self::wc__( 'SKU' ) ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>' . $more;
		}

		if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
			$parts[] = '<h2>' . esc_html( self::wc__( 'Description' ) ) . '</h2>' . $html;
		}

		return implode( "\n", $parts );
	}

	public function seo_data( array $d, \WP_Post $post ): array {
		$product = $this->product( $post );
		if ( ! $product ) {
			return $d;
		}

		if ( ! str_contains( (string) $d['type'], 'Product' ) ) {
			$d['type'] = 'Product';
		}

		$have = array_map( static fn( $e ) => strtolower( $e['type'] . '|' . $e['name'] ), (array) $d['entities'] );
		$add  = [ [ 'type' => 'Product', 'name' => Yoast_Bridge::clean_text( $product->get_name() ) ] ];
		foreach ( $this->data( $product )['brands'] as $brand ) {
			$add[] = [ 'type' => 'Brand', 'name' => $brand ];
		}
		foreach ( $add as $entity ) {
			if ( '' !== $entity['name'] && ! in_array( strtolower( $entity['type'] . '|' . $entity['name'] ), $have, true ) ) {
				array_unshift( $d['entities'], $entity );
			}
		}

		return $d;
	}

	public function frontmatter( array $fields, \WP_Post $post ): array {
		$product = $this->product( $post );
		if ( ! $product ) {
			return $fields;
		}

		$d   = $this->data( $product );
		$new = [
			'product_type'       => $d['type'],
			'price'              => $d['price'],
			'price_max'          => $d['price_max'],
			'regular_price'      => $d['regular_price'],
			'currency'           => $d['currency'],
			'price_includes_tax' => wc_tax_enabled() ? 'incl' === get_option( 'woocommerce_tax_display_shop' ) : null,
			'on_sale'            => $d['on_sale'] ? true : null,
			'availability'       => $d['availability'],
			'sku'                => $d['sku'],
			'gtin'               => $d['gtin'],
			'brand'              => $d['brands'],
			'rating'             => $d['rating'],
			'review_count'       => $d['review_count'] ?: null,
			'attributes'         => $d['attributes'],
			'variations'         => $d['variation_total'] ?: null,
			'purchase_url'       => $d['external_url'],
		];
		$new = array_filter( $new, static fn( $v ) => null !== $v && '' !== $v && [] !== $v );

		// Insert right after "type".
		$out = [];
		foreach ( $fields as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'type' === $key ) {
				$out += $new;
			}
		}
		return array_key_exists( 'product_type', $out ) ? $out : $out + $new;
	}

	/** Price/stock live outside post_modified — include them so documents never go stale. */
	public function cache_key( array $parts, \WP_Post $post ): array {
		$product = $this->product( $post );
		if ( ! $product ) {
			return $parts;
		}

		$volatile = [ $product->get_price(), $product->get_stock_status(), $product->get_stock_quantity(), $product->is_on_sale(), CRAWL_LEDGER_WC_VERSION ];
		if ( $product->is_type( 'variable' ) ) {
			$volatile[] = wp_json_encode( $product->get_variation_prices( true ) );
		}
		$parts[] = md5( (string) wp_json_encode( $volatile ) );

		return $parts;
	}

	public function llms_line( string $line, \WP_Post $post ): string {
		if ( ! self::settings()['llms_price'] ) {
			return $line;
		}
		$product = $this->product( $post );
		if ( ! $product ) {
			return $line;
		}
		$d     = $this->data( $product );
		$price = $this->price_text( $d );
		return $line . ' — ' . ( '' !== $price ? $price . ' · ' : '' ) . $d['stock_label'];
	}

	/* ---------------------------------------------------------------------
	 * Analytics
	 * ------------------------------------------------------------------- */

	public function report( array $out, int $days ): array {
		global $wpdb;

		if ( isset( $out['error'] ) || ! Analytics::table_exists() ) {
			return $out;
		}

		$hits  = Analytics::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - min( 365, max( 1, $days ) ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.post_id, COUNT(*) AS hits,
					SUM(h.category LIKE 'ai-%%') AS ai_hits,
					SUM(h.category = 'ai-user') AS ai_user_hits,
					SUM(h.endpoint IN ('md','query','negotiated')) AS markdown,
					MAX(CASE WHEN h.category LIKE 'ai-%%' THEN h.hit_at END) AS last_ai_hit
				FROM {$hits} h
				INNER JOIN {$wpdb->posts} p ON p.ID = h.post_id AND p.post_type = 'product'
				WHERE h.hit_at >= %s
				GROUP BY h.post_id
				ORDER BY ai_hits DESC, hits DESC
				LIMIT 25",
				$since
			),
			ARRAY_A
		);

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS hits, SUM(h.category LIKE 'ai-%%') AS ai_hits, SUM(h.category = 'ai-user') AS ai_user_hits,
					COUNT(DISTINCT CASE WHEN h.category LIKE 'ai-%%' THEN h.post_id END) AS products_fetched_by_ai
				FROM {$hits} h
				INNER JOIN {$wpdb->posts} p ON p.ID = h.post_id AND p.post_type = 'product'
				WHERE h.hit_at >= %s",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		$products = [];
		foreach ( (array) $rows as $row ) {
			$id      = (int) $row['post_id'];
			$product = wc_get_product( $id );
			$d       = $product ? $this->data( $product ) : null;

			$products[] = [
				'post_id'      => $id,
				'title'        => Yoast_Bridge::clean_text( get_the_title( $id ) ),
				'url'          => (string) get_permalink( $id ),
				'markdown_url' => Plugin::markdown_url( $id ),
				'price'        => $d ? $this->price_text( $d ) : '',
				'availability' => $d ? $d['availability'] : '',
				'hits'         => (int) $row['hits'],
				'ai_hits'      => (int) $row['ai_hits'],
				'ai_user_hits' => (int) $row['ai_user_hits'],
				'markdown'     => (int) $row['markdown'],
				'last_ai_hit'  => $row['last_ai_hit'] ? gmdate( 'c', strtotime( $row['last_ai_hit'] . ' UTC' ) ) : null,
			];
		}

		$out['woocommerce'] = [
			'totals'   => array_map( 'intval', (array) $totals ),
			'products' => $products,
		];

		return $out;
	}

	public function render_analytics( array $report, int $days ): void {
		if ( ! isset( $report['woocommerce'] ) ) {
			return;
		}
		$wc = $report['woocommerce'];
		$t  = $wc['totals'];
		?>
		<h2><?php esc_html_e( 'WooCommerce: products fetched by bots', 'crawl-ledger-woocommerce' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: 1: AI hits, 2: live assistant hits, 3: products */
				esc_html__( 'AI bot hits on products: %1$s · live assistant fetches (ChatGPT-User, Claude-User, Perplexity-User…): %2$s · products read by AI: %3$s', 'crawl-ledger-woocommerce' ),
				'<strong>' . esc_html( number_format_i18n( $t['ai_hits'] ?? 0 ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $t['ai_user_hits'] ?? 0 ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $t['products_fetched_by_ai'] ?? 0 ) ) . '</strong>'
			);
			?>
		</p>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'crawl-ledger-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Price', 'crawl-ledger-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Availability', 'crawl-ledger-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'AI hits', 'crawl-ledger-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Live assistant', 'crawl-ledger-woocommerce' ); ?></th>
					<th>Markdown</th>
					<th><?php esc_html_e( 'Last AI hit (UTC)', 'crawl-ledger-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $wc['products'] ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No product hits yet.', 'crawl-ledger-woocommerce' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $wc['products'] as $p ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $p['title'] ? $p['title'] : '#' . $p['post_id'] ); ?></a> · <a href="<?php echo esc_url( $p['markdown_url'] ); ?>" target="_blank" rel="noopener">.md</a></td>
					<td><?php echo esc_html( $p['price'] ); ?></td>
					<td><?php echo esc_html( $p['availability'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $p['ai_hits'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $p['ai_user_hits'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $p['markdown'] ) ); ?></td>
					<td><?php echo esc_html( $p['last_ai_hit'] ? str_replace( [ 'T', '+00:00' ], [ ' ', '' ], $p['last_ai_hit'] ) : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings UI (inside the core settings form)
	 * ------------------------------------------------------------------- */

	public function render_settings( array $core ): void {
		$s    = self::settings();
		$name = self::OPTION;
		?>
		<h2>WooCommerce</h2>
		<?php if ( ! in_array( 'product', (array) ( $core['post_types'] ?? [] ), true ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Tick "Products" in Post types above, otherwise product pages are not exposed as Markdown.', 'crawl-ledger-woocommerce' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<?php
			$checks = [
				'exclude_out_of_stock' => __( 'Hide out-of-stock products from AI agents (.md and llms.txt)', 'crawl-ledger-woocommerce' ),
				'include_variations'   => __( 'Include a variations table (attributes, price, stock, SKU)', 'crawl-ledger-woocommerce' ),
				'include_rating'       => __( 'Include average rating and review count', 'crawl-ledger-woocommerce' ),
				'llms_price'           => __( 'Show price and stock next to products in llms.txt', 'crawl-ledger-woocommerce' ),
			];
			foreach ( $checks as $key => $label ) :
				?>
				<tr>
					<th scope="row"></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="clwc-max"><?php esc_html_e( 'Max variations per product', 'crawl-ledger-woocommerce' ); ?></label></th>
				<td><input id="clwc-max" type="number" min="1" max="500" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_variations]" value="<?php echo esc_attr( (string) $s['max_variations'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td><p class="description"><?php esc_html_e( 'Cart, Checkout and My account pages are always excluded. Products hidden from the catalog are never exposed.', 'crawl-ledger-woocommerce' ); ?></p></td>
			</tr>
		</table>
		<?php
	}
}
