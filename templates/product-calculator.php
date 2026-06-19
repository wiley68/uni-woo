<?php
/**
 * Product page calculator placeholder.
 *
 * @package MTUC
 *
 * @var array<string, mixed> $context Product calculator context from mtuc_get_product_calculator_context().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mtuc_gap           = (int) ( $context['gap'] ?? 0 );
$mtuc_logo_url      = (string) ( $context['logo_url'] ?? mtuc_get_uni_logo_url() );
$mtuc_is_dark_btn   = ! empty( $context['is_dark_button'] );
$mtuc_root_classes  = 'mtuc-product-calculator';
if ( $mtuc_is_dark_btn ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--dark';
}
?>
<div class="<?php echo esc_attr( $mtuc_root_classes ); ?>"<?php echo $mtuc_gap > 0 ? ' style="margin-top:' . esc_attr( (string) $mtuc_gap ) . 'px;"' : ''; ?>>
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
