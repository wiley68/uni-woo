<?php
/**
 * WordPress admin screen stubs for IDE (not loaded at runtime).
 *
 * @package MTUC
 */

/**
 * @return string
 */
function get_admin_page_title(): string
{
	return '';
}

/**
 * @param string       $text
 * @param string       $type
 * @param string       $name
 * @param bool         $wrap
 * @param array|string $other_attributes
 * @return void
 */
function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ): void {}

/**
 * @param bool|int|string $checked
 * @param bool|int|string $current
 * @param bool            $display
 * @return string
 */
function checked( $checked, $current = true, $display = true ): string
{
	unset( $checked, $current, $display );

	return '';
}

/**
 * @param bool|int|string $selected
 * @param bool|int|string $current
 * @param bool            $display
 * @return string
 */
function selected( $selected, $current = true, $display = true ): string
{
	unset( $selected, $current, $display );

	return '';
}

/**
 * @param string   $file
 * @param callable $callback
 * @return void
 */
function register_activation_hook( $file, $callback ): void {}
