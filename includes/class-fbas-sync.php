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
	 * A szinkronra kijelölt termékek végigfuttatása (cron / "Szinkronizálás most" gomb).
	 *
	 * Optimalizálás: nem az ÖSSZES kijelölt terméket dolgozza fel egy menetben
	 * (ami sok termék esetén PHP/HTTP időtúllépéshez vezethet), hanem egy
	 * kötegben (batch_size beállítás, alapértelmezetten 20) - a legrégebben
	 * szinkronizált / még soha nem szinkronizált termékeket előnyben részesítve.
	 * A 15 percenkénti cron így fokozatosan, rotálva dolgozza fel a teljes listát.
	 */
	public function run_full_sync() {
		if ( ! FBAS_Api_Client::instance()->is_connected() ) {
			FBAS_Logger::log( 'A szinkron kimaradt: a plugin nincs összekötve az Allegro fiókkal.', 'warning' );
			return;
		}

		$batch_size  = max( 1, (int) FBAS_Settings::get( 'batch_size', 20 ) );
		$product_ids = FBAS_Product_Mapper::get_products_marked_for_sync( $batch_size );

		if ( empty( $product_ids ) ) {
			FBAS_Logger::log( 'Nincs szinkronra kijelölt termék.', 'info' );
			return;
		}

		FBAS_Logger::log( sprintf( 'Szinkron köteg indul: %d termék (max %d/futás).', count( $product_ids ), $batch_size ), 'info' );

		foreach ( $product_ids as $product_id ) {
			$this->sync_product( $product_id );
		}

		FBAS_Logger::log( 'Szinkron köteg befejezve.', 'success' );
	}

	/**
	 * A termék képeit felölti az Allegro szerverére (ha még nincs feltöltve /
	 * ha megváltozott a kép URL), és visszaadja az Allegro-n tárolt URL-ek listáját.
	 * A helyi URL -> Allegro URL párokat postmeta-ban cache-eljük, hogy ne kelljen
	 * minden szinkronnál újra feltölteni a változatlan képeket.
	 *
	 * @return string[]
	 */
	private function resolve_image_urls( $product_id, WC_Product $product ) {
		$local_urls = FBAS_Product_Mapper::get_local_image_urls( $product );
		$cache      = get_post_meta( $product_id, FBAS_Product_Mapper::META_IMAGE_MAP, true );
		$cache      = is_array( $cache ) ? $cache : array();

		$client       = FBAS_Api_Client::instance();
		$hosted_urls  = array();
		$cache_dirty  = false;

		foreach ( $local_urls as $local_url ) {
			if ( ! empty( $cache[ $local_url ] ) ) {
				$hosted_urls[] = $cache[ $local_url ];
				continue;
			}

			$uploaded = $client->upload_image_from_url( $local_url );

			if ( is_wp_error( $uploaded ) ) {
				FBAS_Logger::log( sprintf( 'Kép feltöltés sikertelen (%s): %s', $local_url, $uploaded->get_error_message() ), 'error' );
				continue; // A hibás képet kihagyjuk, a többivel folytatjuk.
			}

			$cache[ $local_url ] = $uploaded;
			$cache_dirty          = true;
			$hosted_urls[]        = $uploaded;
		}

		if ( $cache_dirty ) {
			update_post_meta( $product_id, FBAS_Product_Mapper::META_IMAGE_MAP, $cache );
		}

		return $hosted_urls;
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
			return new WP_Error( 'fbas_invalid_product', __( 'Érvénytelen termék.', 'wp-allegro-sync' ) );
		}

		$client   = FBAS_Api_Client::instance();
		$offer_id = FBAS_Product_Mapper::get_offer_id( $product_id );

		if ( $offer_id && $price_stock_only ) {
			// Ár/készlet gyorsfrissítéshez nincs szükség a képek újra-feltöltésére.
			$patch  = FBAS_Product_Mapper::build_price_stock_patch( $product );
			$result = $client->patch( '/sale/product-offers/' . $offer_id, $patch );
		} else {
			$hosted_image_urls = $this->resolve_image_urls( $product_id, $product );

			if ( empty( $hosted_image_urls ) ) {
				FBAS_Product_Mapper::mark_synced( $product_id, 'error', __( 'Nincs feltölthető kép a termékhez - az Allegro-nak legalább 1 kép kötelező.', 'wp-allegro-sync' ) );
				FBAS_Logger::log( sprintf( '"%s" szinkron hiba: nincs kép.', $product->get_name() ), 'error' );
				return new WP_Error( 'fbas_no_images', __( 'A terméknek nincs (feltölthető) képe, az Allegro-hoz legalább 1 kép kötelező.', 'wp-allegro-sync' ) );
			}

			// Debug: pontosan milyen kép URL-eket próbálunk elküldeni - ha az Allegro
			// mégis "hiányzó kép" hibát ad, ebből látszik, hogy a mi oldalunkon volt-e
			// üres a lista, vagy az Allegro utasította el az egyébként elküldött URL-t.
			FBAS_Logger::log( sprintf( '"%s" szinkron: %d feltöltött kép URL kerül elküldésre.', $product->get_name(), count( $hosted_image_urls ) ), 'info', array( 'image_urls' => $hosted_image_urls ) );

			$payload = FBAS_Product_Mapper::build_offer_payload( $product, $hosted_image_urls );

			if ( $offer_id ) {
				// A modern /sale/product-offers végponton a frissítés is PATCH (nem PUT).
				$result = $client->patch( '/sale/product-offers/' . $offer_id, $payload );
			} else {
				// Egyetlen kéréssel létrejön ÉS publikálódik is az ajánlat (publication.status = ACTIVE a payloadban),
				// nincs többé szükség külön "publikálás" API hívásra, mint a régi /sale/offers végpontnál.
				$result = $client->post( '/sale/product-offers', $payload );

				if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
					FBAS_Product_Mapper::set_offer_id( $product_id, $result['id'] );
					$offer_id = $result['id'];
				}
			}

			// Ha a szinkron sikertelen volt, a feltöltött kép-referenciák érvénytelenné
			// válhattak az Allegro oldalán (pl. mert az ajánlat sosem jött létre) - a
			// cache-t töröljük, hogy a KÖVETKEZŐ próbálkozás garantáltan friss képet
			// töltsön fel, ne egy esetleg már lejárt/érvénytelen URL-t használjon újra.
			if ( is_wp_error( $result ) ) {
				delete_post_meta( $product_id, FBAS_Product_Mapper::META_IMAGE_MAP );
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
	 * Termék "eltávolítása" az Allegro-ról - az ajánlat lezárása.
	 *
	 * FONTOS: a DELETE /sale/offers/{offerId} végpont is le lett tiltva a
	 * /sale/offers eltávolításával együtt (403 ACCESS_DENIED-et ad).
	 * A jelenleg támogatott mód egy aktív ajánlat befejezésére a
	 * PATCH /sale/product-offers/{offerId} hívás publication.status = "ENDED"
	 * értékkel.
	 */
	public function remove_offer( $product_id ) {
		$offer_id = FBAS_Product_Mapper::get_offer_id( $product_id );
		if ( ! $offer_id ) {
			return new WP_Error( 'fbas_no_offer', __( 'Ehhez a termékhez nincs Allegro ajánlat.', 'wp-allegro-sync' ) );
		}

		$client = FBAS_Api_Client::instance();
		$result = $client->patch( '/sale/product-offers/' . $offer_id, array(
			'publication' => array( 'status' => 'ENDED' ),
		) );

		if ( ! is_wp_error( $result ) ) {
			FBAS_Product_Mapper::set_offer_id( $product_id, '' );
			FBAS_Product_Mapper::mark_synced( $product_id, 'removed' );
		}

		return $result;
	}
}
