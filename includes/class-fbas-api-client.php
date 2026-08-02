<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allegro REST API kliens.
 *
 * Hitelesítés: OAuth2 "Device Code Flow" - ez az egyetlen mód, ami
 * WordPress admin felületről (redirect URI nélkül) is jól működik.
 * Doksi: https://developer.allegro.pl/tutorials/uwierzytelnianie-i-autoryzacja-zlq9e75GdIR
 *
 * A sync scope-ot (allegro:api:sale:offers:read/write) az Allegro
 * fejlesztői konzolban regisztrált alkalmazásnál kell beállítani.
 */
class FBAS_Api_Client {

	/** @var FBAS_Api_Client|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* -----------------------------------------------------------
	 * OAuth2 Device Code Flow
	 * --------------------------------------------------------- */

	/**
	 * 1. lépés: device code kérése. A visszaadott user_code-ot és
	 * verification_uri_complete linket az admin oldalon jelenítjük meg,
	 * a felhasználónak be kell lépnie az Allegro fiókjába és jóvá kell hagynia.
	 */
	public function request_device_code() {
		$settings = FBAS_Settings::get_all();

		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			return new WP_Error( 'fbas_missing_credentials', __( 'Hiányzó Client ID / Client Secret. Add meg a beállításokban.', 'wp-allegro-sync' ) );
		}

