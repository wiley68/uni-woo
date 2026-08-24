<?php
/**
 * Financing email orchestration (AUD-WOO-016 Step 7).
 *
 * Decides when financing emails are sent, to whom, and which explicit
 * presentation audience to request. Row shaping / EGN policy live in
 * mtuc-financing-presentation.php — this module must not rebuild privacy rules.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce transactional email handler, when available.
 *
 * @return WC_Emails|null
 */
function mtuc_get_wc_mailer(): ?WC_Emails {
	if ( ! function_exists( 'WC' ) ) {
		return null;
	}

	$woocommerce = WC();

	return $woocommerce->mailer();
}

/**
 * Whether transactional emails should include UniCredit leasing details.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_should_show_order_credit_in_email( WC_Order $order ): bool {
	return MTUC_PAYMENT_GATEWAY_ID === $order->get_payment_method();
}

/**
 * Explicit presentation audience for a Woo transactional email context.
 *
 * @param bool $sent_to_admin Whether the email goes to admin/merchant.
 * @return string One of MTUC_CREDIT_ROWS_AUDIENCE_*.
 */
function mtuc_resolve_financing_email_audience( bool $sent_to_admin ): string {
	return $sent_to_admin
		? MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL
		: MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER;
}

/**
 * Remove the WordPress store admin_email from a recipient list (case-insensitive).
 *
 * Used by Process 2 uni_email dispatch so merchant mail is not also sent to the
 * generic store admin address. Does not invent fallback recipients.
 *
 * @param array<int, string> $recipients Candidate emails.
 * @return array<int, string>
 */
function mtuc_exclude_store_admin_from_notification_emails( array $recipients ): array {
	$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
	if ( '' === $admin_email ) {
		return array_values( $recipients );
	}

	return array_values(
		array_filter(
			$recipients,
			static function ( $email ) use ( $admin_email ) {
				return strtolower( (string) $email ) !== strtolower( $admin_email );
			}
		)
	);
}

/**
 * Render UniCredit leasing details in WooCommerce order emails.
 *
 * @param WC_Order $order      Order instance.
 * @param bool     $plain_text Whether the email is plain text.
 * @param string   $audience   Financing rows audience.
 * @return void
 */
