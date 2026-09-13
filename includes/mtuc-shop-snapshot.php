<?php
/**
 * Shop snapshot validation and secret redaction (AUD-WOO-019-F07 / AUD-WOO-018).
 *
 * One pure validator/normalizer serves both inbound paths — the outbound
 * GET /shop refresh and the CP → shop push — so neither can accept a snapshot
 * the other would reject.
 *
 * SmartUCF bank credentials (`uni_user` / `uni_password`) are classified and
 * stored in the dedicated encrypted option. The general shop cache is always
 * credential-free after prepare/ingest.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a key for case/separator-insensitive secret matching.
 *
 * `access_token`, `accessToken`, `access-token` and `AccessToken` all collapse
 * to the same comparison form.
 *
 * @param string $key Raw key.
 * @return string
 */
function mtuc_normalize_snapshot_key( string $key ): string {
	$normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) );

	return is_string( $normalized ) ? $normalized : '';
}

/**
 * Exact normalized key names that always carry secret material.
 *
 * @return array<int, string>
 */
function mtuc_shop_snapshot_secret_key_names(): array {
	return array(
		'secret',
		'token',
		'authorization',
		'password',
		'passwd',
		'privatekey',
		'apikey',
		'certificate',
		'certificatepem',
		'privatekeypem',
		'credentials',
	);
}

/**
 * Normalized suffixes that mark a key as secret regardless of its prefix.
 *
 * Catches vendor-prefixed variants (`clientSecret`, `x-bearer-token`,
 * `shopPrivateKey`, …) without touching established shop-data fields.
 *
 * @return array<int, string>
 */
function mtuc_shop_snapshot_secret_key_suffixes(): array {
	return array(
		'secret',
		'password',
		'passwd',
		'token',
		'privatekey',
		'apikey',
		'authorization',
		'certificatepem',
	);
}

/**
 * Whether a snapshot key must never be persisted in the shop cache.
 *
 * SmartUCF credential aliases are stripped by the dedicated credential helper;
 * this filter still removes tokens/secrets/certificate material.
 *
 * @param mixed $key Array key from the decoded snapshot.
 * @return bool
 */
function mtuc_is_shop_snapshot_secret_key( $key ): bool {
	if ( ! is_string( $key ) ) {
		return false;
	}

	$normalized = mtuc_normalize_snapshot_key( $key );
	if ( '' === $normalized ) {
		return false;
	}

	// Credential aliases are handled by mtuc_strip_smartucf_credentials_from_snapshot.
	if ( function_exists( 'mtuc_is_smartucf_credential_alias_key' )
		&& mtuc_is_smartucf_credential_alias_key( $key )
	) {
		return true;
	}

	if ( in_array( $normalized, mtuc_shop_snapshot_secret_key_names(), true ) ) {
		return true;
	}

	foreach ( mtuc_shop_snapshot_secret_key_suffixes() as $suffix ) {
		if ( strlen( $normalized ) > strlen( $suffix )
			&& substr( $normalized, -strlen( $suffix ) ) === $suffix
		) {
			return true;
		}
	}

	foreach ( mtuc_split_snapshot_key_segments( $key ) as $segment ) {
		if ( in_array( $segment, mtuc_shop_snapshot_secret_key_suffixes(), true )
			|| in_array( $segment, mtuc_shop_snapshot_secret_key_names(), true )
		) {
			return true;
		}
	}

	return false;
}

/**
 * Split a key into lowercase word segments across `_`, `-` and camelCase.
 *
 * @param string $key Raw key.
 * @return array<int, string>
 */
function mtuc_split_snapshot_key_segments( string $key ): array {
	$spaced = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', $key );
	$spaced = is_string( $spaced ) ? $spaced : $key;
	$parts  = preg_split( '/[^A-Za-z0-9]+/', $spaced );

	if ( ! is_array( $parts ) ) {
		return array();
	}

	$segments = array();
	foreach ( $parts as $part ) {
		$part = strtolower( (string) $part );
		if ( '' !== $part ) {
			$segments[] = $part;
		}
	}

	return $segments;
}

/**
 * Recursively drop every secret-bearing key, at any nesting depth.
 *
 * @param mixed $value Snapshot value.
 * @return mixed
 */
function mtuc_strip_shop_snapshot_secrets( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$clean = array();
	foreach ( $value as $key => $item ) {
		if ( mtuc_is_shop_snapshot_secret_key( $key ) ) {
			continue;
		}

		$clean[ $key ] = is_array( $item ) ? mtuc_strip_shop_snapshot_secrets( $item ) : $item;
	}

	return $clean;
}

/**
 * Resolve a shop credential field from a (possibly hydrated) runtime shop array.
 *
 * @param array<string, mixed> $shop  Shop `data` object.
 * @param string               $field Credential field name.
 * @return string
 */
function mtuc_resolve_shop_credential( array $shop, string $field ): string {
	return (string) ( $shop[ $field ] ?? '' );
}

/**
 * Validate a shop snapshot against the canonical contract.
 *
 * @param mixed  $data   Decoded shop `data` value.
 * @param string $unicid Authenticated shop unicid.
 * @return array<int, string> Violations; empty when the snapshot is valid.
 */
function mtuc_validate_shop_snapshot( $data, string $unicid ): array {
	$violations = array();

	if ( ! function_exists( 'mtuc_cp_is_json_object' ) || ! mtuc_cp_is_json_object( $data ) ) {
		return array( 'data_not_object' );
	}

	if ( array() === $data ) {
		return array( 'data_empty' );
	}

	if ( array_key_exists( 'unicid', $data )
		&& ( ! is_string( $data['unicid'] ) || '' === $unicid || ! hash_equals( $unicid, $data['unicid'] ) )
	) {
		$violations[] = 'unicid_mismatch';
	}

	return $violations;
}

/**
 * Validate and commit a shop snapshot (credentials + optional cache writer).
 *
 * COMPLETE ingress rotates the dedicated encrypted option and persists a
 * credential-free cache snapshot when a writer is provided.
 * ABSENT preserves the encrypted option byte-for-byte.
 * INVALID mutates neither store.
 *
 * @param mixed         $data         Decoded shop `data` value.
 * @param string        $unicid       Authenticated shop unicid.
 * @param callable|null $cache_writer Optional cache persistence callback.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_prepare_shop_snapshot( $data, string $unicid, $cache_writer = null ) {
	$violations = mtuc_validate_shop_snapshot( $data, $unicid );

	if ( ! empty( $violations ) ) {
		return new WP_Error(
			'mtuc_shop_snapshot_invalid',
			__( 'КП върна невалиден shop snapshot.', 'mtunicredit' ),
			array(
				'status'     => 422,
				'error'      => 'shop_snapshot_invalid',
				'violations' => $violations,
			)
		);
	}

	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'mtuc_shop_snapshot_invalid',
			__( 'КП върна невалиден shop snapshot.', 'mtunicredit' ),
			array(
				'status'     => 422,
				'error'      => 'shop_snapshot_invalid',
				'violations' => array( 'data_not_object' ),
			)
		);
	}

	if ( ! function_exists( 'mtuc_commit_authenticated_shop_snapshot' ) ) {
		$stripped = mtuc_strip_shop_snapshot_secrets( $data );
		if ( function_exists( 'mtuc_strip_smartucf_credentials_from_snapshot' ) ) {
			$stripped = mtuc_strip_smartucf_credentials_from_snapshot( $stripped );
		}
		return $stripped;
	}

	return mtuc_commit_authenticated_shop_snapshot( $unicid, $data, $cache_writer );
}
