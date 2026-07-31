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
		);
	}

	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function update( array $data ) {
		$all = self::get_all();
		$all = array_merge( $all, $data );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	public static function api_base_url() {
		return 'production' === self::get( 'environment' )
			? 'https://api.allegro.pl'
			: 'https://api.allegro.pl.allegrosandbox.pl';
	}

	public static function auth_base_url() {
		return 'production' === self::get( 'environment' )
			? 'https://allegro.pl'
			: 'https://allegro.pl.allegrosandbox.pl';
	}

	public static function get_token_data() {
		return get_option( self::TOKEN_KEY, array() );
	}

	public static function save_token_data( array $token_data ) {
		$token_data['obtained_at'] = time();
		update_option( self::TOKEN_KEY, $token_data );
	}

	public static function clear_token_data() {
		delete_option( self::TOKEN_KEY );
	}
}
