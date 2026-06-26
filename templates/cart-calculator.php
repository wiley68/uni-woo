<?php
/**
 * Cart page calculator buttons.
 *
 * @package MTUC
 *
 * @var array<string, mixed> $context Cart calculator context from mtuc_get_cart_calculator_context().
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
$mtuc_root_classes     = 'mtuc-product-calculator mtuc-cart-calculator';
$mtuc_root_style       = sprintf(
	'--mtuc-btn-width:%1$dpx;--mtuc-btn-height:%2$dpx;',
	$mtuc_button_width,
	$mtuc_button_height
);

if ( $mtuc_gap > 0 ) {
	$mtuc_root_style = 'margin-bottom:' . esc_attr( (string) $mtuc_gap ) . 'px;' . $mtuc_root_style;
}

if ( $mtuc_is_dark_btn ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--dark';
}

if ( ! $mtuc_buttons_in_row ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--stacked';
}

$mtuc_standard_visible    = null !== $mtuc_standard && ! empty( $mtuc_standard['visible'] );
$mtuc_promo_visible       = null !== $mtuc_promo && ! empty( $mtuc_promo['visible'] );
$mtuc_standard_image_only = $mtuc_standard_visible && ! empty( $mtuc_standard['image_only'] );
$mtuc_promo_image_only    = $mtuc_promo_visible && ! empty( $mtuc_promo['image_only'] );
$mtuc_standard_style      = $mtuc_standard_visible ? '' : 'display:none;';
$mtuc_promo_style         = $mtuc_promo_visible ? '' : 'display:none;';

if ( ! $mtuc_standard_visible && ! $mtuc_promo_visible ) {
	$mtuc_root_style .= 'display:none;';
}

if ( $mtuc_standard_visible && ( $mtuc_standard_image_only || ! $mtuc_show_installment ) ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--no-vnoska';
}
?>
<div
	class="<?php echo esc_attr( $mtuc_root_classes ); ?>"
	style="<?php echo esc_attr( $mtuc_root_style ); ?>"
	data-mtuc-source="cart"
>
	<div class="mtuc-product-calculator__wrap">
		<button
			type="button"
			class="mtuc-product-calculator__btn mtuc-product-calculator__btn--standard<?php echo $mtuc_standard_image_only ? ' mtuc-product-calculator__btn--image-only' : ''; ?>"
			data-mtuc-offer="standard"
			data-mtuc-image-only="<?php echo $mtuc_standard_image_only ? '1' : '0'; ?>"
			style="<?php echo esc_attr( $mtuc_standard_style ); ?>"
		>
			<span class="mtuc-product-calculator__content">
				<?php if ( ! $mtuc_standard_image_only ) : ?>
					<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
					<?php if ( $mtuc_show_installment && ! empty( $mtuc_standard['price_text'] ) ) : ?>
						<span class="mtuc-product-calculator__price"><?php echo esc_html( (string) $mtuc_standard['price_text'] ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</span>
			<span class="mtuc-product-calculator__logo">
				<img src="<?php echo esc_url( $mtuc_logo_url ); ?>" alt="<?php esc_attr_e( 'УниКредит', 'mtunicredit' ); ?>" />
			</span>
		</button>

		<button
			type="button"
			class="mtuc-product-calculator__btn mtuc-product-calculator__btn--promo<?php echo $mtuc_promo_image_only ? ' mtuc-product-calculator__btn--image-only' : ''; ?>"
			data-mtuc-offer="promo"
			data-mtuc-image-only="<?php echo $mtuc_promo_image_only ? '1' : '0'; ?>"
			style="<?php echo esc_attr( $mtuc_promo_style ); ?>"
		>
			<span class="mtuc-product-calculator__content">
				<?php if ( ! $mtuc_promo_image_only ) : ?>
					<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
					<?php if ( $mtuc_show_installment && ! empty( $mtuc_promo['price_text'] ) ) : ?>
						<span class="mtuc-product-calculator__price"><?php echo esc_html( (string) $mtuc_promo['price_text'] ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</span>
			<span class="mtuc-product-calculator__promo-badge" aria-hidden="true">0%</span>
		</button>
	</div>
</div>
