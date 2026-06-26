<?php
/**
 * Checkout payment method scheme fields.
 *
 * @package MTUC
 *
 * @var array<string, mixed> $context Checkout payment context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$popup               = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();
$show_first_vnoska   = ! empty( $popup['show_first_vnoska'] );
$currency            = isset( $popup['currency'] ) && is_array( $popup['currency'] ) ? $popup['currency'] : mtuc_get_currency_display_config( array( 'uni_eur' => 0 ) );
$has_schemes         = ! empty( $popup['has_schemes'] );
$enabled_schemes     = isset( $popup['enabled_schemes'] ) && is_array( $popup['enabled_schemes'] )
	? array_values( $popup['enabled_schemes'] )
	: array();
$default_scheme_key  = isset( $popup['default_scheme_key'] ) ? (string) $popup['default_scheme_key'] : '';
$data_config         = mtuc_build_checkout_payment_fields_data_config( $popup );
$parva_row_class     = $show_first_vnoska ? '' : ' mtuc-popup__row--hidden';
$currency_dual_class = ! empty( $currency['dual'] ) ? ' mtuc-popup__value--dual' : '';
?>
<div
	class="mtuc-checkout-payment"
	id="mtuc-checkout-payment"
	data-mtuc-config="<?php echo esc_attr( $data_config ); ?>"
>
	<input type="hidden" name="mtuc_offer_type" id="mtuc-checkout-offer-type" value="standard" />
	<input type="hidden" name="mtuc_scheme_key" id="mtuc-checkout-scheme-key" value="<?php echo esc_attr( $default_scheme_key ); ?>" />
	<input type="hidden" name="mtuc_parva" id="mtuc-checkout-parva-hidden" value="0" />

	<div class="mtuc-checkout-payment__panel mtuc-popup__panel">
		<p class="mtuc-checkout-payment__intro"><?php esc_html_e( 'Можете да изберете \'Срок за кредита\', предпочитаната от Вас \'Месечна вноска\', както и при желание \'Първоначална вноска\'. След което да потвърдите избора си. Ще бъдете прехвърлени към страницата на UniCredit за довършване на покупката си на кредит.', 'mtunicredit' ); ?></p>

		<?php if ( ! $has_schemes ) : ?>
			<p class="mtuc-checkout-payment__notice"><?php esc_html_e( 'Няма налични схеми за текущата поръчка.', 'mtunicredit' ); ?></p>
		<?php else : ?>
		<div class="mtuc-popup__calc">
			<div class="mtuc-popup__calc-fields">
				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label"><?php esc_html_e( 'Цена на поръчката', 'mtunicredit' ); ?></div>
					<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
						<span id="mtuc-checkout-price-primary" class="mtuc-popup__amount-primary"></span>
						<span id="mtuc-checkout-price-secondary" class="mtuc-popup__amount-secondary"></span>
					</div>
				</div>

				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label">
						<label for="mtuc-checkout-months"><?php esc_html_e( 'Брой месеци за погасяване', 'mtunicredit' ); ?></label>
					</div>
					<div class="mtuc-popup__value">
						<select id="mtuc-checkout-months" name="mtuc_checkout_months_ui" class="mtuc-popup__select">
							<?php foreach ( $enabled_schemes as $scheme_index => $scheme_option ) : ?>
								<?php
								if ( ! is_array( $scheme_option ) ) {
									continue;
								}
								$scheme_key = isset( $scheme_option['key'] ) ? (string) $scheme_option['key'] : '';
								if ( '' === $scheme_key ) {
									continue;
								}
								$is_selected = ( '' !== $default_scheme_key && $scheme_key === $default_scheme_key )
									|| ( '' === $default_scheme_key && 0 === (int) $scheme_index );
								?>
								<option value="<?php echo esc_attr( $scheme_key ); ?>"<?php selected( $is_selected ); ?>>
									<?php echo esc_html( mtuc_format_checkout_scheme_option_label( $scheme_option ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="mtuc-popup__row<?php echo esc_attr( $parva_row_class ); ?>" id="mtuc-checkout-parva-row">
					<div class="mtuc-popup__label">
						<label for="mtuc-checkout-parva"><?php esc_html_e( 'Първоначална вноска /евро/', 'mtunicredit' ); ?></label>
					</div>
					<div class="mtuc-popup__value">
						<input type="number" min="0" step="0.01" id="mtuc-checkout-parva" class="mtuc-popup__input" value="0" />
					</div>
				</div>

				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label"><?php esc_html_e( 'Обща сума на заема', 'mtunicredit' ); ?></div>
					<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
						<span id="mtuc-checkout-loan-primary" class="mtuc-popup__amount-primary"></span>
						<span id="mtuc-checkout-loan-secondary" class="mtuc-popup__amount-secondary"></span>
					</div>
				</div>

				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label"><?php esc_html_e( 'Размер на погасителна вноска', 'mtunicredit' ); ?></div>
					<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
						<span id="mtuc-checkout-monthly-primary" class="mtuc-popup__amount-primary"></span>
						<span id="mtuc-checkout-monthly-secondary" class="mtuc-popup__amount-secondary"></span>
					</div>
				</div>

				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label"><?php esc_html_e( 'Обща дължима сума', 'mtunicredit' ); ?></div>
					<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
						<span id="mtuc-checkout-total-primary" class="mtuc-popup__amount-primary"></span>
						<span id="mtuc-checkout-total-secondary" class="mtuc-popup__amount-secondary"></span>
					</div>
				</div>

				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label"><?php esc_html_e( 'ГЛП', 'mtunicredit' ); ?></div>
					<div class="mtuc-popup__value">
						<span id="mtuc-checkout-glp" class="mtuc-popup__percent"></span>
					</div>
				</div>

				<div class="mtuc-popup__row">
					<div class="mtuc-popup__label"><?php esc_html_e( 'ГПР', 'mtunicredit' ); ?></div>
					<div class="mtuc-popup__value">
						<span id="mtuc-checkout-gpr" class="mtuc-popup__percent"></span>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>
