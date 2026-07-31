<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Egyszerű naplózó - wp_options-ban tárolja az utolsó N bejegyzést,
 * hogy az admin felületen megjeleníthető legyen hiba/siker infó.
 */
class FBAS_Logger {

	const OPTION_KEY = 'fbas_log';
	const MAX_ENTRIES = 200;

	public static function log( $message, $level = 'info', array $context = array() ) {
		$entries = get_option( self::OPTION_KEY, array() );

		array_unshift( $entries, array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level, // info | success | warning | error
			'message' => $message,
			'context' => $context,
		) );

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $entries, false );

		if ( 'error' === $level ) {
			error_log( '[FBAS Allegro Sync] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
		}
	}

	public static function get_entries() {
		return get_option( self::OPTION_KEY, array() );
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}
