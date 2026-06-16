<?php
/**
 * Admin settings page markup and save handler.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mtuc_settings_saved = false;
$mtuc_settings_error = '';

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

	<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . MTUC_ADMIN_PAGE_SLUG ) ); ?>">
		<?php wp_nonce_field( 'mtuc_save_settings', 'mtuc_settings_nonce' ); ?>
		<input type="hidden" name="mtuc_settings_submitted" value="1" />

		<h2 class="title"><?php esc_html_e( 'Системни настройки', 'mtunicredit' ); ?></h2>

		<table class="form-table" role="presentation">
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
								<br /><?php esc_html_e( 'Оставете празно, за да запазите текущия секретен код.', 'mtunicredit' ); ?>
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
					<th scope="row"><?php esc_html_e( 'Директно добавяне на продукта в кошницата', 'mtunicredit' ); ?></th>
					<td>
						<label for="mtuc_cart">
							<input type="checkbox" name="<?php echo esc_attr( Mtuc_Settings::OPTION_CART ); ?>" id="mtuc_cart" value="1" <?php checked( 1, (int) $mtuc_settings[ Mtuc_Settings::OPTION_CART ] ); ?> />
							<?php esc_html_e( 'Ако изберете тази опция, при натискане на бутона на калкулатора в продуктовата страница, избрания продукт директно ще се добавя в кошницата.', 'mtunicredit' ); ?>
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

		<?php submit_button( __( 'Запази настройките', 'mtunicredit' ) ); ?>
	</form>
</div>
