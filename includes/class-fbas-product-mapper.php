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
	const META_IMAGE_MAP    = '_fbas_allegro_image_map';

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

	/**
	 * Szinkronra kijelölt termék ID-k lekérdezése, opcionálisan darabszám-korlátozással.
	 *
	 * Nagyobb termékkatalógusnál egy 15 percenkénti cron futás alatt nem biztos,
	 * hogy minden termék lefut időtúllépés nélkül - ezért $limit megadása esetén
	 * a legrégebben szinkronizált (vagy még soha nem szinkronizált) termékeket
	 * részesítjük előnyben, hogy a rotáció idővel mindenkit lefedjen.
	 *
	 * @param int $limit -1 = nincs korlát (pl. admin termék lista nézet), egyébként darabszám.
	 * @return int[]
	 */
	public static function get_products_marked_for_sync( $limit = -1 ) {
		$base_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => self::META_SYNC_ENABLED,
					'value' => 'yes',
				),
			),
		);

		if ( -1 === $limit ) {
			$query = new WP_Query( array_merge( $base_args, array( 'posts_per_page' => -1 ) ) );
			return $query->posts;
		}

		// 1. lépés: még soha nem szinkronizált termékek (nincs META_LAST_SYNC) - ők az elsők.
		$never_synced_args = $base_args;
		$never_synced_args['posts_per_page'] = $limit;
		$never_synced_args['meta_query'][] = array(
			'key'     => self::META_LAST_SYNC,
			'compare' => 'NOT EXISTS',
		);
		$never_synced_query = new WP_Query( $never_synced_args );
		$ids = $never_synced_query->posts;

		$remaining = $limit - count( $ids );
		if ( $remaining <= 0 ) {
			return $ids;
		}

		// 2. lépés: a maradék helyre a legrégebben szinkronizált termékek jönnek.
		$oldest_synced_args = $base_args;
		$oldest_synced_args['posts_per_page'] = $remaining;
		$oldest_synced_args['orderby']  = 'meta_value';
		$oldest_synced_args['order']    = 'ASC';
		$oldest_synced_args['meta_key'] = self::META_LAST_SYNC;
		$oldest_synced_args['post__not_in'] = $ids;
		$oldest_synced_query = new WP_Query( $oldest_synced_args );

		return array_merge( $ids, $oldest_synced_query->posts );
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
	 * A termék saját (WordPress-en tárolt) kép URL-jei, feltöltési sorrendben
	 * (főkép elöl, majd galéria képek).
	 *
	 * @return string[]
	 */
	public static function get_local_image_urls( WC_Product $product ) {
		$image_ids = $product->get_gallery_image_ids();
		array_unshift( $image_ids, $product->get_image_id() );

		$urls = array();
		foreach ( array_filter( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Az Allegro ajánlat-leírás mezője (description.sections[].items[].content)
	 * NAGYON szűk HTML alkészletet enged - a valóságban tapasztaltak szerint
	 * gyakorlatilag csak <p> és <b> (a <br> kifejezetten TILOS, "Invalid tag:
	 * br, allowed tags: {b}" hibát ad rá). A WordPress `wpautop()` viszont
	 * soronkénti töréseknél <br>-t generál - ezt itt eltávolítjuk / a
	 * kiemeléseket <b>-re alakítjuk, minden mást (span, div, img, a, ul stb.)
	 * pedig egyszerű szövegre bontunk, hogy garantáltan megfeleljen az
	 * Allegro validációjának.
	 *
	 * @param string $raw_html A WooCommerce termékleírás nyers (esetleg HTML-t
	 *                          tartalmazó) szövege.
	 * @return string Allegro-kompatibilis, csak <p>/<b> tageket tartalmazó HTML.
	 */
	public static function sanitize_offer_description( $raw_html ) {
		// Először a WordPress wpautop()-jával alakítjuk bekezdésekké / <br>-ekké
		// a nyers szöveget - ez adja az alap struktúrát, amit utána szűkítünk.
		$html = wpautop( (string) $raw_html );

		// Kiemelések egységesítése <b>-re, mielőtt mindent kiszűrnénk.
		$html = preg_replace( '/<\s*strong([^>]*)>/i', '<b>', $html );
		$html = preg_replace( '/<\s*\/\s*strong\s*>/i', '</b>', $html );

		// A wpautop() <br>-t tesz a soron belüli törésekhez - az Allegro ezt
		// nem engedi meg a leírás mezőben, ezért szóközzé alakítjuk.
		$html = preg_replace( '/<br\s*\/?>/i', ' ', $html );

		// Blokk szintű elemek (div, li, h1-6 stb.) tartalmát bekezdéssé alakítjuk,
		// mielőtt a nem engedélyezett tageket eltávolítanánk - így nem folyik
		// egybe a szöveg.
		$html = preg_replace( '/<\s*\/\s*(div|li|h[1-6]|blockquote)\s*>/i', '</p><p>', $html );

		// Csak <p> és <b> tagek maradhatnak, mindenféle attribútum nélkül
		// (az Allegro a class/style stb. attribútumokat sem engedi).
		$clean = wp_kses( $html, array(
			'p' => array(),
			'b' => array(),
		) );

		// Dupla / üres <p></p> elemek és felesleges whitespace eltávolítása.
		$clean = preg_replace( '/<p>\s*<\/p>/', '', $clean );
		$clean = preg_replace( '/\s+/', ' ', $clean );
		$clean = trim( $clean );

		return $clean;
	}

	/**
	 * Az Allegro ajánlat/termék cím karakterkorlátjához igazítja a szöveget.
	 * Az Allegro jelenlegi limitje 75 karakter (2023 óta, korábban 50 volt) -
	 * ha a WooCommerce termék neve ennél hosszabb, "Nazwa produktu nie może
	 * być dłuższa niż 75 znaków." hibát ad. Ékezetes (UTF-8) karakterekre is
	 * biztonságos (mb_ függvényeket használ), és lehetőség szerint szóhatáron
	 * vág, hogy ne csonkoljon szót félbe.
	 *
	 * @param string $title
	 * @param int    $max_length Alapértelmezetten 75 - az Allegro jelenlegi limitje.
	 * @return string
	 */
	public static function truncate_offer_title( $title, $max_length = 75 ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		if ( mb_strlen( $title ) <= $max_length ) {
			return $title;
		}

		$truncated = mb_substr( $title, 0, $max_length );

		// Ha van szóköz az utolsó ~15 karakteren belül, ott vágjuk el, hogy ne
		// csonkoljon szót félbe - de csak ha ez nem rövidíti túl agresszívan.
		$last_space = mb_strrpos( $truncated, ' ' );
		if ( false !== $last_space && $last_space >= ( $max_length - 15 ) ) {
			$truncated = mb_substr( $truncated, 0, $last_space );
		}

		return trim( $truncated );
	}

	/**
	 * Allegro "sale/product-offers" POST/PATCH törzs összeállítása a termékből.
	 *
	 * FONTOS: 2024 eleje óta a régi `/sale/offers` végpont teljesen le van
	 * tiltva ajánlat létrehozásra/szerkesztésre - a jelenleg támogatott,
	 * egyetlen út a `/sale/product-offers`. Ennek eltérő a sémája a régihez
	 * képest: a termékadatok (név, kategória, kép, paraméterek) a
	 * `productSet[0].product` alá kerülnek, a garancia/visszaküldés az
	 * `afterSalesServices` objektumba, és a `publication.status = ACTIVE`
	 * beállításával egy lépésben, azonnal publikált ajánlat jön létre -
	 * nincs többé szükség külön draft + publikáló parancs lépésre.
	 * Doksi: https://developer.allegro.pl/tutorials/jak-jednym-requestem-wystawic-oferte-powiazana-z-produktem-D7Kj9gw4xFA
	 *
	 * @param WC_Product $product
	 * @param string[]   $allegro_image_urls Az Allegro szerverére MÁR feltöltött kép URL-ek
	 *                                        (FBAS_Api_Client::upload_image_from_url() eredménye).
	 */
	public static function build_offer_payload( WC_Product $product, array $allegro_image_urls = array() ) {
		$settings = FBAS_Settings::get_all();

		$description_html = self::sanitize_offer_description(
			$product->get_description() ?: $product->get_short_description()
		);

		// Az Allegro ajánlat/termék cím maximum 75 karakter lehet (2023 óta ez a limit).
		$offer_title = self::truncate_offer_title( $product->get_name() );

		$stock_qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : 1;
		if ( ! $product->is_in_stock() ) {
			$stock_qty = 0;
		}

		// A termék/katalógus szintű paraméterek (pl. márka, anyag, EAN) - kategóriánként eltérő
		// kötelező mezők, a `fbas_offer_parameters` szűrőn keresztül bővíthető.
		$product_node = array(
			'name'       => $offer_title,
			'category'   => array( 'id' => (string) $settings['offer_category_id'] ),
			'parameters' => apply_filters( 'fbas_offer_parameters', array(), $product ),
			'images'     => $allegro_image_urls,
		);

		// Az ajánlat-szintű paraméterek (leggyakrabban "Állapot/Condition") -
		// a legtöbb kategóriában kötelező, de az ID kategóriánként más, ezért
		// külön szűrőn keresztül állítható be, alapból üres.
		$offer_level_parameters = apply_filters( 'fbas_offer_state_parameters', array(), $product );

		$after_sales_services = array_filter( array(
			'warranty'        => $settings['default_warranty_id'] ? array( 'id' => (string) $settings['default_warranty_id'] ) : null,
			'returnPolicy'    => $settings['default_return_id'] ? array( 'id' => (string) $settings['default_return_id'] ) : null,
		), function ( $value ) {
			return null !== $value;
		} );

		$payload = array(
			'name'       => $offer_title,
			'productSet' => array(
				array(
					'product'  => $product_node,
					'quantity' => array( 'value' => 1 ),
				),
			),
			'parameters'  => $offer_level_parameters,
			'images'      => $allegro_image_urls,
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
			// ACTIVE = azonnal publikált ajánlat egyetlen kéréssel (nincs külön "publikálás" lépés).
			'publication' => array(
				'status' => 'ACTIVE',
			),
			'delivery' => array(
				'shippingRates' => array(
					'id' => (string) $settings['default_delivery_id'],
				),
			),
			'external' => array(
				'id' => (string) $product->get_id(), // saját azonosító - visszakereshetőség
			),
		);

		if ( ! empty( $after_sales_services ) ) {
			$payload['afterSalesServices'] = $after_sales_services;
		}

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
