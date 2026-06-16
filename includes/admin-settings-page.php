<?php
/**
 * Admin settings page markup.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . MTUC_ADMIN_PAGE_SLUG ) ); ?>">
		<?php wp_nonce_field( 'mtuc_save_settings', 'mtuc_settings_nonce' ); ?>
		<input type="hidden" name="mtuc_settings_submitted" value="1" />

		<table class="form-table" role="presentation">
			<tbody>
				<!-- Настройките ще бъдат добавени тук. -->
			</tbody>
		</table>

		<?php submit_button( __( 'Запази настройките', 'mtunicredit' ) ); ?>
	</form>
</div>
