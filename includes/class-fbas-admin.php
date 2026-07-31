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

		add_action( 'wp_ajax_fbas_start_device_auth', array( $this, 'ajax_start_device_auth' ) );
		add_action( 'wp_ajax_fbas_poll_device_auth', array( $this, 'ajax_poll_device_auth' ) );
		add_action( 'wp_ajax_fbas_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_fbas_run_sync_now', array( $this, 'ajax_run_sync_now' ) );
		add_action( 'wp_ajax_fbas_toggle_product_sync', array( $this, 'ajax_toggle_product_sync' ) );
		add_action( 'wp_ajax_fbas_sync_single_product', array( $this, 'ajax_sync_single_product' ) );
		add_action( 'wp_ajax_fbas_remove_offer', array( $this, 'ajax_remove_offer' ) );

		add_action( 'wp_ajax_fbas_search_categories', array( $this, 'ajax_search_categories' ) );
		add_action( 'wp_ajax_fbas_list_account_resource', array( $this, 'ajax_list_account_resource' ) );

		// Termék szerkesztő oldalon gyors kapcsoló.
		add_action( 'add_meta_boxes', array( $this, 'register_product_metabox' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Allegro Sync', 'fb-allegro-sync' ),
			__( 'Allegro Sync', 'fb-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-sync',
			array( $this, 'render_settings_page' ),
			'dashicons-update',
			56
		);

		add_submenu_page(
			'fbas-allegro-sync',
			__( 'Beállítások', 'fb-allegro-sync' ),
			__( 'Beállítások', 'fb-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-sync',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'fbas-allegro-sync',
			__( 'Termékek', 'fb-allegro-sync' ),
			__( 'Termékek', 'fb-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-products',
			array( $this, 'render_products_page' )
		);

		add_submenu_page(
			'fbas-allegro-sync',
			__( 'Napló', 'fb-allegro-sync' ),
			__( 'Napló', 'fb-allegro-sync' ),
			'manage_woocommerce',
			'fbas-allegro-log',
			array( $this, 'render_log_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'fbas-allegro' ) === false && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'fbas-admin', FBAS_PLUGIN_URL . 'assets/admin.css', array(), FBAS_VERSION );
		wp_enqueue_script( 'fbas-admin', FBAS_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), FBAS_VERSION, true );
		wp_localize_script( 'fbas-admin', 'FBAS', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'fbas_nonce' ),
			'i18n'    => array(
				'connecting'   => __( 'Kapcsolódás…', 'fb-allegro-sync' ),
				'waiting'      => __( 'Várakozás a jóváhagyásra…', 'fb-allegro-sync' ),
				'connected'    => __( 'Sikeresen összekötve!', 'fb-allegro-sync' ),
				'error'        => __( 'Hiba történt.', 'fb-allegro-sync' ),
				'syncing'      => __( 'Szinkronizálás…', 'fb-allegro-sync' ),
				'synced'       => __( 'Szinkronizálva.', 'fb-allegro-sync' ),
				'confirmRemove'=> __( 'Biztosan törlöd az ajánlatot az Allegro-ról?', 'fb-allegro-sync' ),
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
			wp_die( esc_html__( 'Nincs jogosultság.', 'fb-allegro-sync' ) );
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
			__( 'Allegro Sync', 'fb-allegro-sync' ),
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
				<?php esc_html_e( 'Szinkronizálás az Allegro-val', 'fb-allegro-sync' ); ?>
			</label>
		</p>
		<?php if ( $offer_id ) : ?>
			<p><strong><?php esc_html_e( 'Allegro offer ID:', 'fb-allegro-sync' ); ?></strong> <?php echo esc_html( $offer_id ); ?></p>
		<?php endif; ?>
		<?php if ( $last ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: dátum, 2: státusz */
					esc_html__( 'Utolsó szinkron: %1$s (%2$s)', 'fb-allegro-sync' ),
					esc_html( $last ),
					esc_html( $status )
				);
				?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'A mentés után, ha automatikus szinkron be van kapcsolva, a plugin frissíti az ajánlatot.', 'fb-allegro-sync' ); ?>
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
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'fb-allegro-sync' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'Lejárt a kapcsolódási kísérlet, próbáld újra.', 'fb-allegro-sync' ) ) );
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
		wp_send_json_success( array( 'message' => __( 'Szinkron lefutott, részletek a naplóban.', 'fb-allegro-sync' ) ) );
	}

	public function ajax_toggle_product_sync() {
		$this->verify_ajax();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$enabled    = ! empty( $_POST['enabled'] );

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen termék.', 'fb-allegro-sync' ) ) );
		}

		FBAS_Product_Mapper::set_sync_enabled( $product_id, $enabled );
		wp_send_json_success();
	}

	public function ajax_sync_single_product() {
		$this->verify_ajax();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen termék.', 'fb-allegro-sync' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Először kösd össze a fiókot az Allegro-val.', 'fb-allegro-sync' ) ) );
		}

		$query = sanitize_text_field( $_POST['query'] ?? '' );
		if ( strlen( $query ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Írj be legalább 2 karaktert.', 'fb-allegro-sync' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'Először kösd össze a fiókot az Allegro-val.', 'fb-allegro-sync' ) ) );
		}

		$map = array(
			'shipping' => array( 'path' => '/sale/shipping-rates', 'key' => 'shippingRates' ),
			'warranty' => array( 'path' => '/sale/warranties', 'key' => 'warranties' ),
			'return'   => array( 'path' => '/sale/return-policies', 'key' => 'returnPolicies' ),
		);

		$type = sanitize_key( $_POST['type'] ?? '' );
		if ( ! isset( $map[ $type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Ismeretlen erőforrás típus.', 'fb-allegro-sync' ) ) );
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
}

add_action( 'save_post_product', array( FBAS_Admin::instance(), 'save_product_metabox' ) );
