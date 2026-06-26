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

$mtuc_gap              = (int) ( $context['gap'] ?? 0 );
$mtuc_logo_url         = (string) ( $context['logo_url'] ?? mtuc_get_uni_logo_url() );
$mtuc_is_dark_btn      = ! empty( $context['is_dark_button'] );
$mtuc_show_installment = ! empty( $context['show_installment'] );
$mtuc_buttons_in_row   = ! empty( $context['buttons_in_row'] );
$mtuc_button_width     = (int) ( $context['button_width'] ?? 290 );
$mtuc_button_height    = (int) ( $context['button_height'] ?? 56 );
$mtuc_standard         = isset( $context['standard'] ) && is_array( $context['standard'] ) ? $context['standard'] : null;
$mtuc_promo            = isset( $context['promo'] ) && is_array( $context['promo'] ) ? $context['promo'] : null;
$mtuc_root_classes     = 'mtuc-product-calculator';
$mtuc_root_style       = sprintf(
	'--mtuc-btn-width:%1$dpx;--mtuc-btn-height:%2$dpx;',
	$mtuc_button_width,
	$mtuc_button_height
);

if ( $mtuc_gap > 0 ) {
	$mtuc_root_style = 'margin-top:' . esc_attr( (string) $mtuc_gap ) . 'px;' . $mtuc_root_style;
}

if ( $mtuc_is_dark_btn ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--dark';
}

if ( ! $mtuc_show_installment ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--no-vnoska';
}

if ( ! $mtuc_buttons_in_row ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--stacked';
}

$mtuc_standard_visible = null !== $mtuc_standard && ! empty( $mtuc_standard['visible'] );
$mtuc_promo_visible    = null !== $mtuc_promo && ! empty( $mtuc_promo['visible'] );
$mtuc_standard_style   = $mtuc_standard_visible ? '' : 'display:none;';
$mtuc_promo_style      = $mtuc_promo_visible ? '' : 'display:none;';

if ( ! $mtuc_standard_visible && ! $mtuc_promo_visible ) {
	$mtuc_root_style .= 'display:none;';
}
?>
<div
	class="<?php echo esc_attr( $mtuc_root_classes ); ?>"
	style="<?php echo esc_attr( $mtuc_root_style ); ?>"
	data-mtuc-product-id="<?php echo esc_attr( (string) (int) ( $context['product_id'] ?? 0 ) ); ?>"
>
	<div class="mtuc-product-calculator__wrap">
		<button
			type="button"
			class="mtuc-product-calculator__btn mtuc-product-calculator__btn--standard"
			data-mtuc-offer="standard"
			style="<?php echo esc_attr( $mtuc_standard_style ); ?>"
		>
			<span class="mtuc-product-calculator__content">
				<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
				<?php if ( $mtuc_show_installment ) : ?>
					<span class="mtuc-product-calculator__price"><?php echo esc_html( (string) ( $mtuc_standard['price_text'] ?? '' ) ); ?></span>
				<?php endif; ?>
			</span>
			<span class="mtuc-product-calculator__logo">
				<img src="<?php echo esc_url( $mtuc_logo_url ); ?>" alt="<?php esc_attr_e( 'УниКредит', 'mtunicredit' ); ?>" />
			</span>
		</button>

		<button
			type="button"
			class="mtuc-product-calculator__btn mtuc-product-calculator__btn--promo"
			data-mtuc-offer="promo"
			style="<?php echo esc_attr( $mtuc_promo_style ); ?>"
		>
			<span class="mtuc-product-calculator__content">
				<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
				<?php if ( $mtuc_show_installment ) : ?>
					<span class="mtuc-product-calculator__price"><?php echo esc_html( (string) ( $mtuc_promo['price_text'] ?? '' ) ); ?></span>
				<?php endif; ?>
			</span>
			<span class="mtuc-product-calculator__promo-badge" aria-hidden="true">0%</span>
		</button>
	</div>
</div>
