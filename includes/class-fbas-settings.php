<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Beállítások tárolása / lekérdezése (wp_options).
 */
class FBAS_Settings {

	const OPTION_KEY = 'fbas_settings';
	const TOKEN_KEY  = 'fbas_oauth_token';

	/** @var array|null Kérésen belüli cache, hogy ne kelljen újra és újra wp_parse_args-olni. */
	private static $cache = null;

	/** @var array|null */
	private static $token_cache = null;

	public static function defaults() {
		return array(
			'environment'        => 'sandbox', // 'sandbox' | 'production'
			'client_id'           => '',
			'client_secret'       => '',
			'auto_sync_enabled'   => 'no',
			'sync_price'          => 'yes',
			'sync_stock'          => 'yes',
			'offer_category_id'   => '',
			'default_delivery_id' => '',
			'default_warranty_id' => '',
			'default_return_id'   => '',
			'price_markup_percent'=> '0',
			'batch_size'          => '20',
		);
	}

	public static function get_all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, array() );
			self::$cache = wp_parse_args( $saved, self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function update( array $data ) {
		$all = self::get_all();
		$all = array_merge( $all, $data );
		update_option( self::OPTION_KEY, $all );
		self::$cache = $all; // Cache frissítése, hogy a mentés utáni kód is friss adatot lásson.
		return $all;
	}

	public static function api_base_url() {
		return 'production' === self::get( 'environment' )
			? 'https://api.allegro.pl'
			: 'https://api.allegro.pl.allegrosandbox.pl';
	}

	/**
	 * A kép-feltöltő végpont külön domain-en fut (upload.allegro.pl), nem az api.allegro.pl alatt.
	 */
	public static function upload_base_url() {
		return 'production' === self::get( 'environment' )
			? 'https://upload.allegro.pl'
			: 'https://upload.allegro.pl.allegrosandbox.pl';
	}

	public static function auth_base_url() {
		return 'production' === self::get( 'environment' )
			? 'https://allegro.pl'
			: 'https://allegro.pl.allegrosandbox.pl';
	}

	public static function get_token_data() {
		if ( null === self::$token_cache ) {
			self::$token_cache = get_option( self::TOKEN_KEY, array() );
		}
		return self::$token_cache;
	}

	public static function save_token_data( array $token_data ) {
		$token_data['obtained_at'] = time();
		update_option( self::TOKEN_KEY, $token_data );
		self::$token_cache = $token_data;
	}

	public static function clear_token_data() {
		delete_option( self::TOKEN_KEY );
		self::$token_cache = array();
	}
}
