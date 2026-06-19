<?php
/**
 * Admin settings page markup and save handler.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mtuc_settings_saved   = false;
$mtuc_settings_error   = '';
$mtuc_shop_refreshed   = false;
$mtuc_shop_refresh_err = '';

if (
	isset( $_POST['mtuc_refresh_shop'] )
	&& '1' === $_POST['mtuc_refresh_shop']
	&& check_admin_referer( 'mtuc_refresh_shop', 'mtuc_refresh_shop_nonce' )
	&& current_user_can( 'manage_options' )
) {
	$result = Mtuc_Shop_Cache::refresh_from_api();

	if ( is_wp_error( $result ) ) {
		$mtuc_shop_refresh_err = $result->get_error_message();
	} else {
		$mtuc_shop_refreshed = true;
	}
}

if (
	isset( $_POST['mtuc_settings_submitted'] )
	&& '1' === $_POST['mtuc_settings_submitted']
	&& check_admin_referer( 'mtuc_save_settings', 'mtuc_settings_nonce' )
	&& current_user_can( 'manage_options' )
) {
	$result = Mtuc_Settings::save_from_post( wp_unslash( $_POST ) );

	if ( is_wp_error( $result ) ) {
		$mtuc_settings_error = $result->get_error_message();
	} else {
		$mtuc_settings_saved = true;
	}
}

$mtuc_settings = Mtuc_Settings::get_all();
$mtuc_hooks    = Mtuc_Settings::get_hook_choices();
$mtuc_cache    = Mtuc_Shop_Cache::get_cache_meta( (string) $mtuc_settings[ Mtuc_Settings::OPTION_UNICID ] );

?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $mtuc_settings_saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><strong><?php esc_html_e( 'Настройките са записани успешно.', 'mtunicredit' ); ?></strong></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $mtuc_settings_error ) : ?>
		<div class="notice notice-error">
			<p><strong><?php echo esc_html( $mtuc_settings_error ); ?></strong></p>
		</div>
	<?php endif; ?>

	<?php if ( $mtuc_shop_refreshed ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><strong><?php esc_html_e( 'Данните от банката са обновени успешно.', 'mtunicredit' ); ?></strong></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $mtuc_shop_refresh_err ) : ?>
		<div class="notice notice-error">
			<p><strong><?php echo esc_html( $mtuc_shop_refresh_err ); ?></strong></p>
		</div>
	<?php endif; ?>

	<?php if ( is_array( $mtuc_cache ) ) : ?>
		<?php
		$mtuc_datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$mtuc_fetched_at      = get_date_from_gmt( $mtuc_cache['fetched_at'], $mtuc_datetime_format );
		$mtuc_expires_at      = get_date_from_gmt( $mtuc_cache['expires_at'], $mtuc_datetime_format );
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: fetched datetime, 2: expires datetime (store timezone) */
					__( 'Кеш на shop данни: зареден на %1$s, валиден до %2$s.', 'mtunicredit' ),
					$mtuc_fetched_at,
					$mtuc_expires_at
				)
			);
			?>
		</p>
	<?php endif; ?>

	<form method="post" id="mtuc-settings-form" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . MTUC_ADMIN_PAGE_SLUG ) ); ?>">
		<?php wp_nonce_field( 'mtuc_save_settings', 'mtuc_settings_nonce' ); ?>
		<input type="hidden" name="mtuc_settings_submitted" value="1" />

		<h2 class="title"><?php esc_html_e( 'Системни настройки', 'mtunicredit' ); ?></h2>

		<table class="form-table mtuc-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?></th>
					<td>
						<label for="mtuc_status">
							<input type="checkbox" name="<?php echo esc_attr( Mtuc_Settings::OPTION_STATUS ); ?>" id="mtuc_status" value="1" <?php checked( 1, (int) $mtuc_settings[ Mtuc_Settings::OPTION_STATUS ] ); ?> />
							<?php esc_html_e( 'Дава възможност на Вашите клиенти да закупуват стока на изплащане с УниКредит.', 'mtunicredit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="mtuc_unicid"><?php esc_html_e( 'Уникален идентификационен код на магазина Ви', 'mtunicredit' ); ?></label>
					</th>
					<td>
						<input type="text" class="regular-text" name="<?php echo esc_attr( Mtuc_Settings::OPTION_UNICID ); ?>" id="mtuc_unicid" value="<?php echo esc_attr( $mtuc_settings[ Mtuc_Settings::OPTION_UNICID ] ); ?>" maxlength="36" required />
						<p class="description"><?php esc_html_e( 'Уникален идентификационен код на магазина Ви в системата на УниКредит.', 'mtunicredit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="mtuc_secret_key"><?php esc_html_e( 'Секретен код на магазина Ви', 'mtunicredit' ); ?></label>
					</th>
					<td>
						<input type="password" class="regular-text" name="<?php echo esc_attr( Mtuc_Settings::OPTION_SECRET_KEY ); ?>" id="mtuc_secret_key" value="" maxlength="64" autocomplete="new-password" <?php echo '' === $mtuc_settings[ Mtuc_Settings::OPTION_SECRET_KEY ] ? 'required' : ''; ?> />
						<p class="description">
							<?php esc_html_e( 'Секретен код на магазина Ви в системата на УниКредит.', 'mtunicredit' ); ?>
							<?php if ( '' !== $mtuc_settings[ Mtuc_Settings::OPTION_SECRET_KEY ] ) : ?>
								<?php esc_html_e( 'Оставете празно, за да запазите текущия секретен код.', 'mtunicredit' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="mtuc_hook"><?php esc_html_e( 'Място на бутона', 'mtunicredit' ); ?></label>
					</th>
					<td>
						<select name="<?php echo esc_attr( Mtuc_Settings::OPTION_HOOK ); ?>" id="mtuc_hook">
							<?php foreach ( $mtuc_hooks as $hook_value => $hook_label ) : ?>
								<option value="<?php echo esc_attr( $hook_value ); ?>" <?php selected( $mtuc_settings[ Mtuc_Settings::OPTION_HOOK ], $hook_value ); ?>>
									<?php echo esc_html( $hook_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Място за показване на бутона на УниКредит в продуктовата страница (hook).', 'mtunicredit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Визуализиране на реклама', 'mtunicredit' ); ?></th>
					<td>
						<label for="mtuc_reklama">
							<input type="checkbox" name="<?php echo esc_attr( Mtuc_Settings::OPTION_REKLAMA ); ?>" id="mtuc_reklama" value="1" <?php checked( 1, (int) $mtuc_settings[ Mtuc_Settings::OPTION_REKLAMA ] ); ?> />
							<?php esc_html_e( 'Можете да включвате или изключвате показването на реклама в началната страница на магазина.', 'mtunicredit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Режим отстраняване на грешки', 'mtunicredit' ); ?></th>
					<td>
						<label for="mtuc_debug">
							<input type="checkbox" name="<?php echo esc_attr( Mtuc_Settings::OPTION_DEBUG ); ?>" id="mtuc_debug" value="1" <?php checked( 1, (int) $mtuc_settings[ Mtuc_Settings::OPTION_DEBUG ] ); ?> />
							<?php esc_html_e( 'Моля изберете тази опция ако искате да включите режима за отстраняване на грешки.', 'mtunicredit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="mtuc_gap"><?php esc_html_e( 'Свободно място над бутона', 'mtunicredit' ); ?></label>
					</th>
					<td>
						<input type="number" class="small-text" name="<?php echo esc_attr( Mtuc_Settings::OPTION_GAP ); ?>" id="mtuc_gap" value="<?php echo esc_attr( (int) $mtuc_settings[ Mtuc_Settings::OPTION_GAP ] ); ?>" min="0" max="200" step="1" />
						<span><?php esc_html_e( 'px', 'mtunicredit' ); ?></span>
						<p class="description"><?php esc_html_e( 'Свободно място над бутона в px.', 'mtunicredit' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
	</form>

	<div class="submit" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding-left:0;">
		<?php
		submit_button(
			__( 'Запази настройките', 'mtunicredit' ),
			'primary',
			'mtuc_save_settings',
			false,
			array( 'form' => 'mtuc-settings-form' )
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . MTUC_ADMIN_PAGE_SLUG ) ); ?>" style="margin:0;">
			<?php wp_nonce_field( 'mtuc_refresh_shop', 'mtuc_refresh_shop_nonce' ); ?>
			<input type="hidden" name="mtuc_refresh_shop" value="1" />
			<?php submit_button( __( 'Обнови данните от банката', 'mtunicredit' ), 'secondary', 'mtuc_refresh_shop_submit', false ); ?>
		</form>
	</div>
</div>