function mtuc_render_order_credit_email_section( WC_Order $order, bool $plain_text = false, string $audience = MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER ): void {
	if ( ! mtuc_should_show_order_credit_in_email( $order ) ) {
		return;
	}

	$rows = mtuc_get_order_credit_meta_rows( $order, $audience );
	if ( empty( $rows ) ) {
		return;
	}

	if ( $plain_text ) {
		echo "\n" . esc_html__( 'УниКредит лизинг', 'mtunicredit' ) . "\n\n";

		foreach ( $rows as $label => $value ) {
			echo esc_html( $label . ': ' . $value ) . "\n";
		}

		echo "\n";
		return;
	}

	?>
	<h2><?php esc_html_e( 'УниКредит лизинг', 'mtunicredit' ); ?></h2>
	<div style="margin-bottom: 40px;">
		<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th class="td" scope="row" style="text-align: left; vertical-align: middle; border: 1px solid #eee; padding: 12px;"><?php echo esc_html( $label ); ?></th>
					<td class="td" style="text-align: left; vertical-align: middle; border: 1px solid #eee; padding: 12px;"><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Append UniCredit leasing details after the order table in transactional emails.
 *
 * @param WC_Order      $order          Order instance.
 * @param bool          $sent_to_admin  Whether the email goes to admin.
 * @param bool          $plain_text     Whether the email is plain text.
 * @param WC_Email|null $email          Email object.
 * @return void
 */
function mtuc_email_after_order_table_credit_details( $order, $sent_to_admin, $plain_text, $email ): void {
	unset( $email );

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$audience = mtuc_resolve_financing_email_audience( (bool) $sent_to_admin );
	mtuc_render_order_credit_email_section( $order, (bool) $plain_text, $audience );
}

/**
 * Send admin/customer emails for a leasing order when WC did not fire a status transition.
 *
 * WooCommerce sends transactional emails on status changes (e.g. pending → on-hold).
 * If the order stays on pending, no emails are sent unless we trigger them explicitly.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_send_leasing_order_notifications_once( WC_Order $order ): void {
	if ( (int) $order->get_meta( MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT ) ) {
		return;
	}

	if ( MTUC_PAYMENT_GATEWAY_ID !== $order->get_payment_method() ) {
		return;
	}

	$mailer = mtuc_get_wc_mailer();
	if ( ! $mailer ) {
		return;
	}

	$emails   = $mailer->get_emails();
	$order_id = $order->get_id();

	if ( ! empty( $emails['WC_Email_New_Order'] ) ) {
		$emails['WC_Email_New_Order']->trigger( $order_id, $order );
	}

	$customer_email_map = array(
		'processing' => 'WC_Email_Customer_Processing_Order',
		'on-hold'    => 'WC_Email_Customer_On_Hold_Order',
		'completed'  => 'WC_Email_Customer_Completed_Order',
	);

	$status = $order->get_status();
	if ( isset( $customer_email_map[ $status ], $emails[ $customer_email_map[ $status ] ] ) ) {
		$emails[ $customer_email_map[ $status ] ]->trigger( $order_id, $order );
	}

	$order->update_meta_data( MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT, 1 );
	$order->save();

	mtuc_send_process2_uni_email_notifications( $order );
}

/**
 * Send Process 2 leasing notification to shop uni_email recipients (excluding store admin).
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_send_process2_uni_email_notifications( WC_Order $order ): void {
	if ( ! mtuc_is_process2_order( $order ) ) {
		return;
	}

	if ( (int) $order->get_meta( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT ) ) {
		return;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return;
	}

	$recipients = mtuc_parse_shop_notification_emails( $shop );
	if ( empty( $recipients ) ) {
		return;
	}

	$recipients = mtuc_exclude_store_admin_from_notification_emails( $recipients );

	if ( empty( $recipients ) ) {
		$order->update_meta_data( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT, 1 );
		$order->save();
		return;
	}

	$rows = mtuc_get_order_credit_meta_rows( $order, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL );
	if ( empty( $rows ) ) {
		return;
	}

	$to      = array_shift( $recipients );
	$cc      = $recipients;
	$subject = sprintf(
		/* translators: 1: site name, 2: order number */
		__( '%1$s — лизинг заявка №%2$s', 'mtunicredit' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		$order->get_order_number()
	);

	$body  = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111;">';
	$body .= '<p style="margin:0 0 16px;">' . esc_html__( 'Нова заявка за лизинг (Процес 2).', 'mtunicredit' ) . '</p>';
	$body .= '<h2 style="margin:0 0 8px;font-size:16px;">' . esc_html__( 'УниКредит лизинг', 'mtunicredit' ) . '</h2>';
	$body .= '<table cellspacing="0" cellpadding="6" style="width:100%;max-width:640px;border-collapse:collapse;" border="1">';
	$body .= '<tbody>';

	foreach ( $rows as $label => $value ) {
		$body .= '<tr>'
			. '<th style="text-align:left;padding:8px;border:1px solid #eee;">' . esc_html( (string) $label ) . '</th>'
			. '<td style="text-align:left;padding:8px;border:1px solid #eee;">' . esc_html( (string) $value ) . '</td>'
			. '</tr>';
	}

	$body .= '</tbody></table></div>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( ! empty( $cc ) ) {
		$headers[] = 'Cc: ' . implode( ', ', $cc );
	}

	if ( function_exists( 'wc_mail' ) ) {
		$sent = wc_mail( $to, $subject, $body, $headers );
	} else {
		$sent = wp_mail( $to, $subject, $body, $headers );
	}

	if ( $sent ) {
		$order->update_meta_data( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT, 1 );
		$order->save();
	}
}
