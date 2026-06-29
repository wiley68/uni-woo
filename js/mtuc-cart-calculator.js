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

	const findCartMountPoint = () => {
		return (
			document.querySelector(
				".wc-block-cart__submit-container .wc-block-cart__submit",
			) ||
			document.querySelector(".wc-block-cart__submit") ||
			document.querySelector(".wc-block-cart__submit-button") ||
			document.querySelector(".woocommerce-cart .wc-proceed-to-checkout")
		);
	};

	const mountCartCalculatorFragment = (html) => {
		if (!html) {
			return false;
		}

		const mountPoint = findCartMountPoint();
		if (!mountPoint) {
			return false;
		}

		const existing = document.querySelector(
			".mtuc-cart-calculator-fragment",
		);
		if (existing) {
			existing.outerHTML = html;
			return true;
		}

		const host = document.createElement("div");
		host.innerHTML = html;
		const fragment = host.firstElementChild;
		if (!fragment) {
			return false;
		}

		if (mountPoint.classList.contains("wc-proceed-to-checkout")) {
			mountPoint.insertBefore(fragment, mountPoint.firstChild);
			return true;
		}

		if (!mountPoint.parentNode) {
			return false;
		}

		mountPoint.parentNode.insertBefore(fragment, mountPoint);
		return true;
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
		if (!$btn.length) {
			return false;
		}

		if (!offer || !offer.visible) {
			$btn.hide();
			return false;
		}

		const imageOnly = !!offer.image_only;
		$btn.show().css("display", "");
		$btn.attr("data-mtuc-image-only", imageOnly ? "1" : "0");
		$btn.toggleClass("mtuc-product-calculator__btn--image-only", imageOnly);
		setButtonContent($btn, offer, showInstallment);

		return true;
	};

	const hasVisibleOffersInPayload = (data) => {
		return (
			(data.standard && data.standard.visible) ||
			(data.promo && data.promo.visible)
		);
	};

	const applyRefreshPayload = (data) => {
		if (!data || !data.visible) {
			const $fragment = $(".mtuc-cart-calculator-fragment").first();
			const $root = getRoot();

			if ($fragment.length) {
				$fragment.hide();
			} else if ($root.length) {
				$root.hide();
			}

			syncCartTotalGlobals(
				data && typeof data.cart_total !== "undefined"
					? data.cart_total
					: 0,
			);
			document.dispatchEvent(
				new CustomEvent("mtuc-calculator-refreshed", {
					detail: data || { visible: false },
				}),
			);
			return;
		}

		if (data.fragmentHtml) {
			mountCartCalculatorFragment(data.fragmentHtml);
		}

		const $fragment = $(".mtuc-cart-calculator-fragment").first();
		const $root = getRoot();

		$fragment.show().css("display", "");
		if ($root.length) {
			$root.show().css("display", "");
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
		let hasPromo = applyOfferButton($promoBtn, data.promo, showInstallment);

		if (data.standard && data.standard.image_only) {
			$promoBtn.hide();
			hasPromo = false;
		}

		if ($root.length) {
			$root.toggleClass(
				"mtuc-product-calculator--no-vnoska",
				!showInstallment ||
					(hasStandard && data.standard && data.standard.image_only),
			);
		}

		if (!hasStandard && !hasPromo && !hasVisibleOffersInPayload(data)) {
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

	window.mtucMountCartCalculatorFragment = mountCartCalculatorFragment;

	$(function () {
		if (typeof mtucCartCalculator === "undefined") {
			return;
		}

		let refreshTimer = null;
		let refreshRequest = null;
		let mountObserver = null;

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

		const bindMountObserver = () => {
			if (
				mountObserver ||
				!window.MutationObserver ||
				!(window.mtucCartBlocks && window.mtucCartBlocks.blocks)
			) {
				return;
			}

			mountObserver = new window.MutationObserver(function () {
				if (document.querySelector(".mtuc-cart-calculator-fragment")) {
					return;
				}

				if (!findCartMountPoint()) {
					return;
				}

				refreshCartCalculator();
			});

			mountObserver.observe(document.body, {
				childList: true,
				subtree: true,
			});
		};

		window.mtucRefreshCartCalculator = refreshCartCalculator;
		window.mtucApplyCartCalculatorRefresh = applyRefreshPayload;

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

		if (window.mtucCartBlocks && window.mtucCartBlocks.fragmentHtml) {
			mountCartCalculatorFragment(window.mtucCartBlocks.fragmentHtml);
		}

		bindMountObserver();
		refreshCartCalculator();
	});
})(jQuery);
