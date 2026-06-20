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

$mtuc_gap          = (int) ( $context['gap'] ?? 0 );
$mtuc_logo_url     = (string) ( $context['logo_url'] ?? mtuc_get_uni_logo_url() );
$mtuc_is_dark_btn  = ! empty( $context['is_dark_button'] );
$mtuc_standard     = isset( $context['standard'] ) && is_array( $context['standard'] ) ? $context['standard'] : null;
$mtuc_promo        = isset( $context['promo'] ) && is_array( $context['promo'] ) ? $context['promo'] : null;
$mtuc_root_classes = 'mtuc-product-calculator';

if ( $mtuc_is_dark_btn ) {
	$mtuc_root_classes .= ' mtuc-product-calculator--dark';
}
?>
<div class="<?php echo esc_attr( $mtuc_root_classes ); ?>"<?php echo $mtuc_gap > 0 ? ' style="margin-top:' . esc_attr( (string) $mtuc_gap ) . 'px;"' : ''; ?>>
	<div class="mtuc-product-calculator__wrap">
		<?php if ( null !== $mtuc_standard && ! empty( $mtuc_standard['visible'] ) ) : ?>
			<button type="button" class="mtuc-product-calculator__btn mtuc-product-calculator__btn--standard">
				<span class="mtuc-product-calculator__content">
					<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
					<span class="mtuc-product-calculator__price"><?php echo esc_html( (string) ( $mtuc_standard['price_text'] ?? '' ) ); ?></span>
				</span>
				<span class="mtuc-product-calculator__logo">
					<img src="<?php echo esc_url( $mtuc_logo_url ); ?>" alt="<?php esc_attr_e( 'УниКредит', 'mtunicredit' ); ?>" />
				</span>
			</button>
		<?php endif; ?>

		<?php if ( null !== $mtuc_promo && ! empty( $mtuc_promo['visible'] ) ) : ?>
			<button type="button" class="mtuc-product-calculator__btn mtuc-product-calculator__btn--promo">
				<span class="mtuc-product-calculator__content">
					<span class="mtuc-product-calculator__label"><?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?></span>
					<span class="mtuc-product-calculator__price"><?php echo esc_html( (string) ( $mtuc_promo['price_text'] ?? '' ) ); ?></span>
				</span>
				<span class="mtuc-product-calculator__logo">
					<img src="<?php echo esc_url( $mtuc_logo_url ); ?>" alt="<?php esc_attr_e( 'УниКредит', 'mtunicredit' ); ?>" />
				</span>
			</button>
		<?php endif; ?>
	</div>
</div>
