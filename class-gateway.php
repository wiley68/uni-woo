<?php
class Uni_Payment_Gateway extends WC_Payment_Gateway {
	public $instructions;
	public $order_status;

	public function __construct() {
		$this->id                 = 'uni_payment_gateway';
		$this->method_title       = 'УНИ Кредит покупки на Кредит';
		$this->method_description = 'Дава възможност на Вашите клиенти да закупуват стока на изплащане с УНИ Кредит';
		$this->icon               = apply_filters( 'woocommerce_custom_gateway_icon', '' );
		$this->has_fields         = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions', $this->description );
		$this->order_status = $this->get_option( 'order_status', 'completed' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_unipayment', array( $this, 'thankyou_unipayment_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_unipayment_instructions' ), 10, 3 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => 'Разреши/Забрани',
				'type'    => 'checkbox',
				'label'   => 'Разреши УНИ Кредит покупки на Кредит',
				'default' => 'yes',
			),
			'title'        => array(
				'title'       => 'Заглавие',
				'type'        => 'text',
				'description' => 'Показва това заглавие при избор на метод на плащане УНИ Кредит покупки на Кредит.',
				'default'     => 'УНИ Кредит покупки на Кредит',
				'desc_tip'    => true,
			),
			'order_status' => array(
				'title'       => 'Състояние на поръчката',
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => 'Какво да бъде състоянието на поръчката след като платите с този метод.',
				'default'     => 'wc-pending',
				'desc_tip'    => true,
				'options'     => wc_get_order_statuses(),
			),
			'description'  => array(
				'title'       => 'Описание',
				'type'        => 'textarea',
				'description' => 'Описание на метода за плащане.',
				'default'     => 'Плащате стоката с УНИ Кредит покупки на Кредит',
				'desc_tip'    => true,
			),
			'instructions' => array(
				'title'       => 'Инструкции',
				'type'        => 'textarea',
				'description' => 'Показва тази инструкция при избор на метод на плащане УНИ Кредит покупки на Кредит.',
				'default'     => 'Плащате стоката с УНИ Кредит покупки на Кредит',
				'desc_tip'    => true,
			),
		);
	}

	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );
		if ( ! $is_available ) {
			return $is_available;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
			return $is_available;
		}

		$uni_status = (string) get_option( 'unipayment_status' );
		if ( $uni_status != 'on' ) {
			$is_available = false;
			return $is_available;
		}

		$uni_currency_code = get_woocommerce_currency();
		if ( $uni_currency_code != 'EUR' && $uni_currency_code != 'BGN' ) {
			$is_available = false;
			return $is_available;
		}

		if ( file_exists( plugin_dir_path( __FILE__ ) . '../keys/kop.json' ) ) {
			$kopdata            = file_get_contents( plugin_dir_path( __FILE__ ) . '../keys/kop.json' );
			$uni_categories_kop = json_decode( $kopdata, true );
			if ( sizeof( $uni_categories_kop ) == 0 ) {
				$is_available = false;
				return $is_available;
			}
		} else {
			$is_available = false;
			return $is_available;
		}

		$uni_unicid = (string) get_option( 'unipayment_unicid' );
		$uni_ch     = curl_init();
		curl_setopt( $uni_ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $uni_ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $uni_ch, CURLOPT_URL, UNI_LIVEURL . '/function/getparameters.php?cid=' . $uni_unicid );
		$paramsuni = json_decode( curl_exec( $uni_ch ), true );
		curl_close( $uni_ch );

		if ( empty( $paramsuni ) || $paramsuni['uni_status'] != 'Yes' ) {
			$is_available = false;
			return $is_available;
		}

		$uni_minstojnost = floatval( $paramsuni['uni_minstojnost'] );
		$uni_maxstojnost = floatval( $paramsuni['uni_maxstojnost'] );
		if ( WC()->cart ) {
			if ( $this->get_order_total() > 0 ) {
				if ( ( $this->get_order_total() < $uni_minstojnost ) ||
					( $this->get_order_total() > $uni_maxstojnost )
				) {
					$is_available = false;
				}
			}
		}

		return $is_available;
	}

	public function thankyou_unipayment_page() {
		if ( $this->instructions ) {
			echo wpautop( wptexturize( $this->instructions ) );
		}
	}

	public function email_unipayment_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->instructions && ! $sent_to_admin && 'uni_payment_gateway' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
			echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
		}
	}

	public function payment_fields() {

		if ( $description = $this->get_description() ) {
			echo wpautop( wptexturize( $description ) );
		}

		$uni_unicid = (string) get_option( 'unipayment_unicid' );
		global $woocommerce;
		$cart = $woocommerce->cart->get_cart();

		$uni_ch = curl_init();
		curl_setopt( $uni_ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $uni_ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $uni_ch, CURLOPT_MAXREDIRS, 2 );
		curl_setopt( $uni_ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $uni_ch, CURLOPT_URL, UNI_LIVEURL . '/function/getparameters.php?cid=' . $uni_unicid );
		$paramsuni = json_decode( curl_exec( $uni_ch ), true );
		curl_close( $uni_ch );
		$uni_product    = reset( $cart );
		$uni_product_id = $uni_product['product_id'];

		$prod_categories    = get_the_terms( $uni_product_id, 'product_cat' );
		$uni_product_cat_id = 0;

		if ( ! empty( $prod_categories ) && ! is_wp_error( $prod_categories ) ) {
			foreach ( $prod_categories as $cat ) {
				$term = $cat;
				while ( $term->parent != 0 ) {
					$term = get_term( $term->parent, 'product_cat' );
					if ( is_wp_error( $term ) || ! $term ) {
						break;
					}
				}
				$uni_product_cat_id = intval( $term->term_id );
				break;
			}
		}

		if ( intval( $paramsuni['uni_testenv'] ) == 1 ) {
			$uni_service = $paramsuni['uni_test_service'];
		} else {
			$uni_service = $paramsuni['uni_production_service'];
		}

		global $current_user;
		if ( version_compare( $woocommerce->version, '4.5', '>=' ) ) {
			wp_get_current_user();
		} else {
			get_current_user();
		}
		if ( get_user_meta( $current_user->ID ) ) {
			$uni_all_meta_for_user = array_map(
				function ( $a ) {
					return $a[0];
				},
				get_user_meta( $current_user->ID )
			);
		}

		$uni_proces1           = intval( $paramsuni['uni_proces1'] );
		$uni_total             = $woocommerce->cart->total;
		$uni_first_vnoska      = $paramsuni['uni_first_vnoska'];
		$uni_proces2           = intval( $paramsuni['uni_proces2'] );
		$uni_firstname         = isset( $uni_all_meta_for_user['first_name'] ) ? $uni_all_meta_for_user['first_name'] : uni_wordpress_get_params( 'billing_first_name', '' );
		$uni_lastname          = isset( $uni_all_meta_for_user['last_name'] ) ? $uni_all_meta_for_user['last_name'] : uni_wordpress_get_params( 'billing_last_name', '' );
		$uni_phone             = isset( $uni_all_meta_for_user['billing_phone'] ) ? $uni_all_meta_for_user['billing_phone'] : uni_wordpress_get_params( 'billing_phone', '' );
		$uni_email             = isset( $uni_all_meta_for_user['billing_email'] ) ? $uni_all_meta_for_user['billing_email'] : uni_wordpress_get_params( 'billing_email', '' );
		$uni_uslovia           = UNI_CSS_URI . '/uni_uslovia.pdf';
		$uni_mod_version       = UNI_MOD_VERSION;
		$uni_promo             = $paramsuni['uni_promo'];
		$uni_promo_data        = $paramsuni['uni_promo_data'];
		$uni_promo_meseci_znak = $paramsuni['uni_promo_meseci_znak'];
		$uni_promo_meseci      = $paramsuni['uni_promo_meseci'];
		$uni_promo_price       = floatval( $paramsuni['uni_promo_price'] );
		$uni_user              = $paramsuni['uni_user'];
		$uni_password          = $paramsuni['uni_password'];
		$uni_sertificat        = $paramsuni['uni_sertificat'];
		$uni_shema_current     = intval( $paramsuni['uni_shema_current'] );
		$uni_meseci_3          = intval( $paramsuni['uni_meseci_3'] );
		$uni_meseci_4          = intval( $paramsuni['uni_meseci_4'] );
		$uni_meseci_5          = intval( $paramsuni['uni_meseci_5'] );
		$uni_meseci_6          = intval( $paramsuni['uni_meseci_6'] );
		$uni_meseci_9          = intval( $paramsuni['uni_meseci_9'] );
		$uni_meseci_10         = intval( $paramsuni['uni_meseci_10'] );
		$uni_meseci_12         = intval( $paramsuni['uni_meseci_12'] );
		$uni_meseci_15         = intval( $paramsuni['uni_meseci_15'] );
		$uni_meseci_18         = intval( $paramsuni['uni_meseci_18'] );
		$uni_meseci_24         = intval( $paramsuni['uni_meseci_24'] );
		$uni_meseci_30         = intval( $paramsuni['uni_meseci_30'] );
		$uni_meseci_36         = intval( $paramsuni['uni_meseci_36'] );

		$uni_eur           = (int) $paramsuni['uni_eur'];
		$uni_currency_code = get_woocommerce_currency();

		switch ( $uni_eur ) {
			case 0:
				break;
			case 1:
				if ( $uni_currency_code == 'EUR' ) {
					$uni_total = $uni_total * 1.95583;
				}
				break;
			case 2:
			case 3:
				if ( $uni_currency_code == 'BGN' ) {
					$uni_total = $uni_total / 1.95583;
				}
				break;
		}

		$uni_price_second = 0;
		$uni_sign         = 'лева';
		$uni_sign_second  = 'евро';
		switch ( $uni_eur ) {
			case 0:
				$uni_price_second = 0;
				$uni_sign         = 'лева';
				$uni_sign_second  = 'евро';
				break;
			case 1:
				$uni_price_second = number_format( $uni_total / 1.95583, 2, '.', '' );
				$uni_sign         = 'лева';
				$uni_sign_second  = 'евро';
				break;
			case 2:
				$uni_price_second = number_format( $uni_total * 1.95583, 2, '.', '' );
				$uni_sign         = 'евро';
				$uni_sign_second  = 'лева';
				break;
			case 3:
				$uni_price_second = 0;
				$uni_sign         = 'евро';
				$uni_sign_second  = 'лева';
				break;
		}

		?>
		<div id="uni-checkout-container">
		<?php if ( $uni_proces1 == 1 ) { ?>
			<div class="uni_title">Можете да изберете 'Срок за кредита', предпочитаната от Вас 'Месечна вноска', както и при желание 'Първоначална вноска'. След което да потвърдите избора си. Ще бъдете прехвърлени към страницата на UNI Credit за довършване на покупката си на кредит.</div>
		<?php } else { ?>
			<div class="uni_title">Можете да изберете 'Срок за кредита', предпочитаната от Вас 'Месечна вноска', както и при желание 'Първоначална вноска'. Въвеждате необходимите лични данни. Съгласявате се с условията за използването им. След което можете да потвърдите избора си. Сътрудник от UNI Credit ще се свърже с Вас за завършване на процедурата.</div>
		<?php } ?>
		<div style="padding-bottom:5px;"></div>
		<input type="hidden" name="uni_promo" id="uni_promo" value="<?php echo $uni_promo; ?>" />
		<input type="hidden" name="uni_promo_data" id="uni_promo_data" value="<?php echo $uni_promo_data; ?>" />
		<input type="hidden" name="uni_promo_meseci_znak" id="uni_promo_meseci_znak" value="<?php echo $uni_promo_meseci_znak; ?>" />
		<input type="hidden" name="uni_promo_meseci" id="uni_promo_meseci" value="<?php echo $uni_promo_meseci; ?>" />
		<input type="hidden" name="uni_promo_price" id="uni_promo_price" value="<?php echo $uni_promo_price; ?>" />
		<input type="hidden" name="uni_product_cat_id" id="uni_product_cat_id" value="<?php echo $uni_product_cat_id; ?>" />
		<input type="hidden" name="uni_service" id="uni_service" value="<?php echo $uni_service; ?>" />
		<input type="hidden" name="uni_user" id="uni_user" value="<?php echo $uni_user; ?>" />
		<input type="hidden" name="uni_password" id="uni_password" value="<?php echo $uni_password; ?>" />
		<input type="hidden" name="uni_sertificat" id="uni_sertificat" value="<?php echo $uni_sertificat; ?>" />
		<input type="hidden" name="uni_liveurl" id="uni_liveurl" value="<?php echo UNI_LIVEURL; ?>" />
		<input type="hidden" name="uni_unicid" id="uni_unicid" value="<?php echo $uni_unicid; ?>" />
		<input type="hidden" name="uni_shema_current" id="uni_shema_current" value="<?php echo $uni_shema_current; ?>" />
		<input type="hidden" name="uni_saglasie" id="uni_saglasie" value="No" />
		<input type="hidden" name="uni_proces2" id="uni_proces2" value="<?php echo $uni_proces2; ?>" />
		<input type="hidden" name="uni_kop" id="uni_kop" value="" />
		<input type="hidden" name="uni_eur" id="uni_eur" value="<?php echo $uni_eur; ?>" />
		<table class="uni_table">
			<tr>
				<td class="uni_row_title">
				<?php if ( $uni_eur == 0 || $uni_eur == 3 ) { ?>
				Цена на продукта /<?php echo $uni_sign; ?>/
				<?php } else { ?>
				Цена на продукта /<?php echo $uni_sign; ?>(<?php echo $uni_sign_second; ?>)/
				<?php } ?>
				</td>
				<td class="uni_row_input">
					<input type="hidden" id="uni_price" value="<?php echo $uni_total; ?>" />
					<?php if ( $uni_eur == 0 || $uni_eur == 3 ) { ?>
					<input type="text" class="uni_input passive" readonly="readonly" value="<?php echo number_format( $uni_total, 2, '.', '' ); ?>">
					<?php } else { ?>
					<input type="text" class="uni_input passive" readonly="readonly" value="<?php echo number_format( $uni_total, 2, '.', '' ); ?> (<?php echo $uni_price_second; ?>)">
					<?php } ?>
				</td>
			</tr>
			<tr>
				<td class="uni_row_title">
				Срок на кредита /месеца/
				</td>
				<td class="uni_row_input">
					<select name="uni_pogasitelni_vnoski" id="uni_pogasitelni_vnoski" class="uni_input" >
						<?php if ( $uni_meseci_3 ) { ?>
						<option value="3" 
							<?php
							if ( $uni_shema_current == 3 ) {
								echo 'selected';}
							?>
						>3 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_4 ) { ?>
						<option value="4" 
							<?php
							if ( $uni_shema_current == 4 ) {
								echo 'selected';}
							?>
						>4 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_5 ) { ?>
						<option value="5" 
							<?php
							if ( $uni_shema_current == 5 ) {
								echo 'selected';}
							?>
						>5 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_6 ) { ?>
						<option value="6" 
							<?php
							if ( $uni_shema_current == 6 ) {
								echo 'selected';}
							?>
						>6 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_9 ) { ?>
						<option value="9" 
							<?php
							if ( $uni_shema_current == 9 ) {
								echo 'selected';}
							?>
						>9 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_10 ) { ?>
						<option value="10" 
							<?php
							if ( $uni_shema_current == 10 ) {
								echo 'selected';}
							?>
						>10 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_12 ) { ?>
						<option value="12" 
							<?php
							if ( $uni_shema_current == 12 ) {
								echo 'selected';}
							?>
						>12 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_15 ) { ?>
						<option value="15" 
							<?php
							if ( $uni_shema_current == 15 ) {
								echo 'selected';}
							?>
						>15 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_18 ) { ?>
						<option value="18" 
							<?php
							if ( $uni_shema_current == 18 ) {
								echo 'selected';}
							?>
						>18 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_24 ) { ?>
						<option value="24" 
							<?php
							if ( $uni_shema_current == 24 ) {
								echo 'selected';}
							?>
						>24 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_30 ) { ?>
						<option value="30" 
							<?php
							if ( $uni_shema_current == 30 ) {
								echo 'selected';}
							?>
						>30 месеца</option>
						<?php } ?>
						<?php if ( $uni_meseci_36 ) { ?>
						<option value="36" 
							<?php
							if ( $uni_shema_current == 36 ) {
								echo 'selected';}
							?>
						>36 месеца</option>
						<?php } ?>
					</select>
				</td>
			</tr>
			<?php if ( $uni_first_vnoska == 'Yes' ) { ?>
			<tr>
				<td class="uni_row_title">
					<table style="width:100%;padding:0px;margin:0px;">
						<tr>
							<td class="uni_row">
								<input type="checkbox" id="uni_parva_chec" title="Ако искате да използвате полето за Първоначална Вноска, моля отбележете тази отметка!">
							</td>
							<td class="uni_row">
								Първоначална вноска към търговеца /<?php echo $uni_sign; ?>/
							</td>
						</tr>
					</table>
				</td>
				<td class="uni_row_input">
					<input class="uni_input" type="text" readonly="readonly" name="uni_parva" id="uni_parva" value="0.00">
				</td>
			</tr>
			<tr>
				<td class="uni_row_title">&nbsp;</td>
				<td class="uni_row_input">
					<div type="button" class="uni_btn_pre" id="uni_parva_button">Преизчисли</div>
				</td>
			</tr>
			<?php } else { ?>
			<input type="hidden" id="uni_parva_chec" value="0">
			<input type="hidden" name="uni_parva" id="uni_parva" value="0.00">
			<?php } ?>
			<tr>
				<td class="uni_row_title">
					<?php if ( $uni_eur == 0 || $uni_eur == 3 ) { ?>
					Общ размер на кредита /<?php echo $uni_sign; ?>/
					<?php } else { ?>
					Общ размер на кредита /<?php echo $uni_sign; ?>(<?php echo $uni_sign_second; ?>)/
					<?php } ?>
				</td>
				<td class="uni_row_input">
					<input type="hidden" name="uni_obshto" id="uni_obshto">
					<input class="uni_input passive" type="text" name="uni_obshto_second" id="uni_obshto_second" readonly="readonly">
				</td>
			</tr>
			<tr>
				<td class="uni_row_title">
					<?php if ( $uni_eur == 0 || $uni_eur == 3 ) { ?>
					Месечна вноска /<?php echo $uni_sign; ?>/
					<?php } else { ?>
					Месечна вноска /<?php echo $uni_sign; ?>(<?php echo $uni_sign_second; ?>)/
					<?php } ?>
				</td>
				<td class="uni_row_input">
					<input type="hidden" name="uni_mesecna" id="uni_mesecna">
					<input class="uni_input passive" type="text" name="uni_mesecna_second" id="uni_mesecna_second" readonly="readonly" value="" >
				</td>
			</tr>
			<tr>
				<td class="uni_row_title">
					<?php if ( $uni_eur == 0 || $uni_eur == 3 ) { ?>
					Обща дължима сума /<?php echo $uni_sign; ?>/
					<?php } else { ?>
					Обща дължима сума /<?php echo $uni_sign; ?>(<?php echo $uni_sign_second; ?>)/
					<?php } ?>
				</td>
				<td class="uni_row_input">
					<input type="hidden" name="uni_obshtozaplashtane" id="uni_obshtozaplashtane">
					<input class="uni_input passive" type="text" name="uni_obshtozaplashtane_second" id="uni_obshtozaplashtane_second" readonly="readonly">
				</td>
			</tr>
			<tr>
				<td class="uni_row_title">
					ГЛП /%/
				</td>
				<td class="uni_row_input">
					<input class="uni_input passive" type="text" name="uni_glp" id="uni_glp" readonly="readonly" />
				</td>
			</tr>
			<tr>
				<td class="uni_row_title">
					ГПР /%/
				</td>
				<td class="uni_row_input">
					<input class="uni_input passive" type="text" name="uni_gpr" id="uni_gpr" readonly="readonly" />
				</td>
			</tr>
		</table>
		<?php if ( $uni_proces2 == 1 ) { ?>
			<div class="uni_hr">&nbsp;</div>
				<table class="uni_table">
					<tr>
						<td class="uni_row_title">
							Име
						</td>
						<td class="uni_row_input">
							<input id="uni_fname" name="uni_fname" required type="text" class="uni_input" value="<?php echo $uni_firstname; ?>">
						</td>
					</tr>
					<tr>
						<td class="uni_row_title">
							Фамилия
						</td>
						<td class="uni_row_input">
							<input id="uni_lname" name="uni_lname" required type="text" class="uni_input" value="<?php echo $uni_lastname; ?>">
						</td>
					</tr>
					<tr>
						<td class="uni_row_title">
							ЕГН
						</td>
						<td class="uni_row_input">
							<input id="uni_egn" name="uni_egn" required type="text" class="uni_input">
						</td>
					</tr>
					<tr>
						<td class="uni_row_title">
							Телефон
						</td>
						<td class="uni_row_input">
							<input id="uni_phone" name="uni_phone" required type="text" class="uni_input" value="<?php echo $uni_phone; ?>">
						</td>
					</tr>
					<tr>
						<td class="uni_row_title">
							Допълнителен Телефон
						</td>
						<td class="uni_row_input">
							<input id="uni_phone2" name="uni_phone2" type="text" class="uni_input">
						</td>
					</tr>
					<tr>
						<td class="uni_row_title">
							E-Mail
						</td>
						<td class="uni_row_input">
							<input id="uni_email" name="uni_email" required type="text" class="uni_input" value="<?php echo $uni_email; ?>">
						</td>
					</tr>
					<tr>
						<td class="uni_row_title">
							Коментар
						</td>
						<td class="uni_row_input">
							<textarea id="uni_description" name="uni_description" class="uni_input"></textarea>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<div style="display:flex;">
								<input type="checkbox" name="uni_uslovia_check" value="uni_uslovia" id="uni_uslovia_check" />
								<a href="<?php echo $uni_uslovia; ?>" style="color: #993300;" title="Общи условия за UniCredit лизинг." target="_blank">
								&nbsp;Прочетох и съм съгласен с Общите условия на UniCredit
								</a>
							</div>
						</td>
					</tr>
				</table>
		<?php } ?>
			<div class="uni_hr">&nbsp;</div>
			<div class="uni_text_cc">C.C.Ver. <?php echo $uni_mod_version; ?></div>
		</div>
		<?php
	}

	public function validate_fields() {
		$uni_proces2 = isset( $_POST['uni_proces2'] ) ? intval( $_POST['uni_proces2'] ) : 0;

		$uni_fname = isset( $_POST['uni_fname'] ) ? strval( $_POST['uni_fname'] ) : '';
		if ( $uni_proces2 === 1 && $uni_fname === '' ) {
			wc_add_notice( 'Необходимо е да попълните полето Име!', 'error' );
			return false;
		}

		$uni_lname = isset( $_POST['uni_lname'] ) ? strval( $_POST['uni_lname'] ) : '';
		if ( $uni_proces2 === 1 && $uni_lname === '' ) {
			wc_add_notice( 'Необходимо е да попълните полето Фамилия!', 'error' );
			return false;
		}

		$uni_egn = isset( $_POST['uni_egn'] ) ? strval( $_POST['uni_egn'] ) : '';
		if ( $uni_proces2 === 1 && $uni_egn === '' ) {
			wc_add_notice( 'Необходимо е да попълните полето ЕГН!', 'error' );
			return false;
		}

		$uni_phone = isset( $_POST['uni_phone'] ) ? strval( $_POST['uni_phone'] ) : '';
		if ( $uni_proces2 === 1 && $uni_phone === '' ) {
			wc_add_notice( 'Необходимо е да попълните полето Телефон!', 'error' );
			return false;
		}

		$uni_email = isset( $_POST['uni_email'] ) ? strval( $_POST['uni_email'] ) : '';
		if ( $uni_proces2 === 1 && $uni_email === '' ) {
			wc_add_notice( 'Необходимо е да попълните полето E-Mail!', 'error' );
			return false;
		}

		$uni_saglasie = isset( $_POST['uni_saglasie'] ) ? strval( $_POST['uni_saglasie'] ) : 'No';
		if ( $uni_proces2 === 1 && $uni_saglasie === 'No' ) {
			wc_add_notice( 'Необходимо е да се съгласите с Общите условия на UniCredit!', 'error' );
			return false;
		}

		if ( isset( $_POST['uni_kop'] ) ) {
			$uni_kop = strval( $_POST['uni_kop'] );
		} else {
			/** WC blocks payment method */
			if ( isset( WC()->session ) ) {
				if ( WC()->session->get( 'uni_kop' ) ) {
					$uni_kop = WC()->session->get( 'uni_kop' );
				} else {
					$uni_kop = '';
				}
			} else {
				$uni_kop = '';
			}
			/** WC blocks payment method */
		}
		if ( $uni_kop === '' ) {
			wc_add_notice( 'Нямате съответствие между КОП код и код на Категория продукт!', 'error' );
			return false;
		}

		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		global $woocommerce;
		$uni_total = (float) $woocommerce->cart->total;

		if ( $order_id != 0 ) {
			if ( isset( $_POST['uni_liveurl'] ) ) {
				$uni_liveurl = $_POST['uni_liveurl'];
			} else {
				$uni_liveurl = UNI_LIVEURL;
			}
			if ( isset( $_POST['uni_fname'] ) ) {
				$uni_fname = $_POST['uni_fname'];
			} elseif ( isset( $_POST['billing_first_name'] ) ) {
					$uni_fname = $_POST['billing_first_name'];
			} else {
				$uni_fname = $order->get_billing_first_name() ? $order->get_billing_first_name() : '';
			}
			if ( isset( $_POST['uni_lname'] ) ) {
				$uni_lname = $_POST['uni_lname'];
			} elseif ( isset( $_POST['billing_last_name'] ) ) {
					$uni_lname = $_POST['billing_last_name'];
			} else {
				$uni_lname = $order->get_billing_last_name() ? $order->get_billing_last_name() : '';
			}
			if ( isset( $_POST['uni_phone'] ) ) {
				$uni_phone = $_POST['uni_phone'];
			} elseif ( isset( $_POST['billing_phone'] ) ) {
					$uni_phone = $_POST['billing_phone'];
			} else {
				$uni_phone = $order->get_billing_phone() ? $order->get_billing_phone() : '';
			}
			if ( isset( $_POST['uni_phone2'] ) ) {
				$uni_phone2 = $_POST['uni_phone2'];
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_phone2' ) ) {
						$uni_phone2 = WC()->session->get( 'uni_phone2' );
						WC()->session->__unset( 'uni_phone2' );
					} else {
						$uni_phone2 = '';
					}
				} else {
					$uni_phone2 = '';
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_email'] ) ) {
				$uni_email = $_POST['uni_email'];
			} elseif ( isset( $_POST['billing_email'] ) ) {
					$uni_email = $_POST['billing_email'];
			} else {
				$uni_email = $order->get_billing_email() ? $order->get_billing_email() : '';
			}
			if ( isset( $_POST['uni_egn'] ) ) {
				$uni_egn = $_POST['uni_egn'];
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_egn' ) ) {
						$uni_egn = WC()->session->get( 'uni_egn' );
						WC()->session->__unset( 'uni_egn' );
					} else {
						$uni_egn = '';
					}
				} else {
					$uni_egn = '';
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_description'] ) ) {
				$uni_description = $_POST['uni_description'];
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_description' ) ) {
						$uni_description = WC()->session->get( 'uni_description' );
						WC()->session->__unset( 'uni_description' );
					} else {
						$uni_description = '';
					}
				} else {
					$uni_description = '';
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['billing_address_1'] ) ) {
				$uni_billing_address  = $_POST['billing_address_1'];
				$uni_shipping_address = $_POST['billing_address_1'];
			} else {
				$uni_billing_address  = $order->get_billing_address_1() ? $order->get_billing_address_1() : '';
				$uni_shipping_address = $order->get_billing_address_1() ? $order->get_billing_address_1() : '';
			}
			if ( isset( $_POST['billing_city'] ) ) {
				$uni_billing_city  = $_POST['billing_city'];
				$uni_shipping_city = $_POST['billing_city'];
			} else {
				$uni_billing_city  = $order->get_billing_city() ? $order->get_billing_city() : '';
				$uni_shipping_city = $order->get_billing_city() ? $order->get_billing_city() : '';
			}
			if ( isset( $_POST['billing_state'] ) ) {
				$uni_billing_county  = $_POST['billing_state'];
				$uni_shipping_county = $_POST['billing_state'];
			} else {
				$uni_billing_county  = $order->get_billing_state() ? $order->get_billing_state() : '';
				$uni_shipping_county = $order->get_billing_state() ? $order->get_billing_state() : '';
			}
			if ( isset( $_POST['uni_mesecna'] ) ) {
				$uni_mesecna = floatval( $_POST['uni_mesecna'] );
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_mesecna' ) ) {
						$uni_mesecna = WC()->session->get( 'uni_mesecna' );
						WC()->session->__unset( 'uni_mesecna' );
					} else {
						$uni_mesecna = 0;
					}
				} else {
					$uni_mesecna = 0;
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_gpr'] ) ) {
				$uni_gpr = floatval( $_POST['uni_gpr'] );
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_gpr' ) ) {
						$uni_gpr = WC()->session->get( 'uni_gpr' );
						WC()->session->__unset( 'uni_gpr' );
					} else {
						$uni_gpr = 0.00;
					}
				} else {
					$uni_gpr = 0.00;
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_glp'] ) ) {
				$uni_glp = floatval( $_POST['uni_glp'] );
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_glp' ) ) {
						$uni_glp = WC()->session->get( 'uni_glp' );
						WC()->session->__unset( 'uni_glp' );
					} else {
						$uni_glp = 0.00;
					}
				} else {
					$uni_glp = 0.00;
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_pogasitelni_vnoski'] ) ) {
				$uni_vnoski = intval( $_POST['uni_pogasitelni_vnoski'] );
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_vnoski' ) ) {
						$uni_vnoski = WC()->session->get( 'uni_vnoski' );
						WC()->session->__unset( 'uni_vnoski' );
					} else {
						$uni_vnoski = 12;
					}
				} else {
					$uni_vnoski = 12;
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_parva'] ) ) {
				$uni_parva = floatval( $_POST['uni_parva'] );
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_parva' ) ) {
						$uni_parva = WC()->session->get( 'uni_parva' );
						WC()->session->__unset( 'uni_parva' );
					} else {
						$uni_parva = 0.00;
					}
				} else {
					$uni_parva = 0.00;
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_kop'] ) ) {
				$uni_kop = strval( $_POST['uni_kop'] );
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_kop' ) ) {
						$uni_kop = WC()->session->get( 'uni_kop' );
						WC()->session->__unset( 'uni_kop' );
					} else {
						$uni_kop = '';
					}
				} else {
					$uni_kop = '';
				}
				/** WC blocks payment method */
			}
			if ( isset( $_POST['uni_uslovia_check'] ) ) {
				$uni_uslovia_check = $_POST['uni_uslovia_check'];
			} else {
				/** WC blocks payment method */
				if ( isset( WC()->session ) ) {
					if ( WC()->session->get( 'uni_uslovia_check' ) ) {
						$uni_uslovia_check = WC()->session->get( 'uni_uslovia_check' );
						WC()->session->__unset( 'uni_uslovia_check' );
					} else {
						$uni_uslovia_check = 0;
					}
				} else {
					$uni_uslovia_check = 0;
				}
				/** WC blocks payment method */
			}
			WC()->session->__unset( 'uni_proces2' );

			$uni_unicid = (string) get_option( 'unipayment_unicid' );
			$uni_ch     = curl_init();
			curl_setopt( $uni_ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $uni_ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $uni_ch, CURLOPT_MAXREDIRS, 2 );
			curl_setopt( $uni_ch, CURLOPT_TIMEOUT, 5 );
			curl_setopt( $uni_ch, CURLOPT_URL, $uni_liveurl . '/function/getparameters.php?cid=' . $uni_unicid );
			$paramsuni = json_decode( curl_exec( $uni_ch ), true );
			curl_close( $uni_ch );

			$uni_currency_code      = get_woocommerce_currency();
			$uni_currency_code_send = 'BGN';
			$uni_eur                = (int) $paramsuni['uni_eur'];
			switch ( $uni_eur ) {
				case 0:
					break;
				case 1:
					$uni_currency_code_send = 'BGN';
					if ( $uni_currency_code == 'EUR' ) {
						$uni_total = number_format( $uni_total * 1.95583, 2, '.', '' );
					}
					break;
				case 2:
				case 3:
					$uni_currency_code_send = 'EUR';
					if ( $uni_currency_code == 'BGN' ) {
						$uni_total = number_format( $uni_total / 1.95583, 2, '.', '' );
					}
					break;
			}

			$ident = 0;
			foreach ( $woocommerce->cart->get_cart() as $cart_item ) {
				$item = $cart_item['data'];
				if ( ! empty( $item ) ) {
					$uni_items[ $ident ]['name'] = str_replace( array( "'", "'" ), '', $cart_item['data']->get_title() );
					$uni_items[ $ident ]['code'] = $cart_item['product_id'];
					$terms                       = get_the_terms( $cart_item['product_id'], 'product_cat' );
					foreach ( $terms as $term ) {
						$product_category = $term->term_id;
					}
					$uni_items[ $ident ]['type']  = $product_category;
					$uni_items[ $ident ]['count'] = intval( $cart_item['quantity'] );
					$uni_product                  = wc_get_product( $item->get_id() );
					$uni_price_cart               = (float) wc_get_price_including_tax( $uni_product );
					switch ( $uni_eur ) {
						case 0:
							break;
						case 1:
							if ( $uni_currency_code == 'EUR' ) {
								$uni_price_cart = ( $uni_price_cart * 1.95583 ) / (float) $cart_item['quantity'];
							}
							break;
						case 2:
						case 3:
							if ( $uni_currency_code == 'BGN' ) {
								$uni_price_cart = ( $uni_price_cart / 1.95583 ) / (float) $cart_item['quantity'];
							}
							break;
					}
					$uni_items[ $ident ]['singlePrice'] = $uni_price_cart;
					++$ident;
				}
			}

			$uni_add_ch = curl_init();
			curl_setopt( $uni_add_ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $uni_add_ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $uni_add_ch, CURLOPT_MAXREDIRS, 2 );
			curl_setopt( $uni_add_ch, CURLOPT_TIMEOUT, 5 );
			curl_setopt( $uni_add_ch, CURLOPT_URL, $uni_liveurl . '/function/addorders.php?cid=' . $uni_unicid );
			curl_setopt( $uni_add_ch, CURLOPT_POST, 1 );

			$useragent = array_key_exists( 'HTTP_USER_AGENT', $_SERVER ) ? $_SERVER['HTTP_USER_AGENT'] : '';
			if ( preg_match( '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent ) || preg_match( '/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr( $useragent, 0, 4 ) ) ) {
				$devices = 'МОБИЛЕН ТЕЛЕФОН';
			} else {
				$devices = 'НАСТОЛЕН КОМПЮТЪР';
			}

			$uni_post = array(
				'orderId'    => $order_id,
				'orderTotal' => $uni_total,
				'vnoska'     => $uni_mesecna,
				'gpr'        => $uni_gpr,
				'glp'        => $uni_glp,
				'vnoski'     => $uni_vnoski,
				'parva'      => $uni_parva,
				'devices'    => $devices,
				'currency'   => $uni_currency_code_send,
				'customer'   => array(
					'firstName'       => str_replace( array( "'", "'" ), '', $uni_fname ),
					'lastName'        => str_replace( array( "'", "'" ), '', $uni_lname ),
					'email'           => str_replace( array( "'", "'" ), '', $uni_email ),
					'phone'           => str_replace( array( "'", "'" ), '', $uni_phone ),
					'billingAddress'  => str_replace( array( "'", "'" ), '', $uni_billing_address ),
					'billingCity'     => str_replace( array( "'", "'" ), '', $uni_billing_city ),
					'billingCounty'   => str_replace( array( "'", "'" ), '', $uni_billing_county ),
					'deliveryAddress' => str_replace( array( "'", "'" ), '', $uni_shipping_address ),
					'deliveryCity'    => str_replace( array( "'", "'" ), '', $uni_shipping_city ),
					'deliveryCounty'  => str_replace( array( "'", "'" ), '', $uni_shipping_county ),
				),
				'items'      => $uni_items,
			);

			if ( intval( $paramsuni['uni_testenv'] ) == 1 ) {
				$uni_service     = $paramsuni['uni_test_service'];
				$uni_application = $paramsuni['uni_test_application'];
			} else {
				$uni_service     = $paramsuni['uni_production_service'];
				$uni_application = $paramsuni['uni_production_application'];
			}
			$uni_user     = $paramsuni['uni_user'];
			$uni_password = $paramsuni['uni_password'];

			curl_setopt( $uni_add_ch, CURLOPT_POSTFIELDS, http_build_query( $uni_post ) );
			$paramsuniadd = json_decode( curl_exec( $uni_add_ch ), true );
			curl_close( $uni_add_ch );

			$uni_data = array(
				'user'                  => $uni_user,
				'pass'                  => $uni_password,
				'orderNo'               => $order_id,
				'clientFirstName'       => str_replace( array( "'", "'" ), '', $uni_fname ),
				'clientLastName'        => str_replace( array( "'", "'" ), '', $uni_lname ),
				'clientPhone'           => str_replace( array( "'", "'" ), '', $uni_phone ),
				'clientEmail'           => str_replace( array( "'", "'" ), '', $uni_email ),
				'clientDeliveryAddress' => str_replace( array( "'", "'" ), '', $uni_shipping_address ),
				'onlineProductCode'     => $uni_kop,
				'totalPrice'            => $uni_total,
				'initialPayment'        => $uni_parva,
				'installmentCount'      => $uni_vnoski,
				'monthlyPayment'        => $uni_mesecna,
				'items'                 => $uni_items,
			);

			$uni_proces1 = intval( $paramsuni['uni_proces1'] );
			$uni_proces2 = intval( $paramsuni['uni_proces2'] );

			if ( $uni_proces1 > 0 ) {
				if ( $paramsuni['uni_sertificat'] == 'Yes' ) {
					$url_key  = $uni_liveurl . '/calculators/key/avalon_private_key.pem';
					$curl_key = curl_init();
					curl_setopt( $curl_key, CURLOPT_URL, $url_key );
					curl_setopt( $curl_key, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( $curl_key, CURLOPT_HEADER, false );
					$keyFileContents = curl_exec( $curl_key );
					curl_close( $curl_key );
					$keyFileHandle = fopen( plugin_dir_path( __FILE__ ) . '../keys/avalon_private_key.pem', 'w' ) or die( 'Unable to open file!' );
					fwrite( $keyFileHandle, $keyFileContents );
					fclose( $keyFileHandle );
					$keyFile   = plugin_dir_path( __FILE__ ) . '../keys/avalon_private_key.pem';
					$url_cert  = $uni_liveurl . '/calculators/key/avalon_cert.pem';
					$curl_cert = curl_init();
					curl_setopt( $curl_cert, CURLOPT_URL, $url_cert );
					curl_setopt( $curl_cert, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( $curl_cert, CURLOPT_HEADER, false );
					$certFileContents = curl_exec( $curl_cert );
					curl_close( $curl_cert );
					$certFileHandle = fopen( plugin_dir_path( __FILE__ ) . '../keys/avalon_cert.pem', 'w' ) or die( 'Unable to open file!' );
					fwrite( $certFileHandle, $certFileContents );
					fclose( $certFileHandle );
					$certFile = plugin_dir_path( __FILE__ ) . '../keys/avalon_cert.pem';
				}

				if ( isset( $paramsuniadd['status'] ) && ( $paramsuniadd['status'] == 'Yes' ) ) {
					$uni_api_ch = curl_init();
					if ( $paramsuni['uni_sertificat'] == 'Yes' ) {
						curl_setopt_array(
							$uni_api_ch,
							array(
								CURLOPT_URL            => $uni_service . 'sucfOnlineSessionStart',
								// името на файл, съдържащ само личен SSL ключ в текстови формат (PEM)
								CURLOPT_SSLKEY         => $keyFile,
								CURLOPT_SSLKEYPASSWD   => '1234',
								// името на файл, съдържащ само клиентския сартификат в текстови формат (PEM)
								CURLOPT_SSLCERT        => $certFile,
								CURLOPT_SSLCERTPASSWD  => '1234',

								CURLOPT_SSLVERSION     => 6,
								CURLOPT_RETURNTRANSFER => true,
								CURLOPT_ENCODING       => '',
								CURLOPT_MAXREDIRS      => 2,
								CURLOPT_TIMEOUT        => 10,
								CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
								CURLOPT_CUSTOMREQUEST  => 'POST',
								CURLOPT_POSTFIELDS     => json_encode( $uni_data ),
								CURLOPT_HTTPHEADER     => array(
									'Content-Type: application/json',
									'cache-control: no-cache',
								),
							)
						);
					} else {
						curl_setopt_array(
							$uni_api_ch,
							array(
								CURLOPT_URL            => $uni_service . 'sucfOnlineSessionStart',
								CURLOPT_SSLVERSION     => 6,
								CURLOPT_RETURNTRANSFER => true,
								CURLOPT_ENCODING       => '',
								CURLOPT_MAXREDIRS      => 2,
								CURLOPT_TIMEOUT        => 10,
								CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
								CURLOPT_CUSTOMREQUEST  => 'POST',
								CURLOPT_POSTFIELDS     => json_encode( $uni_data ),
								CURLOPT_HTTPHEADER     => array(
									'Content-Type: application/json',
									'cache-control: no-cache',
								),
							)
						);
					}

					$responseapi = curl_exec( $uni_api_ch );
					$err         = curl_error( $uni_api_ch );
					curl_close( $uni_api_ch );
					$api_obj = json_decode( $responseapi );
					if ( ! empty( $api_obj->sucfOnlineSessionID ) ) {
						$uni_api = $api_obj->sucfOnlineSessionID;
					}

					$uni_debug = (string) get_option( 'unipayment_debug' );
					if ( $uni_debug == 'on' ) {
						$uniDebugFile = fopen( plugin_dir_path( __FILE__ ) . '../keys/uni_debug.json', 'w' ) or die( 'Unable to open file!' );
						fwrite( $uniDebugFile, 'date: ' . date( 'd.m.Y H:i:s' ) . PHP_EOL );
						fwrite( $uniDebugFile, 'err: ' . $err . PHP_EOL );
						fwrite( $uniDebugFile, 'response: ' . $responseapi . PHP_EOL );
						fwrite( $uniDebugFile, 'request: ' . json_encode( $uni_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
						fwrite( $uniDebugFile, PHP_EOL . '##########' );
						fclose( $uniDebugFile );
					}
				}
			}

			if ( $uni_proces2 == 1 ) {
				$result       = '<span class="uni_result">Резултат от заявката.</span><br /><br />';
				$result      .= '<span class="uni_subresult">Заявката е изпратена успешно.</span><br /><br />';
				$result      .= 'Заявка за лизинг с UNI Credit.<br /><br />';
				$result      .= 'Поръчка №: ' . $order_id . '<br />';
				$result      .= 'Име: ' . $uni_fname . '<br />';
				$result      .= 'Фамилия: ' . $uni_lname . '<br />';
				$result      .= 'ЕГН: ' . $uni_egn . '<br />';
				$result      .= 'Телефон: ' . $uni_phone . '<br />';
				$result      .= 'Втори телефон: ' . $uni_phone2 . '<br />';
				$result      .= 'E-Mail: ' . $uni_email . '<br />';
				$result      .= 'Адрес за доставка: ' . $uni_shipping_address . '<br />';
				$result      .= 'KOP: ' . $uni_kop . '<br />';
				$result      .= 'Коментар: ' . $uni_description . '<br />';
				$result_items = '';
				foreach ( $uni_items as $item ) {
					$itemSinglePrice = $item['singlePrice'];
					switch ( $uni_eur ) {
						case 0:
							break;
						case 1:
							if ( $uni_currency_code == 'EUR' ) {
								$itemSinglePrice = number_format( (float) $itemSinglePrice * 1.95583, 2, '.', '' );
							}
							break;
						case 2:
						case 3:
							if ( $uni_currency_code == 'BGN' ) {
								$itemSinglePrice = number_format( (float) $itemSinglePrice / 1.95583, 2, '.', '' );
							}
							break;
					}
					$result       .= 'Продукт ИД: ' . $item['code'] . ' , Продукт: ' . $item['name'] . ' , Кол.: ' . $item['count'] . ' , Ед. цена: ' . $itemSinglePrice . '<br />';
					$result_items .= 'Продукт ИД: ' . $item['code'] . ' , Продукт: ' . $item['name'] . ' , Кол.: ' . $item['count'] . ' , Ед. цена: ' . $itemSinglePrice . '<br />';
				}

				$uni_obshta         = number_format( floatval( $uni_vnoski ) * floatval( $uni_mesecna ), 2, '.', '' );
				$uni_total_second   = 0;
				$uni_mesecna_second = 0;
				$uni_obshta_second  = 0;
				$uni_sign           = 'лева';
				$uni_sign_second    = 'евро';
				switch ( $uni_eur ) {
					case 0:
						$uni_total_second   = 0;
						$uni_mesecna_second = 0;
						$uni_obshta_second  = 0;
						$uni_sign           = 'лева';
						$uni_sign_second    = 'евро';
						break;
					case 1:
						$uni_total_second   = number_format( $uni_total / 1.95583, 2, '.', '' );
						$uni_mesecna_second = number_format( $uni_mesecna / 1.95583, 2, '.', '' );
						$uni_obshta_second  = number_format( $uni_obshta / 1.95583, 2, '.', '' );
						$uni_sign           = 'лева';
						$uni_sign_second    = 'евро';
						break;
					case 2:
						$uni_total_second   = number_format( $uni_total * 1.95583, 2, '.', '' );
						$uni_mesecna_second = number_format( $uni_mesecna * 1.95583, 2, '.', '' );
						$uni_obshta_second  = number_format( $uni_obshta * 1.95583, 2, '.', '' );
						$uni_sign           = 'евро';
						$uni_sign_second    = 'лева';
						break;
					case 3:
						$uni_total_second   = 0;
						$uni_mesecna_second = 0;
						$uni_obshta_second  = 0;
						$uni_sign           = 'евро';
						$uni_sign_second    = 'лева';
						break;
				}

				if ( $uni_total_second == 0 ) {
					$result .= '<br />' . 'Цена на стоките (' . $uni_sign . '): ' . $uni_total . '<br />';
				} else {
					$result .= '<br />' . 'Цена на стоките (' . $uni_sign . '/' . $uni_sign_second . '): ' . $uni_total . ' / ' . $uni_total_second . '<br />';
				}
				$result .= 'Първоначална вноска (' . $uni_sign . '): ' . $uni_parva . '<br />';
				$result .= 'Брой погасителни вноски: ' . $uni_vnoski . '<br />';
				if ( $uni_mesecna_second == 0 ) {
					$result .= 'Месечна вноска (' . $uni_sign . '): ' . $uni_mesecna . '<br />';
				} else {
					$result .= 'Месечна вноска (' . $uni_sign . '/' . $uni_sign_second . '): ' . $uni_mesecna . ' / ' . $uni_mesecna_second . '<br />';
				}
				$result .= 'ГПР (%): ' . $uni_gpr . '<br />';
				if ( $uni_obshta_second == 0 ) {
					$result .= 'Обща дължима сума от потребителя (' . $uni_sign . '): ' . $uni_obshta . '<br />';
				} else {
					$result .= 'Обща дължима сума от потребителя (' . $uni_sign . '/' . $uni_sign_second . '): ' . $uni_obshta . ' / ' . $uni_obshta_second . '<br />';
				}
				$result .= '<strong>Очаквайте контакт за потвърждаване на направената от Вас заявка.</strong><br />';
				$result .= 'Можете да продължите с разглеждането на нашия магазин.';

				$toName             = get_bloginfo( 'name' );
				$toEmail            = get_bloginfo( 'admin_email' );
				$toEmailAdminConfig = $paramsuni['uni_email'];
				$toml               = $toEmail . ', ' . $uni_email . ', ' . $toEmailAdminConfig;
				$subject            = 'Заявка за лизинг с UNI Credit.';
				$headers            = 'MIME-Version: 1.0' . "\r\n";
				$headers           .= 'Content-type: text/html; charset=utf-8' . "\r\n";
				wp_mail( $toml, $subject, $result, $headers );
			}
		}

		$order_id             = isset( $order_id ) ? $order_id : '';
		$uni_fname            = isset( $uni_fname ) ? $uni_fname : '';
		$uni_lname            = isset( $uni_lname ) ? $uni_lname : '';
		$uni_egn              = isset( $uni_egn ) ? $uni_egn : '';
		$uni_phone            = isset( $uni_phone ) ? $uni_phone : '';
		$uni_phone2           = isset( $uni_phone2 ) ? $uni_phone2 : '';
		$uni_email            = isset( $uni_email ) ? $uni_email : '';
		$uni_shipping_address = isset( $uni_shipping_address ) ? $uni_shipping_address : '';
		$uni_kop              = isset( $uni_kop ) ? $uni_kop : '';
		$uni_description      = isset( $uni_description ) ? $uni_description : '';
		$result_items         = isset( $result_items ) ? urlencode( base64_encode( $result_items ) ) : '';
		$uni_total            = isset( $uni_total ) ? $uni_total : '';
		$uni_parva            = isset( $uni_parva ) ? $uni_parva : '';
		$uni_vnoski           = isset( $uni_vnoski ) ? $uni_vnoski : '';
		$uni_mesecna          = isset( $uni_mesecna ) ? $uni_mesecna : '';
		$uni_gpr              = isset( $uni_gpr ) ? $uni_gpr : '';
		$uni_application      = isset( $uni_application ) ? substr( $uni_application, 8 ) : '';
		$uni_api              = isset( $uni_api ) ? $uni_api : '';
		$uni_eur              = isset( $uni_eur ) ? $uni_eur : '0';

		// Return thankyou redirect
		WC()->cart->empty_cart();

		if ( $uni_proces2 == 1 ) {
			return array(
				'result'   => 'success',
				'redirect' => esc_url_raw( site_url( '/unipaymentredirect/?uni_proces2=' . $uni_proces2 . '&order_id=' . $order_id . '&uni_fname=' . $uni_fname . '&uni_lname=' . $uni_lname . '&uni_egn=' . $uni_egn . '&uni_phone=' . $uni_phone . '&uni_phone2=' . $uni_phone2 . '&uni_email=' . $uni_email . '&uni_shipping_address=' . $uni_shipping_address . '&uni_kop=' . $uni_kop . '&uni_description=' . $uni_description . '&result_items=' . $result_items . '&uni_total=' . $uni_total . '&uni_parva=' . $uni_parva . '&uni_vnoski=' . $uni_vnoski . '&uni_mesecna=' . $uni_mesecna . '&uni_gpr=' . $uni_gpr . '&uni_eur=' . $uni_eur ) ),
			);
		} else {
			if ( $uni_application == '' || $uni_api == '' ) {
				wc_add_notice( 'Има временен проблем с услугата за изпращане на поръчки към Банката. Моля опитайте по-късно.', 'error' );
				return;
			}
			$order->update_status( $this->order_status, 'UNICredit Payment initiated.' );
			return array(
				'result'   => 'success',
				'redirect' => esc_url_raw( 'https://' . $uni_application . '/' . $uni_api ),
			);
		}
	}
}
