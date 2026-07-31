<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce termék adatainak leképezése Allegro ajánlat (offer) JSON-re,
 * illetve a szinkronhoz szükséges post meta mezők kezelése.
 */
class FBAS_Product_Mapper {

	const META_SYNC_ENABLED = '_fbas_sync_enabled';
	const META_OFFER_ID     = '_fbas_allegro_offer_id';
	const META_LAST_SYNC    = '_fbas_last_sync';
	const META_LAST_STATUS  = '_fbas_last_status';
	const META_LAST_ERROR   = '_fbas_last_error';

	public static function is_enabled_for_sync( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_SYNC_ENABLED, true );
	}

	public static function set_sync_enabled( $product_id, $enabled ) {
		update_post_meta( $product_id, self::META_SYNC_ENABLED, $enabled ? 'yes' : 'no' );
	}

	public static function get_offer_id( $product_id ) {
		return get_post_meta( $product_id, self::META_OFFER_ID, true );
	}

	public static function set_offer_id( $product_id, $offer_id ) {
		update_post_meta( $product_id, self::META_OFFER_ID, $offer_id );
	}

	public static function mark_synced( $product_id, $status, $error = '' ) {
		update_post_meta( $product_id, self::META_LAST_SYNC, current_time( 'mysql' ) );
		update_post_meta( $product_id, self::META_LAST_STATUS, $status );
		update_post_meta( $product_id, self::META_LAST_ERROR, $error );
	}

	public static function get_products_marked_for_sync() {
		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => self::META_SYNC_ENABLED,
					'value' => 'yes',
				),
			),
		) );

		return $query->posts;
	}

	/**
	 * Ár kiszámítása a beállított felárral (%), Allegro bruttó ár formátumban.
	 */
	public static function calculate_price( WC_Product $product ) {
		$base   = (float) ( $product->get_price() ?: 0 );
		$markup = (float) FBAS_Settings::get( 'price_markup_percent', 0 );
		$price  = $base + ( $base * ( $markup / 100 ) );

		return number_format( $price, 2, '.', '' );
	}

	/**
	 * Allegro "sale/offers" POST/PUT törzs összeállítása a termékből.
	 *
	 * Megjegyzés: az Allegro kategória-specifikus paramétereket
	 * (pl. márka, méret stb.) a kategória "requiredForOffer" paraméterei
	 * alapján kellene automatikusan feltölteni - ez a plugin ehhez a
	 * `fbas_offer_parameters` szűrőn keresztül biztosít bővítési pontot,
	 * mivel Allegro kategóriánként eltérő kötelező mezőket kér.
	 */
	public static function build_offer_payload( WC_Product $product ) {
		$settings = FBAS_Settings::get_all();

		$images = array();
		$image_ids = $product->get_gallery_image_ids();
		array_unshift( $image_ids, $product->get_image_id() );
		foreach ( array_filter( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $url ) {
				$images[] = array( 'url' => $url );
			}
		}

		$description_html = wpautop( $product->get_description() ?: $product->get_short_description() );

		$stock_qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : 1;
		if ( ! $product->is_in_stock() ) {
			$stock_qty = 0;
		}

		$payload = array(
			'name'     => $product->get_name(),
			'category' => array(
				'id' => (string) $settings['offer_category_id'],
			),
			'parameters' => apply_filters( 'fbas_offer_parameters', array(), $product ),
			'images'     => $images,
			'description' => array(
				'sections' => array(
					array(
						'items' => array(
							array(
								'type'    => 'TEXT',
								'content' => $description_html ?: '<p>' . esc_html( $product->get_name() ) . '</p>',
							),
						),
					),
				),
			),
			'sellingMode' => array(
				'format' => 'BUY_NOW',
				'price'  => array(
					'amount'   => self::calculate_price( $product ),
					'currency' => get_woocommerce_currency(),
				),
			),
			'stock' => array(
				'available' => $stock_qty,
				'unit'      => 'UNIT',
			),
			'publication' => array(
				'duration' => 'PT0S', // "amíg el nem fogy" jellegű, végtelenített ajánlat
				'status'   => 'INACTIVE', // draft - a publikálás külön lépés (lásd FBAS_Api_Client + publish command)
			),
			'delivery' => array(
				'shippingRates' => array(
					'id' => (string) $settings['default_delivery_id'],
				),
			),
			'warranty'  => $settings['default_warranty_id'] ? array( 'id' => (string) $settings['default_warranty_id'] ) : null,
			'returnPolicy' => $settings['default_return_id'] ? array( 'id' => (string) $settings['default_return_id'] ) : null,
			'external' => array(
				'id' => (string) $product->get_id(), // saját azonosító - visszakereshetőség
			),
		);

		// Null értékű kulcsok eltávolítása (Allegro API nem szereti az explicit null mezőket mindenhol).
		return array_filter( $payload, function ( $value ) {
			return null !== $value;
		} );
	}

	/**
	 * Csak az ár/készlet frissítéshez szükséges kisebb payload (PATCH).
	 */
	public static function build_price_stock_patch( WC_Product $product ) {
		$stock_qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : 1;
		if ( ! $product->is_in_stock() ) {
			$stock_qty = 0;
		}

		$patch = array();

		if ( 'yes' === FBAS_Settings::get( 'sync_price' ) ) {
			$patch['sellingMode'] = array(
				'price' => array(
					'amount'   => self::calculate_price( $product ),
					'currency' => get_woocommerce_currency(),
				),
			);
		}

		if ( 'yes' === FBAS_Settings::get( 'sync_stock' ) ) {
			$patch['stock'] = array(
				'available' => $stock_qty,
				'unit'      => 'UNIT',
			);
		}

		return $patch;
	}
}