		$response = wp_remote_post( FBAS_Settings::auth_base_url() . '/auth/oauth/device', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $settings['client_id'] . ':' . $settings['client_secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'client_id' => $settings['client_id'],
			),
		) );

		return $this->parse_response( $response );
	}

	/**
	 * 2. lépés: pollozás a token végpontnál, amíg a felhasználó
	 * jóvá nem hagyja a hozzáférést (vagy le nem jár az idő).
	 */
	public function poll_device_token( $device_code ) {
		$settings = FBAS_Settings::get_all();

		$response = wp_remote_post( FBAS_Settings::auth_base_url() . '/auth/oauth/token', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $settings['client_id'] . ':' . $settings['client_secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
				'device_code' => $device_code,
			),
		) );

		$result = $this->parse_response( $response, array( 400, 428 ) );

		if ( ! is_wp_error( $result ) && ! empty( $result['access_token'] ) ) {
			FBAS_Settings::save_token_data( $result );
		}

		return $result;
	}

	private function refresh_token() {
		$settings = FBAS_Settings::get_all();
		$token    = FBAS_Settings::get_token_data();

		if ( empty( $token['refresh_token'] ) ) {
			return new WP_Error( 'fbas_no_refresh_token', __( 'Nincs mentett Allegro hozzáférés. Kösd össze újra a fiókot a beállításoknál.', 'wp-allegro-sync' ) );
		}

		$response = wp_remote_post( FBAS_Settings::auth_base_url() . '/auth/oauth/token', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $settings['client_id'] . ':' . $settings['client_secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $token['refresh_token'],
			),
		) );

		$result = $this->parse_response( $response );

		if ( ! is_wp_error( $result ) && ! empty( $result['access_token'] ) ) {
			FBAS_Settings::save_token_data( $result );
		}

		return $result;
	}

	/**
	 * Érvényes access tokent ad vissza, szükség esetén frissítve azt.
	 */
	public function get_valid_access_token() {
		$token = FBAS_Settings::get_token_data();

		if ( empty( $token['access_token'] ) ) {
			return new WP_Error( 'fbas_not_connected', __( 'A plugin még nincs összekötve az Allegro fiókkal.', 'wp-allegro-sync' ) );
		}

		$expires_in  = isset( $token['expires_in'] ) ? (int) $token['expires_in'] : 43200;
		$obtained_at = isset( $token['obtained_at'] ) ? (int) $token['obtained_at'] : 0;

		// 60 másodperc biztonsági ráhagyás a lejárat előtt.
		if ( time() >= ( $obtained_at + $expires_in - 60 ) ) {
			$refreshed = $this->refresh_token();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return $refreshed['access_token'];
		}

		return $token['access_token'];
	}

	public function is_connected() {
		$token = FBAS_Settings::get_token_data();
		return ! empty( $token['access_token'] ) && ! empty( $token['refresh_token'] );
	}

	public function disconnect() {
		FBAS_Settings::clear_token_data();
	}

	/* -----------------------------------------------------------
	 * Általános REST hívás az Allegro API felé
	 * --------------------------------------------------------- */

	public function request( $method, $path, $body = null, array $extra_headers = array() ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$headers = array_merge( array(
			'Authorization'   => 'Bearer ' . $token,
			'Accept'          => 'application/vnd.allegro.public.v1+json',
			'Content-Type'    => 'application/vnd.allegro.public.v1+json',
			// A kategória-/paraméter-nevek és a hibaüzenetek (userMessage) ez
			// alapján jönnek vissza lefordítva. Az Allegro csak lengyelt és
			// angolt támogat ehhez - ha a WP oldal nyelve (pl. magyar) nem
			// támogatott, ők automatikusan angolra esnek vissza (a saját
			// API doksijuk szerint), szóval nyugodtan mindig a WP nyelvét küldjük.
			'Accept-Language' => $this->get_accept_language(),
		), $extra_headers );

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( FBAS_Settings::api_base_url() . $path, $args );

		return $this->parse_response( $response );
	}

	/**
	 * A WordPress oldal nyelvi beállítását (pl. "hu_HU") alakítja át az
	 * Allegro API által várt BCP47 formátumra (pl. "hu-HU").
	 */
	private function get_accept_language() {
		$locale = get_locale(); // pl. "hu_HU", "en_US"
		if ( ! $locale ) {
			return 'en-US';
		}
		return str_replace( '_', '-', $locale );
	}

	public function get( $path ) {
		return $this->request( 'GET', $path );
	}

	public function post( $path, $body ) {
		return $this->request( 'POST', $path, $body );
	}

	public function put( $path, $body ) {
		return $this->request( 'PUT', $path, $body );
	}

	public function patch( $path, $body ) {
		return $this->request( 'PATCH', $path, $body, array( 'Content-Type' => 'application/vnd.allegro.public.v1+json' ) );
	}

	public function delete( $path ) {
		return $this->request( 'DELETE', $path );
	}

	/**
	 * Egy Allegro kategória összes paraméterének lekérdezése (id, name, required,
	 * dictionary értékek stb.) - ebből tudjuk feloldani a nyers paraméter ID-kat
	 * emberileg érthető névre, és ebből építjük fel a kitöltő űrlapot is.
	 * 1 órás transient cache-eléssel, mivel ez ritkán változik.
	 *
	 * @return array|WP_Error
	 */
	public function get_category_parameters( $category_id ) {
		$category_id = (string) $category_id;
		$cache_key   = 'fbas_cat_params_' . md5( $category_id . FBAS_Settings::api_base_url() );
		$cached      = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->get( '/sale/categories/' . rawurlencode( $category_id ) . '/parameters' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parameters = $result['parameters'] ?? array();
		set_transient( $cache_key, $parameters, HOUR_IN_SECONDS );

		return $parameters;
	}

	/**
	 * Kép feltöltése egy külső URL-ről az Allegro saját képszerverére.
	 * Ez KÖTELEZŐ lépés minden ajánlatkép esetén - az Allegro nem fogadja el
	 * közvetlenül a külső (pl. a mi WordPress oldalunkon tárolt) kép URL-eket,
	 * "Invalid image URL. Image must be present on allegro server." hibával utasítja el.
	 *
	 * @param string $external_url A forrás kép URL-je (pl. WooCommerce termékkép).
	 * @return string|WP_Error Az Allegro-n tárolt kép URL-je, vagy hiba.
	 */
	public function upload_image_from_url( $external_url ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( FBAS_Settings::upload_base_url() . '/sale/images', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/vnd.allegro.public.v1+json',
				'Content-Type'  => 'application/vnd.allegro.public.v1+json',
			),
			'body'    => wp_json_encode( array( 'url' => $external_url ) ),
		) );

		$result = $this->parse_response( $response );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['location'] ) ) {
			FBAS_Logger::log( 'Kép feltöltés: az Allegro nem adott vissza "location" mezőt.', 'error', array( 'raw' => $result, 'source_url' => $external_url ) );
			return new WP_Error( 'fbas_image_upload_no_location', __( 'Az Allegro nem adott vissza kép URL-t a feltöltés után.', 'wp-allegro-sync' ) );
		}

		return $result['location'];
	}

	/* -----------------------------------------------------------
	 * Válasz feldolgozás
	 * --------------------------------------------------------- */

	private function parse_response( $response, array $allowed_error_codes = array() ) {
		if ( is_wp_error( $response ) ) {
			FBAS_Logger::log( 'HTTP hiba: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		// Device flow pollozásnál 400/428 "várakozás" státusz, nem hiba.
		if ( in_array( $code, $allowed_error_codes, true ) && is_array( $data ) ) {
			return $data;
		}

		$error_entry = $data['errors'][0] ?? array();

		// Az Allegro "userMessage" mezője tartalmazza a ténylegesen hasznos,
		// olvasható hibaleírást (pl. "Nazwa produktu nie może być dłuższa niż
		// 75 znaków." vagy a hiányzó paraméterek listája) - ezt részesítjük
		// előnyben a generikus "message" mezővel szemben (ami gyakran csak
		// annyi, hogy "Unprocessable entity").
		$message = ! empty( $error_entry['userMessage'] )
			? $error_entry['userMessage']
			: ( $error_entry['message'] ?? ( $data['error_description'] ?? 'Ismeretlen Allegro API hiba (HTTP ' . $code . ')' ) );

		// Ha a hiba a "hiányzó kötelező kategória-paraméterek" mintára illeszkedik,
		// a nyers paraméter ID-kat emberileg érthető nevekre fordítjuk le.
		$message = $this->humanize_missing_parameters_error( $message );

		FBAS_Logger::log( 'Allegro API hiba (' . $code . '): ' . $message, 'error', array( 'raw' => $raw ) );

		return new WP_Error( 'fbas_api_error', $message, array( 'status' => $code, 'body' => $data ) );
	}

	/**
	 * Az Allegro "Unable to create product without proper values in all
	 * required parameters: [id1, id2, ...] in category: X" hibaüzenetét
	 * emberileg érthető, magyar szövegre fordítja - a nyers paraméter ID-kat
	 * lekérdezi és feloldja a kategória paraméter-neveire.
	 */
	private function humanize_missing_parameters_error( $message ) {
		if ( ! is_string( $message ) || false === strpos( $message, 'required parameters' ) ) {
			return $message;
		}

		if ( ! preg_match( '/required parameters:\s*\[([^\]]*)\]\s*in category:\s*([\w-]+)/i', $message, $matches ) ) {
			return $message;
		}

		$missing_ids = array_map( 'trim', explode( ',', $matches[1] ) );
		$category_id = trim( $matches[2] );

		$category_params = $this->get_category_parameters( $category_id );

		$labels = array();
		foreach ( $missing_ids as $param_id ) {
			$label = $param_id;
			if ( ! is_wp_error( $category_params ) ) {
				foreach ( $category_params as $param ) {
					if ( (string) ( $param['id'] ?? '' ) === (string) $param_id ) {
						$label = $param['name'] ?? $param_id;
						break;
					}
				}
			}
			$labels[] = sprintf( '%s (ID: %s)', $label, $param_id );
		}

		return sprintf(
			/* translators: 1: kategória ID, 2: hiányzó paraméterek listája */
			__( 'Hiányoznak a kötelező termékparaméterek a(z) %1$s kategóriában: %2$s. Töltsd ki ezeket a plugin "Beállítások" oldalán, a "Kötelező kategória-paraméterek" résznél.', 'wp-allegro-sync' ),
			$category_id,
			implode( ', ', $labels )
		);
	}
}
