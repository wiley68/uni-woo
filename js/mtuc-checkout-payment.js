(function ($) {
	"use strict";

	const PARVA_CALCULATE_DELAY = 900;
	const NS = ".mtucCheckout";

	let calculateTimer = null;
	let lastCalculation = null;
	let activeConfig = null;

	const getConfig = () => activeConfig || window.mtucCheckout || null;

	const formatPercent = (value) => {
		const num = Math.abs(parseFloat(value) || 0);
		return num.toFixed(2);
	};

	const formatMonthLabel = (months, desc, config) => {
		let label = (config.i18n.monthsLabel || "%d месеца").replace(
			"%d",
			String(months),
		);
		if (desc) {
			label += " - " + desc;
		}
		return label + "\u00A0\u00A0\u00A0";
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

	const getEnabledSchemes = (config) => {
		if (
			Array.isArray(config.enabledSchemes) &&
			config.enabledSchemes.length
		) {
			return config.enabledSchemes;
		}

		const offerType = config.offerType || "standard";
		if (
			config.enabledMonthsByOffer &&
			Array.isArray(config.enabledMonthsByOffer[offerType])
		) {
			return config.enabledMonthsByOffer[offerType];
		}

		return [];
	};

	const getDefaultSchemeKey = (config) => {
		if (config.defaultSchemeKey) {
			return String(config.defaultSchemeKey);
		}

		const offerType = config.offerType || "standard";
		if (
			config.defaultSchemeByOffer &&
			config.defaultSchemeByOffer[offerType]
		) {
			return String(config.defaultSchemeByOffer[offerType]);
		}

		return "";
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

	const setDualAmount = (prefix, display) => {
		const data = display || {};
		$("#mtuc-checkout-" + prefix + "-primary").text(data.primary || "");
		$("#mtuc-checkout-" + prefix + "-secondary").text(data.secondary || "");
	};

	const syncHiddenFields = ($schemeKey, $parva, $parvaHidden, $months) => {
		const schemeKey = String($months.val() || "");
		const parva = parseFloat($parva.val()) || 0;
		$schemeKey.val(schemeKey);
		$parvaHidden.val(parva.toFixed(2));
	};

	const applyCalculation = (data, $parva, $parvaRow, syncFn) => {
		lastCalculation = data;
		setDualAmount("price", data.price_display);
		setDualAmount("loan", data.loan_display);
		setDualAmount("monthly", data.monthly_display);
		setDualAmount("total", data.total_display);
		$("#mtuc-checkout-glp").text(
			(data.glp_display || formatPercent(data.glp)) + "%",
		);
		$("#mtuc-checkout-gpr").text(
			(data.gpr_display || formatPercent(data.gpr)) + "%",
		);

		if (data.show_parva) {
			$parvaRow.removeClass("mtuc-popup__row--hidden");
		} else {
			$parvaRow.addClass("mtuc-popup__row--hidden");
		}

		$parva.val(data.parva);
		$parva.prop("readonly", !!data.parva_locked);
		syncFn();
	};

	const rebuildMonthsSelect = (
		$months,
		$schemeKey,
		$parva,
		$parvaHidden,
		config,
	) => {
		const enabled = getEnabledSchemes(config);
		const preferred = getDefaultSchemeKey(config);

		$months.empty();

		if (!enabled.length) {
			$months.append(
				$("<option>", {
					value: "",
					text: config.i18n.noMonths || "Няма налични срокове",
				}),
			);
			$months.prop("disabled", true);
			syncHiddenFields($schemeKey, $parva, $parvaHidden, $months);
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
					text: formatMonthLabel(months, desc, config),
				}),
			);
		});

		$months.prop("disabled", false);
		if (schemeKeys.indexOf(String(preferred)) !== -1) {
			$months.val(String(preferred));
		} else if (schemeKeys.length) {
			$months.val(schemeKeys[0]);
		}

		syncHiddenFields($schemeKey, $parva, $parvaHidden, $months);
		return String($months.val() || "");
	};

	const bindCheckoutPayment = (options) => {
		const config = options.config || getConfig();
		const mode = options.mode || "classic";
		const $scope = options.$scope || $(document);

		if (!config) {
			return null;
		}

		activeConfig = config;

		const $root = $scope.find("#mtuc-checkout-payment");
		if (!$root.length) {
			return null;
		}

		const $offerType = $scope.find("#mtuc-checkout-offer-type");
		const $schemeKey = $scope.find("#mtuc-checkout-scheme-key");
		const $parvaHidden = $scope.find("#mtuc-checkout-parva-hidden");
		const $months = $scope.find("#mtuc-checkout-months");
		const $parva = $scope.find("#mtuc-checkout-parva");
		const $parvaRow = $scope.find("#mtuc-checkout-parva-row");

		const syncFn = () =>
			syncHiddenFields($schemeKey, $parva, $parvaHidden, $months);

		const calculateNow = () => {
			const schemeKey = String($months.val() || "");
			const scheme = parseSchemeKey(schemeKey);
			if (!scheme.months) {
				lastCalculation = null;
				syncFn();
				return;
			}

			const parva = parseFloat($parva.val()) || 0;
			syncFn();

			$.post(config.ajaxUrl, {
				action: "mtuc_popup_calculate",
				security: config.nonce,
				source: config.source || "checkout",
				offer_type: config.offerType || $offerType.val() || "standard",
				scheme_key: schemeKey,
				scheme_type: scheme.schemeType,
				months: scheme.months,
				filter_id: scheme.filterId,
				parva: parva.toFixed(2),
			})
				.done((response) => {
					if (response && response.success && response.data) {
						applyCalculation(
							response.data,
							$parva,
							$parvaRow,
							syncFn,
						);
						return;
					}
					lastCalculation = null;
					window.alert(
						(response && response.data && response.data.message) ||
							config.i18n.calcError,
					);
				})
				.fail((xhr) => {
					lastCalculation = null;
					let message = config.i18n.calcError;
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
			calculateTimer = window.setTimeout(
				calculateNow,
				PARVA_CALCULATE_DELAY,
			);
		};

		const refreshSchemes = () => {
			rebuildMonthsSelect(
				$months,
				$schemeKey,
				$parva,
				$parvaHidden,
				config,
			);
			if ($months.prop("disabled")) {
				lastCalculation = null;
				return;
			}
			calculateNow();
		};

		$months.off(NS).on("change" + NS, calculateNow);
		$parva.off(NS).on("input" + NS + " change" + NS, scheduleCalculate);

		if (mode === "classic") {
			$("form.checkout")
				.off("checkout_place_order_mtunicredit" + NS)
				.on("checkout_place_order_mtunicredit" + NS, function () {
					syncFn();
					if (!String($schemeKey.val() || "")) {
						window.alert(
							config.i18n.schemeRequired ||
								"Моля, изберете схема за погасяване.",
						);
						return false;
					}
					if (!lastCalculation) {
						window.alert(config.i18n.calcError);
						return false;
					}
					return true;
				});

			$(document.body)
				.off("updated_checkout" + NS)
				.on("updated_checkout" + NS, function () {
					if (!$("#mtuc-checkout-payment").length) {
						return;
					}
					refreshSchemes();
				});
		}

		refreshSchemes();

		return {
			validate: () => {
				syncFn();
				if (!String($schemeKey.val() || "")) {
					return {
						valid: false,
						message:
							config.i18n.schemeRequired ||
							"Моля, изберете схема за погасяване.",
					};
				}
				if (!lastCalculation) {
					return {
						valid: false,
						message: config.i18n.calcError,
					};
				}
				return {
					valid: true,
					paymentMethodData: {
						mtuc_scheme_key: String($schemeKey.val() || ""),
						mtuc_offer_type: "standard",
						mtuc_parva: String($parvaHidden.val() || "0"),
					},
				};
			},
			destroy: () => {
				$months.off(NS);
				$parva.off(NS);
				$("form.checkout").off("checkout_place_order_mtunicredit" + NS);
				$(document.body).off("updated_checkout" + NS);
			},
		};
	};

	window.mtucInitCheckoutPayment = function (container, config) {
		lastCalculation = null;
		const $scope = container ? $(container) : $(document);
		return bindCheckoutPayment({
			$scope: $scope,
			config: config || getConfig(),
			mode: config && config.blocks ? "blocks" : "classic",
		});
	};

	window.mtucGetCheckoutPaymentValidation = function () {
		if (
			window.mtucCheckoutBlocksController &&
			typeof window.mtucCheckoutBlocksController.validate === "function"
		) {
			return window.mtucCheckoutBlocksController.validate();
		}
		return { valid: true, paymentMethodData: {} };
	};

	$(function () {
		if (typeof window.mtucCheckout === "undefined") {
			return;
		}
		if (window.mtucCheckout.blocks) {
			return;
		}
		window.mtucInitCheckoutPayment(null, window.mtucCheckout);
	});
})(jQuery);
