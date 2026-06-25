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
$has_standard        = ! empty( $popup['has_standard'] );
$has_promo           = ! empty( $popup['has_promo'] );
$default_offer       = $has_standard ? 'standard' : ( $has_promo ? 'promo' : 'standard' );
$parva_row_class     = $show_first_vnoska ? '' : ' mtuc-popup__row--hidden';
$currency_dual_class = ! empty( $currency['dual'] ) ? ' mtuc-popup__value--dual' : '';
$offer_tabs_class    = ( $has_standard && $has_promo ) ? '' : ' mtuc-checkout-payment__offers--single';
?>
<div class="mtuc-checkout-payment" id="mtuc-checkout-payment">
	<input type="hidden" name="mtuc_offer_type" id="mtuc-checkout-offer-type" value="<?php echo esc_attr( $default_offer ); ?>" />
	<input type="hidden" name="mtuc_scheme_key" id="mtuc-checkout-scheme-key" value="" />
	<input type="hidden" name="mtuc_parva" id="mtuc-checkout-parva-hidden" value="0" />

	<div class="mtuc-checkout-payment__panel mtuc-popup__panel">
		<h3 class="mtuc-checkout-payment__title"><?php esc_html_e( 'Избор на схема за лизинг', 'mtunicredit' ); ?></h3>

		<?php if ( $has_standard || $has_promo ) : ?>
		<div class="mtuc-checkout-payment__offers<?php echo esc_attr( $offer_tabs_class ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Тип оферта', 'mtunicredit' ); ?>">
			<?php if ( $has_standard ) : ?>
				<button
					type="button"
					class="mtuc-checkout-payment__offer<?php echo 'standard' === $default_offer ? ' is-active' : ''; ?>"
					data-mtuc-checkout-offer="standard"
					role="tab"
					aria-selected="<?php echo 'standard' === $default_offer ? 'true' : 'false'; ?>"
				>
					<?php esc_html_e( 'Стандарт', 'mtunicredit' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( $has_promo ) : ?>
				<button
					type="button"
					class="mtuc-checkout-payment__offer<?php echo 'promo' === $default_offer ? ' is-active' : ''; ?>"
					data-mtuc-checkout-offer="promo"
					role="tab"
					aria-selected="<?php echo 'promo' === $default_offer ? 'true' : 'false'; ?>"
				>
					<?php esc_html_e( 'Промо 0%', 'mtunicredit' ); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php endif; ?>

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
						<select id="mtuc-checkout-months" name="mtuc_checkout_months_ui" class="mtuc-popup__select"></select>
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
	</div>
</div>
