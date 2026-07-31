<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Termékek szinkronizálása az Allegro-val:
 * - létrehozás (ha még nincs Allegro offer ID a termékhez)
 * - frissítés (ár, készlet, ha már létezik az ajánlat)
 * - cron job a rendszeres futtatáshoz
 * - WooCommerce hook, ami azonnal szinkronizál mentéskor / készletváltozáskor
 */
class FBAS_Sync {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'fbas_cron_sync', array( $this, 'run_full_sync' ) );

		// Azonnali szinkron mentés / készletváltozás esetén, ha be van kapcsolva az automatikus mód.
		add_action( 'woocommerce_update_product', array( $this, 'maybe_sync_single_on_save' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'maybe_sync_single_on_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'maybe_sync_single_on_stock_change' ) );
	}

	public function maybe_sync_single_on_save( $product_id ) {
		if ( 'yes' !== FBAS_Settings::get( 'auto_sync_enabled' ) ) {
			return;
		}
		if ( ! FBAS_Product_Mapper::is_enabled_for_sync( $product_id ) ) {
			return;
		}
		$this->sync_product( $product_id );
	}

	public function maybe_sync_single_on_stock_change( $product ) {
		if ( 'yes' !== FBAS_Settings::get( 'auto_sync_enabled' ) ) {
			return;
		}
		$product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
		if ( ! FBAS_Product_Mapper::is_enabled_for_sync( $product_id ) ) {
			return;
		}
		$this->sync_product( $product_id, true );
	}

	/**
	 * Az összes, szinkronra kijelölt termék végigfuttatása (cron / "Szinkronizálás most" gomb).
	 */
	public function run_full_sync() {
		if ( ! FBAS_Api_Client::instance()->is_connected() ) {
			FBAS_Logger::log( 'A szinkron kimaradt: a plugin nincs összekötve az Allegro fiókkal.', 'warning' );
			return;
		}

		$product_ids = FBAS_Product_Mapper::get_products_marked_for_sync();

		if ( empty( $product_ids ) ) {
			FBAS_Logger::log( 'Nincs szinkronra kijelölt termék.', 'info' );
			return;
		}

		FBAS_Logger::log( sprintf( 'Szinkron indul: %d termék.', count( $product_ids ) ), 'info' );

		foreach ( $product_ids as $product_id ) {
			$this->sync_product( $product_id );
		}

		FBAS_Logger::log( 'Szinkron befejezve.', 'success' );
	}

	/**
	 * Egyetlen termék szinkronizálása.
	 *
	 * @param int  $product_id
	 * @param bool $price_stock_only Csak ár/készlet gyors frissítés (ha már van offer ID).
	 */
	public function sync_product( $product_id, $price_stock_only = false ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'fbas_invalid_product', __( 'Érvénytelen termék.', 'fb-allegro-sync' ) );
		}

		$client   = FBAS_Api_Client::instance();
		$offer_id = FBAS_Product_Mapper::get_offer_id( $product_id );

		if ( $offer_id ) {
			if ( $price_stock_only ) {
				$patch = FBAS_Product_Mapper::build_price_stock_patch( $product );
				$result = $client->patch( '/sale/offers/' . $offer_id, $patch );
			} else {
				$payload = FBAS_Product_Mapper::build_offer_payload( $product );
				$result  = $client->put( '/sale/offers/' . $offer_id, $payload );
			}
		} else {
			$payload = FBAS_Product_Mapper::build_offer_payload( $product );
			$result  = $client->post( '/sale/offers', $payload );

			if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
				FBAS_Product_Mapper::set_offer_id( $product_id, $result['id'] );
				$offer_id = $result['id'];
				$this->publish_offer( $offer_id );
			}
		}

		if ( is_wp_error( $result ) ) {
			FBAS_Product_Mapper::mark_synced( $product_id, 'error', $result->get_error_message() );
			FBAS_Logger::log( sprintf( '"%s" szinkron hiba: %s', $product->get_name(), $result->get_error_message() ), 'error' );
			return $result;
		}

		FBAS_Product_Mapper::mark_synced( $product_id, 'success' );
		FBAS_Logger::log( sprintf( '"%s" sikeresen szinkronizálva (offer: %s).', $product->get_name(), $offer_id ), 'success' );

		return $result;
	}

	/**
	 * Új ajánlat publikálása (draft -> ACTIVE) a publikációs parancs végponton keresztül.
	 */
	private function publish_offer( $offer_id ) {
		$client = FBAS_Api_Client::instance();
		$command_id = wp_generate_uuid4();

		$result = $client->put( '/sale/offer-publication-commands/' . $command_id, array(
			'publication' => array( 'action' => 'ACTIVATE' ),
			'offerCriteria' => array(
				array(
					'offers' => array( array( 'id' => $offer_id ) ),
					'type'   => 'CONTAINS_OFFERS',
				),
			),
		) );

		if ( is_wp_error( $result ) ) {
			FBAS_Logger::log( 'Ajánlat publikálása sikertelen (offer: ' . $offer_id . '): ' . $result->get_error_message(), 'error' );
		}

		return $result;
	}

	/**
	 * Termék törlése az Allegro-ról (ajánlat befejezése).
	 */
	public function remove_offer( $product_id ) {
		$offer_id = FBAS_Product_Mapper::get_offer_id( $product_id );
		if ( ! $offer_id ) {
			return new WP_Error( 'fbas_no_offer', __( 'Ehhez a termékhez nincs Allegro ajánlat.', 'fb-allegro-sync' ) );
		}

		$client = FBAS_Api_Client::instance();
		$result = $client->delete( '/sale/offers/' . $offer_id );

		if ( ! is_wp_error( $result ) ) {
			FBAS_Product_Mapper::set_offer_id( $product_id, '' );
			FBAS_Product_Mapper::mark_synced( $product_id, 'removed' );
		}

		return $result;
	}
}
