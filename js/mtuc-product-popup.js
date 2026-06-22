(function ($) {
	"use strict";

	$(function () {
		const popup = document.getElementById("mtuc-product-popup");
		if (!popup || typeof mtucPopup === "undefined") {
			return;
		}

		const movePopupToBody = () => {
			if (!document.body) {
				return;
			}

			if (popup.parentNode !== document.body) {
				document.body.insertBefore(popup, document.body.firstChild);
				return;
			}

			if (document.body.firstChild !== popup) {
				document.body.insertBefore(popup, document.body.firstChild);
			}
		};

		const $popup = $(popup);
		const $step1 = $("#mtuc-popup-step-1");
		const $step2 = $("#mtuc-popup-step-2");
		const $offerType = $("#mtuc-popup-offer-type");
		const $months = $("#mtuc-popup-months");
		const $parva = $("#mtuc-popup-parva");
		const $parvaRow = $("#mtuc-popup-parva-row");
		const $buyBtn = $("#mtuc-popup-buy");
		let calculateTimer = null;
		let lastCalculation = null;
		let lastOpenTrigger = null;

		const resetParvaInput = () => {
			$parva.val("0").prop("readonly", false);
		};

		const setDualAmount = (prefix, display) => {
			const $primary = $("#mtuc-popup-" + prefix + "-primary");
			const $secondary = $("#mtuc-popup-" + prefix + "-secondary");

			if (!display) {
				$primary.text("");
				$secondary.text("");
				return;
			}

			$primary.text(display.primary || "");
			if (mtucPopup.currencyDual && display.dual) {
				$secondary.text(
					display.secondary ? "(" + display.secondary + ")" : "",
				);
			} else {
				$secondary.text("");
			}
		};

		const showStep = (step) => {
			if (step === 2) {
				$step1
					.prop("hidden", true)
					.removeClass("mtuc-popup__step--active");
				$step2
					.prop("hidden", false)
					.addClass("mtuc-popup__step--active");
				return;
			}

			$step2.prop("hidden", true).removeClass("mtuc-popup__step--active");
			$step1.prop("hidden", false).addClass("mtuc-popup__step--active");
		};

		const formatMonthLabel = (months, desc) => {
			let label = (mtucPopup.i18n.monthsLabel || "%d месеца").replace(
				"%d",
				String(months),
			);

			if (desc) {
				label += " - " + desc;
			}

			return label;
		};

		// Native <option> padding is unreliable — trailing spaces offset text from the right edge.
		const formatMonthOptionLabel = (months, desc) => {
			return formatMonthLabel(months, desc) + "\u00A0\u00A0\u00A0";
		};

		const getMonthOptionValue = (entry) => {
			if (entry && typeof entry === "object") {
				return parseInt(entry.months, 10);
			}

			return parseInt(entry, 10);
		};

		const getMonthOptionDesc = (entry) => {
			if (entry && typeof entry === "object" && entry.desc) {
				return String(entry.desc);
			}

			return "";
		};

		const getMonthOptionKey = (entry) => {
			if (entry && typeof entry === "object" && entry.key) {
				return String(entry.key);
			}

			const months = getMonthOptionValue(entry);
			return months ? String(months) + ":0" : "";
		};

		const parseSchemeKey = (schemeKey) => {
			const key = String(schemeKey || "");
			if (key.indexOf("p:") === 0) {
				const parts = key.slice(2).split(":");
				return {
					schemeType: "promo",
					months: parseInt(parts[0], 10) || 0,
					filterId: parseInt(parts[1], 10) || 0,
				};
			}

			const parts = key.split(":");
			return {
				schemeType: "standard",
				months: parseInt(parts[0], 10) || 0,
				filterId: parseInt(parts[1], 10) || 0,
			};
		};

		const rebuildMonthsSelect = (offerType) => {
			const enabled =
				(mtucPopup.enabledMonthsByOffer &&
					mtucPopup.enabledMonthsByOffer[offerType]) ||
				[];
			const preferred =
				(mtucPopup.defaultSchemeByOffer &&
					mtucPopup.defaultSchemeByOffer[offerType]) ||
				"";

			$months.empty();

			if (!enabled.length) {
				$months.append(
					$("<option>", {
						value: "",
						text: mtucPopup.i18n.noMonths || "Няма налични срокове",
					}),
				);
				$months.prop("disabled", true);
				return "";
			}

			const schemeKeys = [];

			enabled.forEach((entry) => {
				const months = getMonthOptionValue(entry);
				const desc = getMonthOptionDesc(entry);
				const schemeKey = getMonthOptionKey(entry);

				if (!months || !schemeKey) {
					return;
				}

				schemeKeys.push(schemeKey);
				$months.append(
					$("<option>", {
						value: schemeKey,
						text: formatMonthOptionLabel(months, desc),
					}),
				);
			});

			$months.prop("disabled", false);
			if (schemeKeys.indexOf(String(preferred)) !== -1) {
				$months.val(String(preferred));
			} else if (schemeKeys.length) {
				$months.val(schemeKeys[0]);
			}

			return String($months.val() || "");
		};

		const openPopup = (offerType) => {
			movePopupToBody();
			$offerType.val(offerType);
			showStep(1);
			resetParvaInput();
			lastCalculation = null;
			rebuildMonthsSelect(offerType);
			$popup
				.removeAttr("hidden")
				.addClass("is-open")
				.attr("aria-hidden", "false");
			document.body.classList.add("mtuc-popup-open");

			if ($months.prop("disabled")) {
				window.alert(mtucPopup.i18n.noMonths);
				closePopup();
				return;
			}

			calculateNow();
		};

		const releasePopupFocus = () => {
			const active = document.activeElement;
			if (!active || !popup.contains(active)) {
				return;
			}

			if (
				lastOpenTrigger &&
				document.body.contains(lastOpenTrigger) &&
				typeof lastOpenTrigger.focus === "function"
			) {
				lastOpenTrigger.focus();
				return;
			}

			active.blur();
		};

		const closePopup = () => {
			releasePopupFocus();
			$popup
				.removeClass("is-open")
				.attr("aria-hidden", "true")
				.attr("hidden", "hidden");
			document.body.classList.remove("mtuc-popup-open");
			showStep(1);
			resetParvaInput();
			lastCalculation = null;
		};

		const applyCalculation = (data) => {
			lastCalculation = data;

			setDualAmount("price", data.price_display);
			setDualAmount("loan", data.loan_display);
			setDualAmount("monthly", data.monthly_display);
			setDualAmount("total", data.total_display);

			$("#mtuc-popup-glp").text(data.glp + "%");
			$("#mtuc-popup-gpr").text(data.gpr + "%");

			if (data.show_parva) {
				$parvaRow.removeClass("mtuc-popup__row--hidden");
			} else {
				$parvaRow.addClass("mtuc-popup__row--hidden");
			}

			$parva.val(data.parva);

			if (data.parva_locked) {
				$parva.prop("readonly", true);
			} else {
				$parva.prop("readonly", false);
			}

			$buyBtn.prop("disabled", false).removeClass("is-disabled");
		};

		const getProductLineContext = () => {
			if (typeof window.mtucGetProductLineContext === "function") {
				return window.mtucGetProductLineContext();
			}

			return {
				linePrice: 0,
				variationId: 0,
				productId:
					parseInt($("#mtuc-popup-product-id").val(), 10) ||
					mtucPopup.productId,
			};
		};

		const calculateNow = () => {
			const schemeKey = String($months.val() || "");
			const scheme = parseSchemeKey(schemeKey);
			if (!scheme.months) {
				return;
			}
			const parva = parseFloat($parva.val()) || 0;
			const lineContext = getProductLineContext();

			$buyBtn.prop("disabled", true).addClass("is-disabled");

			$.post(mtucPopup.ajaxUrl, {
				action: "mtuc_popup_calculate",
				security: mtucPopup.nonce,
				product_id: lineContext.productId,
				variation_id: lineContext.variationId,
				line_price: lineContext.linePrice.toFixed(2),
				offer_type: $offerType.val(),
				scheme_key: schemeKey,
				scheme_type: scheme.schemeType,
				months: scheme.months,
				filter_id: scheme.filterId,
				parva: parva.toFixed(2),
			})
				.done((response) => {
					if (response && response.success && response.data) {
						applyCalculation(response.data);
						return;
					}

					window.alert(
						(response && response.data && response.data.message) ||
							mtucPopup.i18n.calcError,
					);
				})
				.fail((xhr) => {
					let message = mtucPopup.i18n.calcError;
					if (
						xhr.responseJSON &&
						xhr.responseJSON.data &&
						xhr.responseJSON.data.message
					) {
						message = xhr.responseJSON.data.message;
					}
					window.alert(message);
				});
		};

		const scheduleCalculate = () => {
			window.clearTimeout(calculateTimer);
			calculateTimer = window.setTimeout(calculateNow, 250);
		};

		$(document).on(
			"click",
			".mtuc-product-calculator__btn[data-mtuc-offer]",
			function (event) {
				event.preventDefault();
				lastOpenTrigger = this;
				openPopup($(this).data("mtuc-offer"));
			},
		);

		$popup.on("click", "[data-mtuc-popup-close]", closePopup);

		$months.on("change", function () {
			resetParvaInput();
			calculateNow();
		});

		$parva.on("change blur", function () {
			if ($(this).prop("readonly")) {
				return;
			}
			scheduleCalculate();
		});

		$("#mtuc-popup-back").on("click", function () {
			showStep(1);
		});

		$("#mtuc-popup-buy").on("click", function () {
			if (!lastCalculation) {
				return;
			}
			showStep(2);
		});

		$("#mtuc-popup-add-to-cart").on("click", function () {
			const $addToCart = $(
				'button[type="submit"].single_add_to_cart_button',
			).eq(0);
			if ($addToCart.length && !$addToCart.hasClass("disabled")) {
				$addToCart.trigger("click");
				closePopup();
				return;
			}

			window.alert(mtucPopup.i18n.addToCartError);
		});

		$("#mtuc-popup-submit").on("click", function () {
			window.alert(mtucPopup.i18n.submitPending);
		});

		$(document).on("keyup", function (event) {
			if (event.key === "Escape" && $popup.hasClass("is-open")) {
				closePopup();
			}
		});

		document.addEventListener(
			"mtuc-calculator-refreshed",
			function (event) {
				const data =
					event && event.detail && typeof event.detail === "object"
						? event.detail
						: null;

				if (!data || !data.visible) {
					if ($popup.hasClass("is-open")) {
						closePopup();
					}
					return;
				}

				if (data.enabledMonthsByOffer) {
					mtucPopup.enabledMonthsByOffer = data.enabledMonthsByOffer;
				}

				if (data.defaultSchemeByOffer) {
					mtucPopup.defaultSchemeByOffer = data.defaultSchemeByOffer;
				}

				if (data.product_id) {
					mtucPopup.productId = parseInt(data.product_id, 10);
					$("#mtuc-popup-product-id").val(mtucPopup.productId);
				}

				if (!$popup.hasClass("is-open")) {
					return;
				}

				resetParvaInput();
				lastCalculation = null;
				rebuildMonthsSelect($offerType.val());

				if ($months.prop("disabled")) {
					closePopup();
					return;
				}

				calculateNow();
			},
		);
	});
})(jQuery);
