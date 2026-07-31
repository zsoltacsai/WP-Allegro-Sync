<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $settings */
/** @var bool $is_connected */
?>
<div class="wrap fbas-wrap">
	<h1><?php esc_html_e( 'Fountainbridge Allegro Sync – Beállítások', 'fb-allegro-sync' ); ?></h1>

	<?php if ( ! empty( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Beállítások elmentve.', 'fb-allegro-sync' ); ?></p></div>
	<?php endif; ?>

	<div class="fbas-grid">

		<div class="fbas-card">
			<h2><?php esc_html_e( '1. Allegro fejlesztői alkalmazás', 'fb-allegro-sync' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link */
					wp_kses_post( __( 'Hozz létre egy alkalmazást az <a href="%s" target="_blank" rel="noopener">Allegro fejlesztői konzoljában</a>, majd másold ide a Client ID / Client Secret párost.', 'fb-allegro-sync' ) ),
					'https://apps.developer.allegro.pl'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fbas_save_settings" />
				<?php wp_nonce_field( 'fbas_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="environment"><?php esc_html_e( 'Környezet', 'fb-allegro-sync' ); ?></label></th>
						<td>
							<select name="environment" id="environment">
								<option value="sandbox" <?php selected( $settings['environment'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (teszt)', 'fb-allegro-sync' ); ?></option>
								<option value="production" <?php selected( $settings['environment'], 'production' ); ?>><?php esc_html_e( 'Éles (production)', 'fb-allegro-sync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Először tesztelj sandbox környezetben, csak utána válts élesre.', 'fb-allegro-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="client_id">Client ID</label></th>
						<td><input type="text" class="regular-text" id="client_id" name="client_id" value="<?php echo esc_attr( $settings['client_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="client_secret">Client Secret</label></th>
						<td><input type="password" class="regular-text" id="client_secret" name="client_secret" value="<?php echo esc_attr( $settings['client_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( '2. Ajánlat alapbeállításai', 'fb-allegro-sync' ); ?></h2>
				<p class="description">
					<?php if ( $is_connected ) : ?>
						<?php esc_html_e( 'A fiók össze van kötve, ezért a mezők mellett kereshetsz / lekérdezheted a listát a saját Allegro fiókodból - nem kell kézzel beírni az ID-kat.', 'fb-allegro-sync' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Miután lentebb összekötötted a fiókodat, a mezők mellett kereshetsz / lekérdezheted a listát az Allegro fiókodból.', 'fb-allegro-sync' ); ?>
					<?php endif; ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="offer_category_id"><?php esc_html_e( 'Allegro kategória ID', 'fb-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="offer_category_id" name="offer_category_id" value="<?php echo esc_attr( $settings['offer_category_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="category" data-target="#offer_category_id">
									<input type="search" class="regular-text fbas-lookup-query" placeholder="<?php esc_attr_e( 'pl. szablon malarski', 'fb-allegro-sync' ); ?>" />
									<button type="button" class="button fbas-lookup-search-btn"><?php esc_html_e( 'Keresés', 'fb-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_delivery_id"><?php esc_html_e( 'Szállítási sablon ID', 'fb-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="default_delivery_id" name="default_delivery_id" value="<?php echo esc_attr( $settings['default_delivery_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="shipping" data-target="#default_delivery_id">
									<button type="button" class="button fbas-lookup-list-btn"><?php esc_html_e( 'Sablonjaim lekérdezése', 'fb-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_warranty_id"><?php esc_html_e( 'Garancia ID (opcionális)', 'fb-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="default_warranty_id" name="default_warranty_id" value="<?php echo esc_attr( $settings['default_warranty_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="warranty" data-target="#default_warranty_id">
									<button type="button" class="button fbas-lookup-list-btn"><?php esc_html_e( 'Garanciáim lekérdezése', 'fb-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_return_id"><?php esc_html_e( 'Visszaküldési szabályzat ID (opcionális)', 'fb-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="default_return_id" name="default_return_id" value="<?php echo esc_attr( $settings['default_return_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="return" data-target="#default_return_id">
									<button type="button" class="button fbas-lookup-list-btn"><?php esc_html_e( 'Szabályzataim lekérdezése', 'fb-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="price_markup_percent"><?php esc_html_e( 'Ár felár (%)', 'fb-allegro-sync' ); ?></label></th>
						<td><input type="number" step="0.01" id="price_markup_percent" name="price_markup_percent" value="<?php echo esc_attr( $settings['price_markup_percent'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( '3. Szinkron viselkedés', 'fb-allegro-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatikus szinkron', 'fb-allegro-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="auto_sync_enabled" value="1" <?php checked( $settings['auto_sync_enabled'], 'yes' ); ?> /> <?php esc_html_e( 'Termék mentésekor / készletváltozáskor azonnal frissítsen', 'fb-allegro-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'Ettől függetlenül 15 percenként fut egy háttér (cron) szinkron is a kijelölt termékekre.', 'fb-allegro-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ár szinkron', 'fb-allegro-sync' ); ?></th>
						<td><label><input type="checkbox" name="sync_price" value="1" <?php checked( $settings['sync_price'], 'yes' ); ?> /> <?php esc_html_e( 'Ár frissítése', 'fb-allegro-sync' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Készlet szinkron', 'fb-allegro-sync' ); ?></th>
						<td><label><input type="checkbox" name="sync_stock" value="1" <?php checked( $settings['sync_stock'], 'yes' ); ?> /> <?php esc_html_e( 'Készlet mennyiség frissítése', 'fb-allegro-sync' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button( __( 'Beállítások mentése', 'fb-allegro-sync' ) ); ?>
			</form>
		</div>

		<div class="fbas-card">
			<h2><?php esc_html_e( '4. Allegro fiók összekötése', 'fb-allegro-sync' ); ?></h2>

			<div id="fbas-connect-box" data-connected="<?php echo $is_connected ? '1' : '0'; ?>">
				<?php if ( $is_connected ) : ?>
					<p class="fbas-status fbas-status--ok">✅ <?php esc_html_e( 'Az Allegro fiók összekötve.', 'fb-allegro-sync' ); ?></p>
					<button type="button" class="button" id="fbas-disconnect-btn"><?php esc_html_e( 'Kapcsolat bontása', 'fb-allegro-sync' ); ?></button>
				<?php else : ?>
					<p class="fbas-status fbas-status--warn">⚠️ <?php esc_html_e( 'Még nincs összekötve az Allegro fiókkal.', 'fb-allegro-sync' ); ?></p>
					<button type="button" class="button button-primary" id="fbas-connect-btn"><?php esc_html_e( 'Összekötés az Allegro-val', 'fb-allegro-sync' ); ?></button>
				<?php endif; ?>

				<div id="fbas-device-code" class="fbas-device-code" style="display:none;">
					<p><?php esc_html_e( 'Nyisd meg az alábbi linket, jelentkezz be az Allegro fiókodba, és add meg ezt a kódot:', 'fb-allegro-sync' ); ?></p>
					<p class="fbas-user-code" id="fbas-user-code"></p>
					<p><a href="#" target="_blank" id="fbas-verification-link" class="button button-secondary"><?php esc_html_e( 'Megnyitás új lapon', 'fb-allegro-sync' ); ?></a></p>
					<p id="fbas-poll-status" class="description"></p>
				</div>
			</div>
		</div>

		<div class="fbas-card">
			<h2><?php esc_html_e( '5. Manuális szinkron', 'fb-allegro-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A "Termékek" oldalon jelöld ki, mely termékeket szeretnéd az Allegro-n árulni, majd itt indíthatsz azonnali teljes szinkront.', 'fb-allegro-sync' ); ?></p>
			<button type="button" class="button button-primary" id="fbas-run-sync-btn"><?php esc_html_e( 'Szinkronizálás most', 'fb-allegro-sync' ); ?></button>
			<span id="fbas-run-sync-status" class="description"></span>
		</div>

	</div>
</div>
