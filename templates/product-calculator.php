<?php
/**
 * Product page calculator placeholder.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mtuc_gap = (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_GAP );
?>
<div class="mtuc-product-calculator"<?php echo $mtuc_gap > 0 ? ' style="margin-top:' . esc_attr( (string) $mtuc_gap ) . 'px;"' : ''; ?>>
	<div class="mtuc-product-calculator__wrap">
		<button type="button" class="mtuc-product-calculator__btn">
			<?php esc_html_e( 'УниКредит', 'mtunicredit' ); ?>
		</button>
		<button type="button" class="mtuc-product-calculator__btn">
			<?php esc_html_e( 'УниКредит', 'mtunicredit' ); ?>
		</button>
	</div>
</div>
