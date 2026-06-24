(function ($) {
	"use strict";

	let cartTotal =
		typeof mtucCartCalculator !== "undefined" &&
		typeof mtucCartCalculator.cartTotal === "number"
			? mtucCartCalculator.cartTotal
			: 0;

	const getBuyLabel = () => {
		if (
			typeof mtucCartCalculator !== "undefined" &&
			mtucCartCalculator.i18n &&
			mtucCartCalculator.i18n.buyLabel
		) {
			return String(mtucCartCalculator.i18n.buyLabel);
		}

		return "Купи на изплащане";
	};

	const getRoot = () => {
		return $(
			".mtuc-cart-calculator-fragment .mtuc-cart-calculator",
		).first();
	};

	const syncCartTotalGlobals = (value) => {
		const total = parseFloat(value) || 0;
		cartTotal = total;

		if (typeof mtucCartCalculator !== "undefined") {
			mtucCartCalculator.cartTotal = total;
		}

		if (typeof mtucPopup !== "undefined") {
			mtucPopup.cartTotal = total;
		}
	};

	window.mtucGetProductLineContext = function () {
		return {
			source: "cart",
			linePrice: cartTotal,
			productId: 0,
			variationId: 0,
			quantity: 1,
		};
	};

	const setButtonContent = ($btn, offer, showInstallment) => {
		const $content = $btn.find(".mtuc-product-calculator__content");
		$content.empty();

		if (!offer || offer.image_only) {
			return;
		}

		$("<span>", {
			class: "mtuc-product-calculator__label",
			text: getBuyLabel(),
		}).appendTo($content);

		if (showInstallment && offer.price_text) {
			$("<span>", {
				class: "mtuc-product-calculator__price",
				text: String(offer.price_text),
			}).appendTo($content);
		}
	};

	const applyOfferButton = ($btn, offer, showInstallment) => {
		if (!offer || !offer.visible) {
			$btn.hide();
			return false;
		}

		const imageOnly = !!offer.image_only;
		$btn.show();
		$btn.attr("data-mtuc-image-only", imageOnly ? "1" : "0");
		$btn.toggleClass("mtuc-product-calculator__btn--image-only", imageOnly);
		setButtonContent($btn, offer, showInstallment);

		return true;
	};

	const applyRefreshPayload = (data) => {
		const $root = getRoot();
		const $fragment = $(".mtuc-cart-calculator-fragment").first();

		if (!data || !data.visible) {
			if ($fragment.length) {
				$fragment.hide();
			} else if ($root.length) {
				$root.hide();
			}

			syncCartTotalGlobals(0);
			document.dispatchEvent(
				new CustomEvent("mtuc-calculator-refreshed", {
					detail: data || { visible: false },
				}),
			);
			return;
		}

		if ($fragment.length) {
			$fragment.show();
		}
		if ($root.length) {
			$root.show();
		}

		syncCartTotalGlobals(data.cart_total);

		const showInstallment = !!data.show_installment;
		const $standardBtn = $(
			".mtuc-cart-calculator .mtuc-product-calculator__btn--standard",
		).first();
		const $promoBtn = $(
			".mtuc-cart-calculator .mtuc-product-calculator__btn--promo",
		).first();
		const hasStandard = applyOfferButton(
			$standardBtn,
			data.standard,
			showInstallment,
		);
		const hasPromo = applyOfferButton(
			$promoBtn,
			data.promo,
			showInstallment,
		);

		if ($root.length) {
			$root.toggleClass(
				"mtuc-product-calculator--no-vnoska",
				!showInstallment ||
					(hasStandard && data.standard && data.standard.image_only),
			);
		}

		if (!hasStandard && !hasPromo) {
			if ($fragment.length) {
				$fragment.hide();
			} else if ($root.length) {
				$root.hide();
			}
		}

		document.dispatchEvent(
			new CustomEvent("mtuc-calculator-refreshed", { detail: data }),
		);
	};

	$(function () {
		if (typeof mtucCartCalculator === "undefined") {
			return;
		}

		let refreshTimer = null;
		let refreshRequest = null;

		const refreshCartCalculator = () => {
			window.clearTimeout(refreshTimer);
			refreshTimer = window.setTimeout(function () {
				if (refreshRequest && refreshRequest.readyState !== 4) {
					refreshRequest.abort();
				}

				refreshRequest = $.post(mtucCartCalculator.ajaxUrl, {
					action: "mtuc_cart_calculator_refresh",
					security: mtucCartCalculator.nonce,
				}).done(function (response) {
					if (response && response.success && response.data) {
						applyRefreshPayload(response.data);
					}
				});
			}, 120);
		};

		window.mtucRefreshCartCalculator = refreshCartCalculator;

		$(document.body).on(
			"updated_wc_div updated_cart_totals wc_fragments_refreshed removed_from_cart item_removed_from_classic_cart",
			function () {
				refreshCartCalculator();
			},
		);

		$(document).on(
			"click",
			".woocommerce-cart-form button[name='update_cart']",
			function () {
				window.setTimeout(refreshCartCalculator, 300);
			},
		);

		$(document).on(
			"change input",
			".woocommerce-cart-form input.qty",
			function () {
				const $form = $(this).closest("form.woocommerce-cart-form");
				if (
					$form.length &&
					$form.find("button[name='update_cart']").length
				) {
					return;
				}

				refreshCartCalculator();
			},
		);
	});
})(jQuery);
