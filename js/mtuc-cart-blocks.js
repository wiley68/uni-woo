(function ($) {
	"use strict";

	const settings = window.mtucCartBlocks || {};
	let fragmentHtml = settings.fragmentHtml || "";
	let cartSignature = "";
	let refreshTimer = null;

	const getCartSignature = () => {
		if (
			!window.wp ||
			!window.wp.data ||
			!window.wc ||
			!window.wc.wcBlocksData
		) {
			return "";
		}

		const cartStore = window.wc.wcBlocksData.CART_STORE_KEY;
		const cart = window.wp.data.select(cartStore).getCartData();
		if (!cart || !Array.isArray(cart.items)) {
			return "";
		}

		const itemsSignature = cart.items
			.map((item) => {
				return (
					String(item.key || "") + ":" + String(item.quantity || 0)
				);
			})
			.join("|");

		return (
			itemsSignature +
			"|" +
			String(cart.totals?.total_items || "") +
			":" +
			String(cart.totals?.total_tax || "")
		);
	};

	const mountCalculator = () => {
		if (!fragmentHtml) {
			return false;
		}

		if (typeof window.mtucMountCartCalculatorFragment === "function") {
			return window.mtucMountCartCalculatorFragment(fragmentHtml);
		}

		return false;
	};

	const refreshFromServer = () => {
		if (!settings.ajaxUrl || !settings.nonce) {
			return;
		}

		if (typeof window.mtucRefreshCartCalculator === "function") {
			window.mtucRefreshCartCalculator();
			return;
		}

		$.post(settings.ajaxUrl, {
			action: "mtuc_cart_blocks_refresh",
			security: settings.nonce,
		})
			.done((response) => {
				if (!response || !response.success || !response.data) {
					return;
				}

				if (typeof response.data.fragmentHtml === "string") {
					fragmentHtml = response.data.fragmentHtml;
				}

				mountCalculator();

				if (
					typeof window.mtucApplyCartCalculatorRefresh === "function"
				) {
					window.mtucApplyCartCalculatorRefresh(response.data);
				}
			})
			.fail(() => {});
	};

	const scheduleRefresh = () => {
		window.clearTimeout(refreshTimer);
		refreshTimer = window.setTimeout(refreshFromServer, 250);
	};

	const bindCartStoreSubscription = () => {
		if (!window.wp || !window.wp.data) {
			return;
		}

		cartSignature = getCartSignature();

		window.wp.data.subscribe(() => {
			const nextSignature = getCartSignature();
			if (nextSignature === cartSignature) {
				return;
			}

			cartSignature = nextSignature;
			scheduleRefresh();
		});
	};

	const boot = () => {
		if (!settings.blocks) {
			return;
		}

		mountCalculator();

		if (typeof window.mtucRefreshCartCalculator === "function") {
			window.mtucRefreshCartCalculator();
		}

		bindCartStoreSubscription();
	};

	$(boot);
})(jQuery);
