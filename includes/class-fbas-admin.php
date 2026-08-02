<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FBAS_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_fbas_save_settings', array( $this, 'handle_save_settings' ) );

		// WP Rocket (és hasonló optimalizáló pluginok) néha eltávolítják a
		// ?ver= lekérdezési karakterláncot a statikus JS/CSS fájlokról, ami
		// megakadályozza a verzió-alapú cache-bustingunkat: a böngésző/CDN a
		// verziófrissítés után is a régi fájlt szolgálja ki. Kizárjuk ez alól
		// a saját admin fájljainkat.
		add_filter( 'rocket_exclude_js', array( $this, 'exclude_from_rocket_query_string_removal' ) );
		add_filter( 'rocket_exclude_css', array( $this, 'exclude_from_rocket_query_string_removal' ) );

		add_action( 'wp_ajax_fbas_start_device_auth', array( $this, 'ajax_start_device_auth' ) );
		add_action( 'wp_ajax_fbas_poll_device_auth', array( $this, 'ajax_poll_device_auth' ) );
		add_action( 'wp_ajax_fbas_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_fbas_run_sync_now', array( $this, 'ajax_run_sync_now' ) );
		add_action( 'wp_ajax_fbas_toggle_product_sync', array( $this, 'ajax_toggle_product_sync' ) );
		add_action( 'wp_ajax_fbas_sync_single_product', array( $this, 'ajax_sync_single_product' ) );
		add_action( 'wp_ajax_fbas_remove_offer', array( $this, 'ajax_remove_offer' ) );

		add_action( 'wp_ajax_fbas_search_categories', array( $this, 'ajax_search_categories' ) );
		add_action( 'wp_ajax_fbas_list_account_resource', array( $this, 'ajax_list_account_resource' ) );
		add_action( 'wp_ajax_fbas_get_category_parameters', array( $this, 'ajax_get_category_parameters' ) );
		add_action( 'wp_ajax_fbas_save_category_parameter_values', array( $this, 'ajax_save_category_parameter_values' ) );

		// Termék szerkesztő oldalon gyors kapcsoló.
		add_action( 'add_meta_boxes', array( $this, 'register_product_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_product_metabox' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Allegro Sync', 'wp-allegro-sync' ),
			__( 'Allegro Sync', 'wp-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-sync',
			array( $this, 'render_settings_page' ),
			'dashicons-update',
			56
		);

		add_submenu_page(
			'fbas-allegro-sync',
			__( 'Beállítások', 'wp-allegro-sync' ),
			__( 'Beállítások', 'wp-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-sync',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'fbas-allegro-sync',
			__( 'Termékek', 'wp-allegro-sync' ),
			__( 'Termékek', 'wp-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-products',
			array( $this, 'render_products_page' )
		);

		add_submenu_page(
			'fbas-allegro-sync',
			__( 'Napló', 'wp-allegro-sync' ),
			__( 'Napló', 'wp-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-log',
			array( $this, 'render_log_page' )
		);
	}

	/**
	 * WP Rocket "statikus erőforrások lekérdezési karakterláncainak
	 * eltávolítása" funkciójából kizárja a plugin saját admin JS/CSS fájljait,
	 * hogy a verzió-alapú cache-busting (?ver=...) mindig működjön, és ne
	 * ragadjon be egy régi, gyorsítótárazott admin.js/admin.css.
	 */
	public function exclude_from_rocket_query_string_removal( $excluded ) {
		$excluded[] = 'wp-content/plugins/WP-Allegro-Sync/assets/admin.js';
		$excluded[] = 'wp-content/plugins/WP-Allegro-Sync/assets/admin.css';
		return $excluded;
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'fbas-allegro' ) === false && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'fbas-admin', FBAS_PLUGIN_URL . 'assets/admin.css', array(), FBAS_VERSION );
		wp_enqueue_script( 'fbas-admin', FBAS_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), FBAS_VERSION, true );
		wp_localize_script( 'fbas-admin', 'FBAS', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fbas_nonce' ),
			'version'  => FBAS_VERSION,
			'i18n'    => array(
				'connecting'   => __( 'Kapcsolódás…', 'wp-allegro-sync' ),
				'waiting'      => __( 'Várakozás a jóváhagyásra…', 'wp-allegro-sync' ),
				'connected'    => __( 'Sikeresen összekötve!', 'wp-allegro-sync' ),
				'error'        => __( 'Hiba történt.', 'wp-allegro-sync' ),
				'syncing'      => __( 'Szinkronizálás…', 'wp-allegro-sync' ),
				'synced'       => __( 'Szinkronizálva.', 'wp-allegro-sync' ),
				'confirmRemove'=> __( 'Biztosan törlöd az ajánlatot az Allegro-ról?', 'wp-allegro-sync' ),
			),
		) );
	}

	/* -----------------------------------------------------------
	 * Beállítások oldal
	 * --------------------------------------------------------- */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings   = FBAS_Settings::get_all();
		$is_connected = FBAS_Api_Client::instance()->is_connected();
		include FBAS_PLUGIN_DIR . 'includes/views/settings-page.php';
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'fbas_save_settings' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'wp-allegro-sync' ) );
		}

		$data = array(
			'environment'          => in_array( $_POST['environment'] ?? '', array( 'sandbox', 'production' ), true ) ? $_POST['environment'] : 'sandbox',
			'client_id'             => sanitize_text_field( $_POST['client_id'] ?? '' ),
			'client_secret'         => sanitize_text_field( $_POST['client_secret'] ?? '' ),
			'auto_sync_enabled'     => ! empty( $_POST['auto_sync_enabled'] ) ? 'yes' : 'no',
			'sync_price'            => ! empty( $_POST['sync_price'] ) ? 'yes' : 'no',
			'sync_stock'            => ! empty( $_POST['sync_stock'] ) ? 'yes' : 'no',
			'offer_category_id'     => sanitize_text_field( $_POST['offer_category_id'] ?? '' ),
			'default_delivery_id'   => sanitize_text_field( $_POST['default_delivery_id'] ?? '' ),
			'default_warranty_id'   => sanitize_text_field( $_POST['default_warranty_id'] ?? '' ),
			'default_return_id'     => sanitize_text_field( $_POST['default_return_id'] ?? '' ),
			'price_markup_percent'  => is_numeric( $_POST['price_markup_percent'] ?? null ) ? (string) floatval( $_POST['price_markup_percent'] ) : '0',
			'batch_size'            => max( 1, absint( $_POST['batch_size'] ?? 20 ) ),
		);

		FBAS_Settings::update( $data );

		wp_safe_redirect( add_query_arg( array( 'page' => 'fbas-allegro-sync', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* -----------------------------------------------------------
	 * Termékek oldal
	 * --------------------------------------------------------- */

	public function render_products_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search   = sanitize_text_field( $_GET['s'] ?? '' );

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'paged'          => $paged,
		);
		if ( $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );

		include FBAS_PLUGIN_DIR . 'includes/views/products-page.php';
	}

	/* -----------------------------------------------------------
	 * Napló oldal
	 * --------------------------------------------------------- */

	public function render_log_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$entries = FBAS_Logger::get_entries();
		include FBAS_PLUGIN_DIR . 'includes/views/log-page.php';
	}

	/* -----------------------------------------------------------
	 * Termék szerkesztő meta box
	 * --------------------------------------------------------- */

	public function register_product_metabox() {
		add_meta_box(
			'fbas_product_sync',
			__( 'Allegro Sync', 'wp-allegro-sync' ),
			array( $this, 'render_product_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	public function render_product_metabox( $post ) {
		wp_nonce_field( 'fbas_product_metabox', 'fbas_product_metabox_nonce' );
		$enabled  = FBAS_Product_Mapper::is_enabled_for_sync( $post->ID );
		$offer_id = FBAS_Product_Mapper::get_offer_id( $post->ID );
		$status   = get_post_meta( $post->ID, FBAS_Product_Mapper::META_LAST_STATUS, true );
		$last     = get_post_meta( $post->ID, FBAS_Product_Mapper::META_LAST_SYNC, true );
		?>
		<p>
			<label>
				<input type="checkbox" name="fbas_sync_enabled" value="yes" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Szinkronizálás az Allegro-val', 'wp-allegro-sync' ); ?>
			</label>
		</p>
		<?php if ( $offer_id ) : ?>
			<p><strong><?php esc_html_e( 'Allegro offer ID:', 'wp-allegro-sync' ); ?></strong> <?php echo esc_html( $offer_id ); ?></p>
		<?php endif; ?>
		<?php if ( $last ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: dátum, 2: státusz */
					esc_html__( 'Utolsó szinkron: %1$s (%2$s)', 'wp-allegro-sync' ),
					esc_html( $last ),
					esc_html( $status )
				);
				?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'A mentés után, ha automatikus szinkron be van kapcsolva, a plugin frissíti az ajánlatot.', 'wp-allegro-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * A metabox checkbox mentése a sima WP termék mentéskor (save_post).
	 */
	public function save_product_metabox( $post_id ) {
		if ( ! isset( $_POST['fbas_product_metabox_nonce'] ) || ! wp_verify_nonce( $_POST['fbas_product_metabox_nonce'], 'fbas_product_metabox' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		FBAS_Product_Mapper::set_sync_enabled( $post_id, ! empty( $_POST['fbas_sync_enabled'] ) );
	}

	/* -----------------------------------------------------------
	 * AJAX végpontok
	 * --------------------------------------------------------- */

	private function verify_ajax() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'fbas_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'wp-allegro-sync' ) ), 403 );
		}
	}

	public function ajax_start_device_auth() {
		$this->verify_ajax();

		$result = FBAS_Api_Client::instance()->request_device_code();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		set_transient( 'fbas_device_code_' . get_current_user_id(), $result['device_code'], 15 * MINUTE_IN_SECONDS );

		wp_send_json_success( array(
			'user_code'                => $result['user_code'] ?? '',
			'verification_uri'         => $result['verification_uri'] ?? '',
			'verification_uri_complete'=> $result['verification_uri_complete'] ?? '',
			'interval'                 => $result['interval'] ?? 5,
			'expires_in'               => $result['expires_in'] ?? 600,
		) );
	}

	public function ajax_poll_device_auth() {
		$this->verify_ajax();

		$device_code = get_transient( 'fbas_device_code_' . get_current_user_id() );
		if ( ! $device_code ) {
			wp_send_json_error( array( 'message' => __( 'Lejárt a kapcsolódási kísérlet, próbáld újra.', 'wp-allegro-sync' ) ) );
		}

		$result = FBAS_Api_Client::instance()->poll_device_token( $device_code );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! empty( $result['access_token'] ) ) {
			delete_transient( 'fbas_device_code_' . get_current_user_id() );
			wp_send_json_success( array( 'status' => 'connected' ) );
		}

		// authorization_pending / slow_down -> még várunk.
		wp_send_json_success( array( 'status' => $result['error'] ?? 'pending' ) );
	}

	public function ajax_disconnect() {
		$this->verify_ajax();
		FBAS_Api_Client::instance()->disconnect();
		wp_send_json_success();
	}

	public function ajax_run_sync_now() {
		$this->verify_ajax();
		FBAS_Sync::instance()->run_full_sync();
		wp_send_json_success( array( 'message' => __( 'Szinkron lefutott, részletek a naplóban.', 'wp-allegro-sync' ) ) );
	}

	public function ajax_toggle_product_sync() {
		$this->verify_ajax();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$enabled    = ! empty( $_POST['enabled'] );

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen termék.', 'wp-allegro-sync' ) ) );
		}

		FBAS_Product_Mapper::set_sync_enabled( $product_id, $enabled );
		wp_send_json_success();
	}

	public function ajax_sync_single_product() {
		$this->verify_ajax();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen termék.', 'wp-allegro-sync' ) ) );
		}

		$result = FBAS_Sync::instance()->sync_product( $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'offer_id' => FBAS_Product_Mapper::get_offer_id( $product_id ),
		) );
	}

	public function ajax_remove_offer() {
		$this->verify_ajax();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$result     = FBAS_Sync::instance()->remove_offer( $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Allegro kategória keresés név alapján (GET /sale/matching-categories),
	 * hogy a beállítások oldalon ne kelljen kézzel kitalálni a kategória ID-t.
	 */
	public function ajax_search_categories() {
		$this->verify_ajax();

		if ( ! FBAS_Api_Client::instance()->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Először kösd össze a fiókot az Allegro-val.', 'wp-allegro-sync' ) ) );
		}

		$query = sanitize_text_field( $_POST['query'] ?? '' );
		if ( strlen( $query ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Írj be legalább 2 karaktert.', 'wp-allegro-sync' ) ) );
		}

		$result = FBAS_Api_Client::instance()->get( '/sale/matching-categories?name=' . rawurlencode( $query ) );

		if ( is_wp_error( $result ) ) {
			FBAS_Logger::log( 'Kategória keresés hiba ("' . $query . '"): ' . $result->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Debug: a nyers választ is naplózzuk, hogy a Napló oldalon látható legyen,
		// ha az Allegro más mezőnevet ad vissza, mint amit feltételezünk.
		FBAS_Logger::log( 'Kategória keresés ("' . $query . '") nyers válasz.', 'info', array( 'raw' => $result ) );

		$raw = array();
		if ( ! empty( $result['matchingCategories'] ) ) {
			$raw = $result['matchingCategories'];
		} elseif ( is_array( $result ) && ! empty( $result ) && isset( $result[0] ) ) {
			// Fallback, ha az API közvetlenül tömböt ad vissza kulcs nélkül.
			$raw = $result;
		}
		$items = array();

		foreach ( $raw as $item ) {
			$path_names = array();
			if ( ! empty( $item['category']['path'] ) && is_array( $item['category']['path'] ) ) {
				foreach ( $item['category']['path'] as $step ) {
					$path_names[] = $step['name'] ?? '';
				}
			}
			$items[] = array(
				'id'   => $item['category']['id'] ?? ( $item['id'] ?? '' ),
				'name' => $item['category']['name'] ?? ( $item['name'] ?? '' ),
				'path' => implode( ' → ', array_filter( $path_names ) ),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * A bejelentkezett Allegro fiókhoz tartozó szállítási sablonok / garanciák /
	 * visszaküldési szabályzatok listázása, hogy kiválaszthatóak legyenek a
	 * kézi ID-bemásolgatás helyett.
	 *
	 * type: shipping | warranty | return
	 */
	public function ajax_list_account_resource() {
		$this->verify_ajax();

		if ( ! FBAS_Api_Client::instance()->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Először kösd össze a fiókot az Allegro-val.', 'wp-allegro-sync' ) ) );
		}

		$map = array(
			'shipping' => array( 'path' => '/sale/shipping-rates', 'key' => 'shippingRates' ),
			'warranty' => array( 'path' => '/sale/warranties', 'key' => 'warranties' ),
			'return'   => array( 'path' => '/sale/return-policies', 'key' => 'returnPolicies' ),
		);

		$type = sanitize_key( $_POST['type'] ?? '' );
		if ( ! isset( $map[ $type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Ismeretlen erőforrás típus.', 'wp-allegro-sync' ) ) );
		}

		$result = FBAS_Api_Client::instance()->get( $map[ $type ]['path'] );

		if ( is_wp_error( $result ) ) {
			FBAS_Logger::log( 'Fiók-erőforrás lekérdezés hiba (' . $type . '): ' . $result->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		FBAS_Logger::log( 'Fiók-erőforrás lekérdezés (' . $type . ') nyers válasz.', 'info', array( 'raw' => $result ) );

		$raw   = $result[ $map[ $type ]['key'] ] ?? array();
		$items = array();

		foreach ( $raw as $item ) {
			$items[] = array(
				'id'   => $item['id'] ?? '',
				'name' => $item['name'] ?? $item['id'] ?? '',
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * A beállított (vagy explicit megadott) Allegro kategória összes
	 * paraméterének lekérdezése, a jelenleg elmentett értékekkel együtt -
	 * ebből épül fel a "Kötelező kategória-paraméterek" kitöltő űrlap.
	 */
	public function ajax_get_category_parameters() {
		$this->verify_ajax();

		if ( ! FBAS_Api_Client::instance()->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Először kösd össze a fiókot az Allegro-val.', 'wp-allegro-sync' ) ) );
		}

		$category_id = sanitize_text_field( $_POST['category_id'] ?? FBAS_Settings::get( 'offer_category_id' ) );
		if ( ! $category_id ) {
			wp_send_json_error( array( 'message' => __( 'Előbb add meg / mentsd el az Allegro kategória ID-t.', 'wp-allegro-sync' ) ) );
		}

		$params = FBAS_Api_Client::instance()->get_category_parameters( $category_id );

		if ( is_wp_error( $params ) ) {
			wp_send_json_error( array( 'message' => $params->get_error_message() ) );
		}

		$saved = FBAS_Settings::get_category_parameter_values();
		$items = array();

		foreach ( $params as $param ) {
			$id       = (string) ( $param['id'] ?? '' );
			$required = ! empty( $param['required'] ) || ! empty( $param['requiredForProduct'] );

			$dictionary = array();
			if ( ! empty( $param['dictionary'] ) && is_array( $param['dictionary'] ) ) {
				foreach ( $param['dictionary'] as $dict_item ) {
					$dictionary[] = array(
						'id'    => $dict_item['id'] ?? '',
						'value' => $dict_item['value'] ?? ( $dict_item['id'] ?? '' ),
					);
				}
			}

			$items[] = array(
				'id'         => $id,
				'name'       => $param['name'] ?? $id,
				'required'   => $required,
				'type'       => $param['type'] ?? 'string',
				'dictionary' => $dictionary,
				'saved'      => $saved[ $id ] ?? null,
			);
		}

		// Kötelezők előre, hogy azokat lássa elsőnek a felhasználó.
		usort( $items, function ( $a, $b ) {
			return (int) $b['required'] - (int) $a['required'];
		} );

		wp_send_json_success( array( 'items' => $items, 'category_id' => $category_id ) );
	}

	/**
	 * A kitöltő űrlapon megadott paraméter-értékek mentése.
	 */
	public function ajax_save_category_parameter_values() {
		$this->verify_ajax();

		$raw = wp_unslash( $_POST['values_json'] ?? '' );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen adat.', 'wp-allegro-sync' ) ) );
		}

		$sanitized = array();
		foreach ( $decoded as $param_id => $data ) {
			$param_id = sanitize_text_field( $param_id );
			$entry    = array( 'name' => sanitize_text_field( $data['name'] ?? '' ) );

			if ( ! empty( $data['valuesIds'] ) && is_array( $data['valuesIds'] ) ) {
				$entry['valuesIds'] = array_map( 'sanitize_text_field', $data['valuesIds'] );
			}
			if ( ! empty( $data['values'] ) && is_array( $data['values'] ) ) {
				$entry['values'] = array_map( 'sanitize_text_field', $data['values'] );
			}

			// Csak akkor mentjük, ha ténylegesen van kitöltött érték.
			if ( ! empty( $entry['valuesIds'] ) || ! empty( $entry['values'] ) ) {
				$sanitized[ $param_id ] = $entry;
			}
		}

		FBAS_Settings::save_category_parameter_values( $sanitized );

		wp_send_json_success( array( 'message' => __( 'Paraméterek elmentve.', 'wp-allegro-sync' ) ) );
	}
}
