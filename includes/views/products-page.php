<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var WP_Query $query */
/** @var string $search */
?>
<div class="wrap fbas-wrap">
	<h1><?php esc_html_e( 'Allegro Sync – Termékek', 'fb-allegro-sync' ); ?></h1>

	<form method="get" class="fbas-search-form">
		<input type="hidden" name="page" value="fbas-allegro-products" />
		<p class="search-box">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Termék keresése…', 'fb-allegro-sync' ); ?>" />
			<button class="button"><?php esc_html_e( 'Keresés', 'fb-allegro-sync' ); ?></button>
		</p>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:60px;"><?php esc_html_e( 'Kép', 'fb-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Termék', 'fb-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Ár', 'fb-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Készlet', 'fb-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Szinkron', 'fb-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Allegro státusz', 'fb-allegro-sync' ); ?></th>
				<th><?php esc_html_e( 'Művelet', 'fb-allegro-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $query->have_posts() ) : ?>
				<?php while ( $query->have_posts() ) : $query->the_post();
					$product_id = get_the_ID();
					$product    = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}
					$enabled  = FBAS_Product_Mapper::is_enabled_for_sync( $product_id );
					$offer_id = FBAS_Product_Mapper::get_offer_id( $product_id );
					$status   = get_post_meta( $product_id, FBAS_Product_Mapper::META_LAST_STATUS, true );
					$error    = get_post_meta( $product_id, FBAS_Product_Mapper::META_LAST_ERROR, true );
					?>
					<tr data-product-id="<?php echo esc_attr( $product_id ); ?>">
						<td><?php echo wp_kses_post( $product->get_image( array( 40, 40 ) ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $product->get_name() ); ?></strong><br />
							<small>SKU: <?php echo esc_html( $product->get_sku() ?: '—' ); ?></small>
						</td>
						<td><?php echo wp_kses_post( wc_price( $product->get_price() ) ); ?></td>
						<td><?php echo esc_html( $product->get_stock_quantity() ?? '—' ); ?></td>
						<td>
							<label class="fbas-switch">
								<input type="checkbox" class="fbas-toggle-sync" <?php checked( $enabled ); ?> />
								<span class="fbas-slider"></span>
							</label>
						</td>
						<td class="fbas-status-cell">
							<?php if ( $offer_id ) : ?>
								<span class="fbas-badge fbas-badge--<?php echo esc_attr( $status ?: 'info' ); ?>">
									<?php echo esc_html( $status ?: 'success' ); ?>
								</span>
								<br /><small>Offer ID: <?php echo esc_html( $offer_id ); ?></small>
								<?php if ( 'error' === $status && $error ) : ?>
									<br /><small class="fbas-error-text"><?php echo esc_html( $error ); ?></small>
								<?php endif; ?>
							<?php else : ?>
								<span class="fbas-badge fbas-badge--muted"><?php esc_html_e( 'Nincs szinkronizálva', 'fb-allegro-sync' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" class="button fbas-sync-now-btn"><?php esc_html_e( 'Szinkron most', 'fb-allegro-sync' ); ?></button>
							<?php if ( $offer_id ) : ?>
								<button type="button" class="button fbas-remove-offer-btn"><?php esc_html_e( 'Törlés Allegro-ról', 'fb-allegro-sync' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endwhile; wp_reset_postdata(); ?>
			<?php else : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Nincs találat.', 'fb-allegro-sync' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	$big = 999999999;
	echo '<div class="fbas-pagination">' . wp_kses_post( paginate_links( array(
		'base'    => str_replace( $big, '%#%', esc_url( add_query_arg( 'paged', $big ) ) ),
		'format'  => '?paged=%#%',
		'current' => max( 1, get_query_var( 'paged' ) ?: 1 ),
		'total'   => $query->max_num_pages,
	) ) ) . '</div>';
	?>
</div>
