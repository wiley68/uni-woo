<?php
/**
 * Shop consents list (popup + checkout).
 *
 * @package MTUC
 *
 * @var array<int, array<string, mixed>> $consents    Normalized consents.
 * @var string                          $id_prefix   Checkbox/label id prefix.
 * @var string                          $input_name  Checkbox input name attribute.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $consents ) || ! is_array( $consents ) ) {
	return;
}

$id_prefix  = isset( $id_prefix ) ? (string) $id_prefix : 'mtuc-consent';
$input_name = isset( $input_name ) ? (string) $input_name : 'mtuc_consent[]';
?>
<div class="mtuc-popup__consents" aria-label="<?php esc_attr_e( 'Съгласия', 'mtunicredit' ); ?>">
	<?php foreach ( $consents as $mtuc_consent ) : ?>
		<?php
		$mtuc_consent_id           = (int) ( $mtuc_consent['id'] ?? 0 );
		$mtuc_consent_name         = (string) ( $mtuc_consent['name'] ?? '' );
		$mtuc_consent_url          = (string) ( $mtuc_consent['url'] ?? '' );
		$mtuc_consent_has_checkbox = ! empty( $mtuc_consent['has_checkbox'] );
		$mtuc_consent_input_id     = $id_prefix . '-' . $mtuc_consent_id;
		$mtuc_consent_item_class   = 'mtuc-popup__consent' . ( $mtuc_consent_has_checkbox ? '' : ' mtuc-popup__consent--info' );
		?>
		<div class="<?php echo esc_attr( $mtuc_consent_item_class ); ?>">
			<?php if ( $mtuc_consent_has_checkbox ) : ?>
				<input
					type="checkbox"
					class="mtuc-popup__consent-checkbox"
					id="<?php echo esc_attr( $mtuc_consent_input_id ); ?>"
					name="<?php echo esc_attr( $input_name ); ?>"
					value="<?php echo esc_attr( (string) $mtuc_consent_id ); ?>"
					data-mtuc-consent-id="<?php echo esc_attr( (string) $mtuc_consent_id ); ?>"
				/>
				<label class="mtuc-popup__consent-label" for="<?php echo esc_attr( $mtuc_consent_input_id ); ?>">
					<?php if ( '' !== $mtuc_consent_url ) : ?>
						<a href="<?php echo esc_url( $mtuc_consent_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $mtuc_consent_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $mtuc_consent_name ); ?>
					<?php endif; ?>
				</label>
			<?php else : ?>
				<p class="mtuc-popup__consent-text">
					<?php if ( '' !== $mtuc_consent_url ) : ?>
						<a href="<?php echo esc_url( $mtuc_consent_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $mtuc_consent_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $mtuc_consent_name ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
