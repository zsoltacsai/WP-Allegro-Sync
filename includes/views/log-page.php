<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $entries */
?>
<div class="wrap fbas-wrap">
	<h1><?php esc_html_e( 'Allegro Sync – Napló', 'wp-allegro-sync' ); ?></h1>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:160px;"><?php esc_html_e( 'Időpont', 'wp-allegro-sync' ); ?></th>
				<th style="width:100px;"><?php esc_html_e( 'Szint', 'wp-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Üzenet', 'wp-allegro-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $entries ) : ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ); ?></td>
						<td><span class="fbas-badge fbas-badge--<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['level'] ); ?></span></td>
						<td>
							<?php echo esc_html( $entry['message'] ); ?>
							<?php if ( ! empty( $entry['context'] ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'Nyers válasz (debug)', 'wp-allegro-sync' ); ?></summary>
									<pre class="fbas-raw-context"><?php echo esc_html( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="3"><?php esc_html_e( 'Még nincs naplóbejegyzés.', 'wp-allegro-sync' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
