=== УниКредит покупки на Кредит ===
Contributors: wiley68
Tags: кредитен, калкулатор, woocommerce, unicredit
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 2.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

УниКредит покупки на Кредит — WooCommerce модул, комуникиращ с Контролния панел и SmartUCF.

== Description ==

Дава възможност на Вашите клиенти да закупуват стока на изплащане с УНИ Кредит.

== Installation ==

1. Качете папката `mtunicredit` в `/wp-content/plugins/`.
2. Активирайте плъгина от меню Плъгини в WordPress.
3. Уверете се, че WooCommerce е активен.
4. Конфигурирайте връзката с Контролния панел от настройките на модула.

== Deployment (vendor) ==

Before creating the production installation ZIP, add `secrets/smartucf-key.php` containing the SmartUCF private-key password.

The ZIP is prepared manually and uploaded to the Bank/CP portal. It contains Git source plus deployment-only files (certificates, `secrets/smartucf-key.php`). Merchants install the ZIP normally; they do not edit wp-config or environment variables for the SSL password.

== Changelog ==

= 2.0.1 =
* Authoritative product price and quantity for product-popup financing (AUD-WOO-001).
* Canonical full payable order amount across cart/checkout → CP → SmartUCF (AUD-WOO-002).
* WooCommerce/CP transaction currency integrity gate (AUD-WOO-003).
* Native Woo order status remains merchant-controlled after create; bank callbacks update financing state only (AUD-WOO-004).
* Ambiguous CP create outcomes are marked for safe idempotent retry instead of claiming non-creation (AUD-WOO-005).
* Process 1 records `bank_sent_process1` only after SmartUCF success; Process 2 after CP create (AUD-WOO-008).
* CP status PATCH failures persist pending sync diagnostics with bounded retry (AUD-WOO-009).
* Durable popup operation token prevents duplicate Woo/CP/SmartUCF orders on retry (AUD-WOO-006).
* CP shop order_id equals Woo internal numeric order ID (max 13), persisted meta and exact HPOS lookup (AUD-WOO-007; hotfix: not base36/W-prefix).
* Shop configuration stale-if-error fallback with refresh lock and stampede protection (AUD-WOO-010).
* SmartUCF SSL private-key password from deployment secret file or dev overrides, not plugin source (AUD-WOO-011).
* Customer-safe error normalization for AJAX, checkout and gateway flows (AUD-WOO-012).
* Order-level financing diagnostics independent of WP_DEBUG (AUD-WOO-013).

= 1.0.0 =
* Първоначална версия — гръбнак на модула.
