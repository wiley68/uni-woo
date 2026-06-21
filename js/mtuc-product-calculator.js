(function ($) {
	"use strict";

	$(function () {
		if (typeof mtucCalculator === "undefined") {
			return;
		}

		const $root = $(".mtuc-product-calculator").first();
		if (!$root.length) {
			return;
		}

		let variationId = null;
		let refreshTimer = null;
		let refreshRequest = null;

		const convertToDotDecimal = (price) => {
			price = String(price || "").trim();
			if (price.includes(".") && price.includes(",")) {
				if (price.lastIndexOf(",") < price.lastIndexOf(".")) {
					price = price.replace(/,/g, "");
				} else {
					price = price.replace(/\./g, "").replace(/,/g, ".");
				}
			} else if (price.includes(",")) {
				if (price.split(",").length - 1 === 1) {
					price = price.replace(/,/g, ".");
				} else {
					price = price.replace(/,/g, "");
				}
			}

			return price;
		};

		const extractVariationPriceText = () => {
			const variationDiv = document.getElementsByClassName(
				"woocommerce-variation-price",
			);
			if (typeof variationDiv[0] === "undefined") {
				return "";
			}

			const variationSpan1 = variationDiv[0].getElementsByTagName("span");
			if (typeof variationSpan1[0] === "undefined") {
				return "";
			}

			const variationIns = variationSpan1[0].getElementsByTagName("ins");
			if (typeof variationIns[0] !== "undefined") {
				const variationSpan3 =
					variationIns[0].getElementsByTagName("span");
				if (typeof variationSpan3[0] !== "undefined") {
					return variationSpan3[0].textContent || "";
				}
			}

			const variationSpan2 =
				variationSpan1[0].getElementsByTagName("span");
			if (typeof variationSpan2[0] !== "undefined") {
				return variationSpan2[0].textContent || "";
			}

			return variationSpan1[0].textContent || "";
		};

		const extractWapfGrandTotalText = () => {
			const wapfGrand = document.querySelector(
				".wapf-product-totals .wapf-grand-total",
			);
			if (!wapfGrand) {
				return { text: "", isGrandTotal: false };
			}

			const wapfTotals = wapfGrand.closest(".wapf-product-totals");
			if (!wapfTotals) {
				return { text: "", isGrandTotal: false };
			}

			const wapfStyle = window.getComputedStyle(wapfTotals);
			if (
				wapfStyle.display === "none" ||
				wapfStyle.visibility === "hidden"
			) {
				return { text: "", isGrandTotal: false };
			}

			const wapfText =
				wapfGrand.textContent && wapfGrand.textContent.trim();
			if (!wapfText) {
				return { text: "", isGrandTotal: false };
			}

			return { text: wapfText, isGrandTotal: true };
		};

		const extractFallbackPriceText = () => {
			const selectors = [
				".summary .price ins .woocommerce-Price-amount",
				".summary .price .woocommerce-Price-amount",
				".product .price ins .woocommerce-Price-amount",
				".product .price .woocommerce-Price-amount",
			];

			for (let i = 0; i < selectors.length; i += 1) {
				const el = document.querySelector(selectors[i]);
				if (el && el.textContent) {
					return el.textContent.trim();
				}
			}

			return "";
		};

		const getLinePrice = () => {
			let priceText = extractVariationPriceText();
			let fromWapfGrandTotal = false;

			const wapf = extractWapfGrandTotalText();
			if (wapf.isGrandTotal) {
				priceText = wapf.text;
				fromWapfGrandTotal = true;
			}

			if (!priceText) {
				priceText = extractFallbackPriceText();
			}

			priceText = convertToDotDecimal(priceText.replace(/[^\d.,]/g, ""));

			const quantity = parseInt(
				$('input[name="quantity"]').first().val(),
				10,
			);
			const qty = Number.isNaN(quantity) || quantity <= 0 ? 1 : quantity;
			const rawPrice = parseFloat(priceText) || 0;

			return fromWapfGrandTotal ? rawPrice : rawPrice * qty;
		};

		const applyRefreshPayload = (data) => {
			if (!data || !data.visible) {
				$root.hide();
				document.dispatchEvent(
					new CustomEvent("mtuc-calculator-refreshed", {
						detail: data || { visible: false },
					}),
				);
				return;
			}

			$root.show();

			const $standardBtn = $root.find(
				".mtuc-product-calculator__btn--standard",
			);
			const $promoBtn = $root.find(
				".mtuc-product-calculator__btn--promo",
			);
			const hasStandard = !!(data.standard && data.standard.visible);
			const hasPromo = !!(data.promo && data.promo.visible);

			if (!hasStandard && !hasPromo) {
				$root.hide();
				document.dispatchEvent(
					new CustomEvent("mtuc-calculator-refreshed", {
						detail: { visible: false },
					}),
				);
				return;
			}

			if (hasStandard) {
				$standardBtn.show();
				$standardBtn
					.find(".mtuc-product-calculator__price")
					.text(data.standard.price_text || "");
			} else {
				$standardBtn.hide();
			}

			if (hasPromo) {
				$promoBtn.show();
				$promoBtn
					.find(".mtuc-product-calculator__price")
					.text(data.promo.price_text || "");
			} else {
				$promoBtn.hide();
			}

			document.dispatchEvent(
				new CustomEvent("mtuc-calculator-refreshed", { detail: data }),
			);
		};

		const refreshCalculator = () => {
			window.clearTimeout(refreshTimer);
			refreshTimer = window.setTimeout(function () {
				const linePrice = getLinePrice();
				if (linePrice <= 0) {
					$root.hide();
					return;
				}

				if (refreshRequest && refreshRequest.readyState !== 4) {
					refreshRequest.abort();
				}

				refreshRequest = $.post(mtucCalculator.ajaxUrl, {
					action: "mtuc_product_calculator_refresh",
					security: mtucCalculator.nonce,
					product_id:
						parseInt($root.data("mtuc-product-id"), 10) ||
						mtucCalculator.productId,
					variation_id: variationId || 0,
					line_price: linePrice.toFixed(2),
				}).done(function (response) {
					if (response && response.success && response.data) {
						applyRefreshPayload(response.data);
					}
				});
			}, 80);
		};

		$("form.variations_form").on(
			"woocommerce_variation_select_change",
			function () {
				variationId = null;
				refreshCalculator();
			},
		);

		$("form.variations_form").on(
			"found_variation",
			function (event, variation) {
				variationId = variation.variation_id;
				refreshCalculator();
			},
		);

		$("form.variations_form").on("reset_data", function () {
			variationId = null;
			refreshCalculator();
		});

		if ($('[name="quantity"]').length) {
			$('[name="quantity"]')
				.first()
				.on("change input", function () {
					refreshCalculator();
				});
		}

		const variationNode = document.querySelector(
			"div.woocommerce-variation.single_variation",
		);
		if (variationNode instanceof Node) {
			new MutationObserver(function () {
				refreshCalculator();
			}).observe(variationNode, {
				childList: true,
				subtree: true,
			});
		}

		const wapfTotalsNode = document.querySelector(".wapf-product-totals");
		if (wapfTotalsNode instanceof Node) {
			new MutationObserver(function () {
				refreshCalculator();
			}).observe(wapfTotalsNode, {
				childList: true,
				subtree: true,
				characterData: true,
				attributes: true,
				attributeFilter: ["style", "class"],
			});
		}

		if (!variationNode) {
			refreshCalculator();
		}

		window.mtucGetProductLineContext = function () {
			return {
				linePrice: getLinePrice(),
				variationId: variationId || 0,
				productId:
					parseInt($root.data("mtuc-product-id"), 10) ||
					mtucCalculator.productId,
			};
		};

		window.mtucRefreshProductCalculator = refreshCalculator;
	});
})(jQuery);
