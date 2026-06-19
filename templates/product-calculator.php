<?php
/**
 * Product page calculator placeholder.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mtuc_gap      = (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_GAP );
$mtuc_logo_url = mtuc_get_product_button_logo_url();
?>
<div class="mtuc-product-calculator"<?php echo $mtuc_gap > 0 ? ' style="margin-top:' . esc_attr( (string) $mtuc_gap ) . 'px;"' : ''; ?>>
	<div class="mtuc-product-calculator__wrap">
		<?php for ( $mtuc_btn_index = 0; $mtuc_btn_index < 2; $mtuc_btn_index++ ) : ?>
			<button type="button" class="mtuc-product-calculator__btn">
				<span class="mtuc-product-calculator__content">
					<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
					<span class="mtuc-product-calculator__price"><?php esc_html_e( '12 x 93.33 евро (182.54 лв.)', 'mtunicredit' ); ?></span>
				</span>
				<span class="mtuc-product-calculator__logo">
					<img src="<?php echo esc_url( $mtuc_logo_url ); ?>" alt="<?php esc_attr_e( 'УниКредит', 'mtunicredit' ); ?>" />
				</span>
			</button>
		<?php endfor; ?>
	</div>
</div>
