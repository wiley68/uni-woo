<?php
/**
 * Product credit popup — two-step flow.
 *
 * @package MTUC
 *
 * @var array<string, mixed> $context Product calculator context.
 * @var array<string, mixed> $popup   Popup context from mtuc_get_product_popup_context().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$popup                    = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();
$show_first_vnoska        = ! empty( $popup['show_first_vnoska'] );
$currency            = isset( $popup['currency'] ) && is_array( $popup['currency'] ) ? $popup['currency'] : mtuc_get_currency_display_config( array( 'uni_eur' => 0 ) );
$customer            = isset( $popup['customer'] ) && is_array( $popup['customer'] ) ? $popup['customer'] : mtuc_get_popup_customer_defaults();
$banner_url          = isset( $popup['banner_url'] ) ? (string) $popup['banner_url'] : '';
$reklama_url         = isset( $popup['reklama_url'] ) ? (string) $popup['reklama_url'] : '';
$product_id          = (int) ( $popup['product_id'] ?? 0 );
$logo_url            = mtuc_get_uni_logo_url();
$parva_row_class     = $show_first_vnoska ? '' : ' mtuc-popup__row--hidden';
$currency_dual_class = ! empty( $currency['dual'] ) ? ' mtuc-popup__value--dual' : '';
?>
<div id="mtuc-product-popup" class="mtuc-popup" aria-hidden="true" hidden>
	<div class="mtuc-popup__overlay" data-mtuc-popup-close></div>
	<div class="mtuc-popup__dialog" role="dialog" aria-modal="true" aria-labelledby="mtuc-popup-title">
		<div class="mtuc-popup__content">
			<div class="mtuc-popup__body">
				<?php if ( '' !== $banner_url ) : ?>
					<div class="mtuc-popup__banner">
						<?php if ( '' !== $reklama_url ) : ?>
							<a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $reklama_url ); ?>">
						<?php endif; ?>
						<img
							class="mtuc-popup__banner-image"
							src="<?php echo esc_url( $banner_url ); ?>"
							alt="<?php esc_attr_e( 'УниКредит покупки на кредит', 'mtunicredit' ); ?>"
						/>
						<?php if ( '' !== $reklama_url ) : ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="mtuc-popup__panel">
					<div id="mtuc-popup-step-1" class="mtuc-popup__step mtuc-popup__step--active">
						<h2 id="mtuc-popup-title" class="mtuc-popup__step-title"><?php esc_html_e( 'Избор на схема за лизинг', 'mtunicredit' ); ?></h2>

						<div class="mtuc-popup__calc">
							<div class="mtuc-popup__calc-logo" aria-hidden="true"></div>
							<div class="mtuc-popup__calc-fields">
								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'Цена на продукта', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
										<span id="mtuc-popup-price-primary" class="mtuc-popup__amount-primary"></span>
										<span id="mtuc-popup-price-secondary" class="mtuc-popup__amount-secondary"></span>
									</div>
								</div>

								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label">
										<span class="mtuc-popup__label-desktop"><?php esc_html_e( 'Срок на заема (месеци)', 'mtunicredit' ); ?></span>
										<span class="mtuc-popup__label-mobile"><?php esc_html_e( 'Срок (мес.)', 'mtunicredit' ); ?></span>
									</div>
									<div class="mtuc-popup__value">
										<select id="mtuc-popup-months" class="mtuc-popup__select"></select>
									</div>
								</div>

								<div class="mtuc-popup__row<?php echo esc_attr( $parva_row_class ); ?>" id="mtuc-popup-parva-row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'Първоначална вноска', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value">
										<input
											type="number"
											min="0"
											step="0.01"
											id="mtuc-popup-parva"
											class="mtuc-popup__input"
											value="0"
										/>
									</div>
								</div>

								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'Обща сума на заема', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
										<span id="mtuc-popup-loan-primary" class="mtuc-popup__amount-primary"></span>
										<span id="mtuc-popup-loan-secondary" class="mtuc-popup__amount-secondary"></span>
									</div>
								</div>

								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'Месечна вноска', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
										<span id="mtuc-popup-monthly-primary" class="mtuc-popup__amount-primary"></span>
										<span id="mtuc-popup-monthly-secondary" class="mtuc-popup__amount-secondary"></span>
									</div>
								</div>

								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'Обща дължима сума', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value<?php echo esc_attr( $currency_dual_class ); ?>">
										<span id="mtuc-popup-total-primary" class="mtuc-popup__amount-primary"></span>
										<span id="mtuc-popup-total-secondary" class="mtuc-popup__amount-secondary"></span>
									</div>
								</div>

								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'ГЛП', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value">
										<span id="mtuc-popup-glp" class="mtuc-popup__percent"></span>
									</div>
								</div>

								<div class="mtuc-popup__row">
									<div class="mtuc-popup__label"><?php esc_html_e( 'ГПР', 'mtunicredit' ); ?></div>
									<div class="mtuc-popup__value">
										<span id="mtuc-popup-gpr" class="mtuc-popup__percent"></span>
									</div>
								</div>

								<div class="mtuc-popup__row mtuc-popup__row--note">
									<div class="mtuc-popup__help"><?php esc_html_e( '* Срокът на изплащане се заявява при приключване на поръчката.', 'mtunicredit' ); ?></div>
								</div>
							</div>
						</div>

						<div class="mtuc-popup__actions mtuc-popup__actions--step1">
							<button type="button" class="mtuc-popup__btn mtuc-popup__btn--secondary" data-mtuc-popup-close>
								<?php esc_html_e( 'Откажи', 'mtunicredit' ); ?>
							</button>
							<button type="button" class="mtuc-popup__btn mtuc-popup__btn--secondary" id="mtuc-popup-add-to-cart">
								<?php esc_html_e( 'Добави в количката', 'mtunicredit' ); ?>
							</button>
							<button type="button" class="mtuc-popup__btn mtuc-popup__btn--primary" id="mtuc-popup-buy">
								<span class="mtuc-popup__btn-badge" style="background-image:url('<?php echo esc_url( $logo_url ); ?>')"></span>
								<?php esc_html_e( 'Купи на изплащане', 'mtunicredit' ); ?>
							</button>
						</div>
					</div>

					<div id="mtuc-popup-step-2" class="mtuc-popup__step" hidden>
						<h2 class="mtuc-popup__step-title"><?php esc_html_e( 'Попълване на лични данни', 'mtunicredit' ); ?></h2>

						<div class="mtuc-popup__form">
							<div class="mtuc-popup__field">
								<label class="mtuc-popup__field-label" for="mtuc-popup-first-name"><?php esc_html_e( 'Име', 'mtunicredit' ); ?></label>
								<input type="text" id="mtuc-popup-first-name" class="mtuc-popup__input mtuc-popup__input--text" value="<?php echo esc_attr( (string) ( $customer['first_name'] ?? '' ) ); ?>" />
							</div>
							<div class="mtuc-popup__field">
								<label class="mtuc-popup__field-label" for="mtuc-popup-last-name"><?php esc_html_e( 'Фамилия', 'mtunicredit' ); ?></label>
								<input type="text" id="mtuc-popup-last-name" class="mtuc-popup__input mtuc-popup__input--text" value="<?php echo esc_attr( (string) ( $customer['last_name'] ?? '' ) ); ?>" />
							</div>
							<div class="mtuc-popup__field">
								<label class="mtuc-popup__field-label" for="mtuc-popup-address"><?php esc_html_e( 'Адрес', 'mtunicredit' ); ?></label>
								<input type="text" id="mtuc-popup-address" class="mtuc-popup__input mtuc-popup__input--text" value="<?php echo esc_attr( (string) ( $customer['address'] ?? '' ) ); ?>" />
							</div>
							<div class="mtuc-popup__field">
								<label class="mtuc-popup__field-label" for="mtuc-popup-phone"><?php esc_html_e( 'Мобилен телефон', 'mtunicredit' ); ?></label>
								<input type="tel" id="mtuc-popup-phone" class="mtuc-popup__input mtuc-popup__input--text" value="<?php echo esc_attr( (string) ( $customer['phone'] ?? '' ) ); ?>" />
							</div>
							<div class="mtuc-popup__field">
								<label class="mtuc-popup__field-label" for="mtuc-popup-email"><?php esc_html_e( 'E-Mail', 'mtunicredit' ); ?></label>
								<input type="email" id="mtuc-popup-email" class="mtuc-popup__input mtuc-popup__input--text" value="<?php echo esc_attr( (string) ( $customer['email'] ?? '' ) ); ?>" />
							</div>
						</div>

						<div class="mtuc-popup__actions mtuc-popup__actions--step2">
							<button type="button" class="mtuc-popup__btn mtuc-popup__btn--secondary" id="mtuc-popup-back">
								<?php esc_html_e( 'Назад', 'mtunicredit' ); ?>
							</button>
							<button type="button" class="mtuc-popup__btn mtuc-popup__btn--secondary" data-mtuc-popup-close>
								<?php esc_html_e( 'Откажи', 'mtunicredit' ); ?>
							</button>
							<button type="button" class="mtuc-popup__btn mtuc-popup__btn--primary" id="mtuc-popup-submit">
								<?php esc_html_e( 'Изпрати', 'mtunicredit' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<input type="hidden" id="mtuc-popup-product-id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
	<input type="hidden" id="mtuc-popup-offer-type" value="standard" />
</div>
