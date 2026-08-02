<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $settings */
/** @var bool $is_connected */
?>
<div class="wrap fbas-wrap">
	<h1><?php esc_html_e( 'WP Allegro Sync – Beállítások', 'wp-allegro-sync' ); ?></h1>

	<?php if ( ! empty( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Beállítások elmentve.', 'wp-allegro-sync' ); ?></p></div>
	<?php endif; ?>

	<div class="fbas-grid">

		<div class="fbas-card">
			<h2><?php esc_html_e( '1. Allegro fejlesztői alkalmazás', 'wp-allegro-sync' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link */
					wp_kses_post( __( 'Hozz létre egy alkalmazást az <a href="%s" target="_blank" rel="noopener">Allegro fejlesztői konzoljában</a>, majd másold ide a Client ID / Client Secret párost.', 'wp-allegro-sync' ) ),
					'https://apps.developer.allegro.pl'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fbas_save_settings" />
				<?php wp_nonce_field( 'fbas_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="environment"><?php esc_html_e( 'Környezet', 'wp-allegro-sync' ); ?></label></th>
						<td>
							<select name="environment" id="environment">
								<option value="sandbox" <?php selected( $settings['environment'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (teszt)', 'wp-allegro-sync' ); ?></option>
								<option value="production" <?php selected( $settings['environment'], 'production' ); ?>><?php esc_html_e( 'Éles (production)', 'wp-allegro-sync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Először tesztelj sandbox környezetben, csak utána válts élesre.', 'wp-allegro-sync' ); ?></p>
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

				<h2><?php esc_html_e( '2. Ajánlat alapbeállításai', 'wp-allegro-sync' ); ?></h2>
				<p class="description">
					<?php if ( $is_connected ) : ?>
						<?php esc_html_e( 'A fiók össze van kötve, ezért a mezők mellett kereshetsz / lekérdezheted a listát a saját Allegro fiókodból - nem kell kézzel beírni az ID-kat.', 'wp-allegro-sync' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Miután lentebb összekötötted a fiókodat, a mezők mellett kereshetsz / lekérdezheted a listát az Allegro fiókodból.', 'wp-allegro-sync' ); ?>
					<?php endif; ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="offer_category_id"><?php esc_html_e( 'Allegro kategória ID', 'wp-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="offer_category_id" name="offer_category_id" value="<?php echo esc_attr( $settings['offer_category_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="category" data-target="#offer_category_id">
									<input type="search" class="regular-text fbas-lookup-query" placeholder="<?php esc_attr_e( 'pl. szablon malarski', 'wp-allegro-sync' ); ?>" />
									<button type="button" class="button fbas-lookup-search-btn"><?php esc_html_e( 'Keresés', 'wp-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_delivery_id"><?php esc_html_e( 'Szállítási sablon ID', 'wp-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="default_delivery_id" name="default_delivery_id" value="<?php echo esc_attr( $settings['default_delivery_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="shipping" data-target="#default_delivery_id">
									<button type="button" class="button fbas-lookup-list-btn"><?php esc_html_e( 'Sablonjaim lekérdezése', 'wp-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_warranty_id"><?php esc_html_e( 'Garancia ID (opcionális)', 'wp-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="default_warranty_id" name="default_warranty_id" value="<?php echo esc_attr( $settings['default_warranty_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="warranty" data-target="#default_warranty_id">
									<button type="button" class="button fbas-lookup-list-btn"><?php esc_html_e( 'Garanciáim lekérdezése', 'wp-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_return_id"><?php esc_html_e( 'Visszaküldési szabályzat ID (opcionális)', 'wp-allegro-sync' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="default_return_id" name="default_return_id" value="<?php echo esc_attr( $settings['default_return_id'] ); ?>" />
							<?php if ( $is_connected ) : ?>
								<div class="fbas-lookup" data-lookup="return" data-target="#default_return_id">
									<button type="button" class="button fbas-lookup-list-btn"><?php esc_html_e( 'Szabályzataim lekérdezése', 'wp-allegro-sync' ); ?></button>
									<ul class="fbas-lookup-results"></ul>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="price_markup_percent"><?php esc_html_e( 'Ár felár (%)', 'wp-allegro-sync' ); ?></label></th>
						<td><input type="number" step="0.01" id="price_markup_percent" name="price_markup_percent" value="<?php echo esc_attr( $settings['price_markup_percent'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( '3. Szinkron viselkedés', 'wp-allegro-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatikus szinkron', 'wp-allegro-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="auto_sync_enabled" value="1" <?php checked( $settings['auto_sync_enabled'], 'yes' ); ?> /> <?php esc_html_e( 'Termék mentésekor / készletváltozáskor azonnal frissítsen', 'wp-allegro-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'Ettől függetlenül 15 percenként fut egy háttér (cron) szinkron is a kijelölt termékekre, kötegelve (lásd lent).', 'wp-allegro-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="batch_size"><?php esc_html_e( 'Köteg méret (batch)', 'wp-allegro-sync' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" id="batch_size" name="batch_size" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" style="width:100px;" />
							<p class="description"><?php esc_html_e( 'Egy cron futás (15 percenként) ennyi terméket dolgoz fel egyszerre - nagyobb katalógusnál érdemes alacsonyan tartani az időtúllépés elkerülése végett. A rotáció mindig a legrégebben szinkronizált termékeket dolgozza fel elsőként.', 'wp-allegro-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ár szinkron', 'wp-allegro-sync' ); ?></th>
						<td><label><input type="checkbox" name="sync_price" value="1" <?php checked( $settings['sync_price'], 'yes' ); ?> /> <?php esc_html_e( 'Ár frissítése', 'wp-allegro-sync' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Készlet szinkron', 'wp-allegro-sync' ); ?></th>
						<td><label><input type="checkbox" name="sync_stock" value="1" <?php checked( $settings['sync_stock'], 'yes' ); ?> /> <?php esc_html_e( 'Készlet mennyiség frissítése', 'wp-allegro-sync' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button( __( 'Beállítások mentése', 'wp-allegro-sync' ) ); ?>
			</form>
		</div>

		<div class="fbas-card">
			<h2><?php esc_html_e( '4. Allegro fiók összekötése', 'wp-allegro-sync' ); ?></h2>

			<div id="fbas-connect-box" data-connected="<?php echo $is_connected ? '1' : '0'; ?>">
				<?php if ( $is_connected ) : ?>
					<p class="fbas-status fbas-status--ok">✅ <?php esc_html_e( 'Az Allegro fiók összekötve.', 'wp-allegro-sync' ); ?></p>
					<button type="button" class="button" id="fbas-disconnect-btn"><?php esc_html_e( 'Kapcsolat bontása', 'wp-allegro-sync' ); ?></button>
				<?php else : ?>
					<p class="fbas-status fbas-status--warn">⚠️ <?php esc_html_e( 'Még nincs összekötve az Allegro fiókkal.', 'wp-allegro-sync' ); ?></p>
					<button type="button" class="button button-primary" id="fbas-connect-btn"><?php esc_html_e( 'Összekötés az Allegro-val', 'wp-allegro-sync' ); ?></button>
				<?php endif; ?>

				<div id="fbas-device-code" class="fbas-device-code" style="display:none;">
					<p><?php esc_html_e( 'Nyisd meg az alábbi linket, jelentkezz be az Allegro fiókodba, és add meg ezt a kódot:', 'wp-allegro-sync' ); ?></p>
					<p class="fbas-user-code" id="fbas-user-code"></p>
					<p><a href="#" target="_blank" id="fbas-verification-link" class="button button-secondary"><?php esc_html_e( 'Megnyitás új lapon', 'wp-allegro-sync' ); ?></a></p>
					<p id="fbas-poll-status" class="description"></p>
				</div>
			</div>
		</div>

		<div class="fbas-card">
			<h2><?php esc_html_e( '5. Manuális szinkron', 'wp-allegro-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A "Termékek" oldalon jelöld ki, mely termékeket szeretnéd az Allegro-n árulni, majd itt indíthatsz azonnali szinkront - ez is a beállított köteg méret szerint fut (a legrégebben szinkronizáltak mennek elsőként).', 'wp-allegro-sync' ); ?></p>
			<button type="button" class="button button-primary" id="fbas-run-sync-btn"><?php esc_html_e( 'Szinkronizálás most', 'wp-allegro-sync' ); ?></button>
			<span id="fbas-run-sync-status" class="description"></span>
		</div>

		<div class="fbas-card fbas-card--wide">
			<h2><?php esc_html_e( '6. Kötelező kategória-paraméterek', 'wp-allegro-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Sok Allegro kategória kötelezővé tesz bizonyos mezőket (pl. vonalkód/EAN, márka, anyag) - ezek nélkül az ajánlat létrehozása hibával elutasul. Az alábbi gombbal lekérdezheted a beállított kategória kötelező (és opcionális) paramétereit, és itt egyből ki is töltheted azokat az értékeket, amik minden termékre egyformán vonatkoznak (pl. "Márka: Fountainbridge"). Ami termékenként változik (pl. szín, méret), azt a fejlesztőnek kell a `fbas_offer_parameters` szűrőn keresztül dinamikusan beállítania.', 'wp-allegro-sync' ); ?>
			</p>

			<?php if ( $is_connected ) : ?>
				<button type="button" class="button" id="fbas-load-params-btn"><?php esc_html_e( 'Kötelező paraméterek lekérdezése', 'wp-allegro-sync' ); ?></button>
				<div id="fbas-params-form" class="fbas-params-form"></div>
			<?php else : ?>
				<p class="fbas-status fbas-status--warn"><?php esc_html_e( 'Előbb kösd össze a fiókot az Allegro-val (lásd fent).', 'wp-allegro-sync' ); ?></p>
			<?php endif; ?>
		</div>

	</div>
</div>
