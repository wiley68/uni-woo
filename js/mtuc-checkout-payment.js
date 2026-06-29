(function ($) {
	"use strict";

	const PARVA_CALCULATE_DELAY = 900;
	const NS = ".mtucCheckout";
	const GATEWAY_ID = "mtunicredit";
	const PLACE_ORDER_LOCK = "mtucPlaceOrderLock";

	let calculateTimer = null;
	let lastCalculation = null;
	let activeConfig = null;
	let activeController = null;

	const getConfig = () => activeConfig || window.mtucCheckout || null;

	const normalizeSchemeList = (value) => {
		if (Array.isArray(value)) {
			return value;
		}
		if (value && typeof value === "object") {
			return Object.values(value);
		}
		return [];
	};

	const readConfigFromRoot = ($root) => {
		const raw = $root.attr("data-mtuc-config");
		if (!raw) {
			return {};
		}

		try {
			const parsed = JSON.parse(raw);
			return parsed && typeof parsed === "object" ? parsed : {};
		} catch (error) {
			return {};
		}
	};

	const mergeCheckoutConfig = (baseConfig, $root) => {
		const rootConfig = readConfigFromRoot($root);
		return Object.assign({}, baseConfig || {}, rootConfig, {
			enabledSchemes: normalizeSchemeList(
				rootConfig.enabledSchemes ||
					(baseConfig && baseConfig.enabledSchemes),
			),
		});
	};

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
		return normalizeSchemeList(config && config.enabledSchemes);
	};

	const getDefaultSchemeKey = (config) => {
		if (config && config.defaultSchemeKey) {
			return String(config.defaultSchemeKey);
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

	const isOurPaymentSelected = () => {
		const $checked = $('input[name="payment_method"]:checked');
		return $checked.length && $checked.val() === GATEWAY_ID;
	};

	const getConsentCheckboxes = ($scope) => {
		return $scope.find(
			"#mtuc-checkout-payment .mtuc-popup__consent-checkbox",
		);
	};

	const areMandatoryConsentsChecked = ($scope) => {
		const $boxes = getConsentCheckboxes($scope);
		if (!$boxes.length) {
			return true;
		}

		let allChecked = true;
		$boxes.each(function () {
			if (!this.checked) {
				allChecked = false;
				return false;
			}
		});

		return allChecked;
	};

	const getCheckedConsentIds = ($scope) => {
		const ids = [];
		getConsentCheckboxes($scope)
			.filter(":checked")
			.each(function () {
				const value = String($(this).val() || "").trim();
				if (value) {
					ids.push(value);
				}
			});
		return ids;
	};

	const formatConsentsForPaymentData = ($scope) => {
		return getCheckedConsentIds($scope).join(",");
	};

	const PHONE_VALID_PATTERN = /^[-0-9+() ]+$/;

	const isValidCheckoutEgn = (value) => {
		const egn = String(value || "").replace(/\D/g, "");
		if (!/^\d{10}$/.test(egn)) {
			return false;
		}

		const year = parseInt(egn.slice(0, 4), 10);
		const month = parseInt(egn.slice(4, 6), 10);
		const day = parseInt(egn.slice(6, 8), 10);
		const date = new Date(year, month - 1, day);

		return (
			date.getFullYear() === year &&
			date.getMonth() === month - 1 &&
			date.getDate() === day
		);
	};

	const isValidCheckoutPhone = (value) => {
		const phone = String(value || "").trim();
		return (
			phone !== "" && PHONE_VALID_PATTERN.test(phone) && /\d/.test(phone)
		);
	};

	const releasePlaceOrderButton = () => {
		const $btn = $("form.checkout #place_order");
		if (!$btn.length || !$btn.data(PLACE_ORDER_LOCK)) {
			return;
		}

		$btn.prop("disabled", false)
			.removeClass("disabled")
			.removeData(PLACE_ORDER_LOCK);
	};

	const hidePlaceOrderConsentsTooltip = () => {
		$(".mtuc-place-order-tooltip").remove();
	};

	const unwrapPlaceOrderButton = () => {
		const $btn = $("form.checkout #place_order");
		if (!$btn.length) {
			return;
		}

		const $wrap = $btn.parent(".mtuc-place-order-wrap");
		if ($wrap.length) {
			$wrap.replaceWith($btn);
		}
	};

	const releasePlaceOrderConsentsTooltip = () => {
		hidePlaceOrderConsentsTooltip();
		$("form.checkout").off(
			"mousemove" + NS + "ConsentHint mouseleave" + NS + "ConsentHint",
		);
		unwrapPlaceOrderButton();
	};

	const isPointerOverPlaceOrder = (event) => {
		const button = document.getElementById("place_order");
		if (!button || typeof event.clientX !== "number") {
			return false;
		}

		const rect = button.getBoundingClientRect();
		return (
			event.clientX >= rect.left &&
			event.clientX <= rect.right &&
			event.clientY >= rect.top &&
			event.clientY <= rect.bottom
		);
	};

	const showPlaceOrderConsentsTooltip = (message) => {
		const button = document.getElementById("place_order");
		if (!button) {
			return;
		}

		let $tooltip = $("body > .mtuc-place-order-tooltip");
		if (!$tooltip.length) {
			$tooltip = $("<div>", {
				class: "mtuc-place-order-tooltip",
				role: "tooltip",
			}).appendTo(document.body);
		}

		$tooltip.text(message);
		$tooltip.css({ visibility: "hidden", display: "block" });

		const rect = button.getBoundingClientRect();
		const tooltipHeight = $tooltip.outerHeight() || 0;
		const viewportPadding = 8;
		let top = rect.top - tooltipHeight - 10;
		top = Math.max(viewportPadding, top);

		$tooltip.css({
			position: "fixed",
			top: top + "px",
			left: rect.left + rect.width / 2 + "px",
			transform: "translateX(-50%)",
			visibility: "visible",
		});
	};

	const bindPlaceOrderConsentsTooltipListeners = (message) => {
		const $form = $("form.checkout");
		if (!$form.length) {
			return;
		}

		$form.off(
			"mousemove" + NS + "ConsentHint mouseleave" + NS + "ConsentHint",
		);
		$form.on("mousemove" + NS + "ConsentHint", function (event) {
			if (isPointerOverPlaceOrder(event)) {
				showPlaceOrderConsentsTooltip(message);
				return;
			}

			hidePlaceOrderConsentsTooltip();
		});
		$form.on("mouseleave" + NS + "ConsentHint", function () {
			hidePlaceOrderConsentsTooltip();
		});
	};

	const applyCalculation = (
		data,
		$parva,
		$parvaRow,
		syncFn,
		onReadyChange,
	) => {
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

		if (data.show_parva || data.parva_locked) {
			$parvaRow.removeClass("mtuc-popup__row--hidden");
		} else {
			$parvaRow.addClass("mtuc-popup__row--hidden");
		}

		$parva.val(data.parva);
		$parva.prop("readonly", !!data.parva_locked);
		syncFn();
		if (typeof onReadyChange === "function") {
			onReadyChange();
		}
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
		const currentValue = String($months.val() || "");

		if (!enabled.length) {
			if (!$months.find("option").length) {
				$months.append(
					$("<option>", {
						value: "",
						text: config.i18n.noMonths || "Няма налични срокове",
					}),
				);
			}
			$months.prop("disabled", true);
			syncHiddenFields($schemeKey, $parva, $parvaHidden, $months);
			return "";
		}

		const hasServerOptions = $months.find("option").length > 0;
		if (!hasServerOptions) {
			$months.empty();
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

			if (schemeKeys.indexOf(currentValue) !== -1) {
				$months.val(currentValue);
			} else if (schemeKeys.indexOf(String(preferred)) !== -1) {
				$months.val(String(preferred));
			} else if (schemeKeys.length) {
				$months.val(schemeKeys[0]);
			}
		} else {
			$months.prop("disabled", false);
			if (currentValue) {
				$months.val(currentValue);
			} else if (preferred) {
				$months.val(preferred);
			}
		}

		syncHiddenFields($schemeKey, $parva, $parvaHidden, $months);
		return String($months.val() || "");
	};

	const bindCheckoutPayment = (options) => {
		const baseConfig = options.config || getConfig();
		const mode = options.mode || "classic";
		const $scope = options.$scope || $(document);

		if (!baseConfig) {
			return null;
		}

		const $root = $scope.find("#mtuc-checkout-payment");
		if (!$root.length) {
			return null;
		}

		if (
			activeController &&
			typeof activeController.destroy === "function"
		) {
			activeController.destroy();
			activeController = null;
		}

		const config = mergeCheckoutConfig(baseConfig, $root);
		activeConfig = config;

		const $offerType = $scope.find("#mtuc-checkout-offer-type");
		const $schemeKey = $scope.find("#mtuc-checkout-scheme-key");
		const $parvaHidden = $scope.find("#mtuc-checkout-parva-hidden");
		const $months = $scope.find("#mtuc-checkout-months");
		const $parva = $scope.find("#mtuc-checkout-parva");
		const $parvaRow = $scope.find("#mtuc-checkout-parva-row");
		const $consentCheckboxes = getConsentCheckboxes($scope);
		const $egn = $scope.find("#mtuc-checkout-egn");
		const $phone2 = $scope.find("#mtuc-checkout-phone2");
		const isProcess2 = () => !!(config && config.process2);

		const getProcess2ValidationMessage = () => {
			if (!isProcess2()) {
				return "";
			}

			const egn = String($egn.val() || "").replace(/\D/g, "");
			if (egn === "") {
				return (
					config.i18n.egnRequired ||
					config.i18n.fieldRequired ||
					"Полето „ЕГН“ е задължително."
				);
			}
			if (!isValidCheckoutEgn(egn)) {
				return (
					config.i18n.egnInvalid ||
					"Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD)."
				);
			}

			const phone2 = String($phone2.val() || "").trim();
			if (phone2 === "") {
				return config.i18n.fieldRequired || "Полето е задължително.";
			}
			if (!isValidCheckoutPhone(phone2)) {
				return (
					config.i18n.phoneInvalid ||
					"Въведете валиден втори телефонен номер."
				);
			}

			return "";
		};

		const getProcess2PaymentFields = () => {
			if (!isProcess2()) {
				return {};
			}

			return {
				mtuc_egn: String($egn.val() || "").replace(/\D/g, ""),
				mtuc_phone2: String($phone2.val() || "").trim(),
			};
		};

		const syncFn = () =>
			syncHiddenFields($schemeKey, $parva, $parvaHidden, $months);

		const isCheckoutPaymentReady = () => {
			if (!String($schemeKey.val() || "")) {
				return false;
			}
			if (!lastCalculation) {
				return false;
			}
			if (getProcess2ValidationMessage()) {
				return false;
			}
			return areMandatoryConsentsChecked($scope);
		};

		const isBlockedByConsentsOnly = () => {
			return (
				isOurPaymentSelected() &&
				!!String($schemeKey.val() || "") &&
				!!lastCalculation &&
				!areMandatoryConsentsChecked($scope)
			);
		};

		const syncPlaceOrderConsentsTooltip = () => {
			releasePlaceOrderConsentsTooltip();

			if (mode !== "classic" || !isBlockedByConsentsOnly()) {
				return;
			}

			const message =
				config.i18n.consentsTooltip ||
				config.i18n.consentsRequired ||
				"Моля, първо приемете общите условия, за да продължите с поръчката.";

			bindPlaceOrderConsentsTooltipListeners(message);
		};

		const updatePlaceOrderButtonState = () => {
			const $btn = $("form.checkout #place_order");
			if (!$btn.length) {
				releasePlaceOrderConsentsTooltip();
				return;
			}

			if (!isOurPaymentSelected()) {
				releasePlaceOrderButton();
				releasePlaceOrderConsentsTooltip();
				return;
			}

			if (isCheckoutPaymentReady()) {
				$btn.prop("disabled", false)
					.removeClass("disabled")
					.removeData(PLACE_ORDER_LOCK);
				releasePlaceOrderConsentsTooltip();
				return;
			}

			$btn.prop("disabled", true)
				.addClass("disabled")
				.data(PLACE_ORDER_LOCK, 1);
			syncPlaceOrderConsentsTooltip();
		};

		const onCheckoutReadyChange = () => {
			if (mode === "classic") {
				updatePlaceOrderButtonState();
			}
		};

		const calculateNow = () => {
			const schemeKey = String($months.val() || "");
			const scheme = parseSchemeKey(schemeKey);
			if (!scheme.months) {
				lastCalculation = null;
				syncFn();
				onCheckoutReadyChange();
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
							onCheckoutReadyChange,
						);
						return;
					}
					lastCalculation = null;
					onCheckoutReadyChange();
					window.alert(
						(response && response.data && response.data.message) ||
							config.i18n.calcError,
					);
				})
				.fail((xhr) => {
					lastCalculation = null;
					onCheckoutReadyChange();
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
			syncFn();
			if ($months.prop("disabled")) {
				lastCalculation = null;
				onCheckoutReadyChange();
				return;
			}
			calculateNow();
		};

		$months.off(NS).on("change" + NS, calculateNow);
		$parva.off(NS).on("input" + NS + " change" + NS, scheduleCalculate);
		$consentCheckboxes.off(NS).on("change" + NS, onCheckoutReadyChange);
		$egn.add($phone2)
			.off(NS)
			.on("input" + NS + " change" + NS, onCheckoutReadyChange);
		$scope.off("mousedown" + NS, ".mtuc-popup__consent-label a");
		$scope.on(
			"mousedown" + NS,
			".mtuc-popup__consent-label a",
			function (event) {
				event.stopPropagation();
			},
		);

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
					if (!areMandatoryConsentsChecked($scope)) {
						window.alert(
							config.i18n.consentsRequired ||
								"Моля, приемете всички задължителни съгласия.",
						);
						return false;
					}
					const process2Message = getProcess2ValidationMessage();
					if (process2Message) {
						window.alert(process2Message);
						return false;
					}
					return true;
				});
		}

		refreshSchemes();

		const buildValidationResult = () => {
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
			if (!areMandatoryConsentsChecked($scope)) {
				return {
					valid: false,
					message:
						config.i18n.consentsRequired ||
						"Моля, приемете всички задължителни съгласия.",
				};
			}
			const process2Message = getProcess2ValidationMessage();
			if (process2Message) {
				return {
					valid: false,
					message: process2Message,
				};
			}
			return {
				valid: true,
				paymentMethodData: Object.assign(
					{
						mtuc_scheme_key: String($schemeKey.val() || ""),
						mtuc_offer_type: "standard",
						mtuc_parva: String($parvaHidden.val() || "0"),
						mtuc_consent: formatConsentsForPaymentData($scope),
					},
					getProcess2PaymentFields(),
				),
			};
		};

		const controller = {
			validate: () => buildValidationResult(),
			destroy: () => {
				$months.off(NS);
				$parva.off(NS);
				$egn.add($phone2).off(NS);
				$consentCheckboxes.off(NS);
				$scope.off("mousedown" + NS, ".mtuc-popup__consent-label a");
				$("form.checkout").off("checkout_place_order_mtunicredit" + NS);
				releasePlaceOrderButton();
				releasePlaceOrderConsentsTooltip();
			},
		};

		activeController = controller;
		window.mtucCheckoutBlocksController = controller;

		return controller;
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
			activeController &&
			typeof activeController.validate === "function"
		) {
			return activeController.validate();
		}
		return { valid: true, paymentMethodData: {} };
	};

	const bootClassicCheckoutPayment = () => {
		if (
			typeof window.mtucCheckout === "undefined" ||
			window.mtucCheckout.blocks
		) {
			return;
		}
		window.mtucInitCheckoutPayment(null, window.mtucCheckout);
	};

	$(function () {
		bootClassicCheckoutPayment();

		$(document.body).on("updated_checkout" + NS, function () {
			if (!$("#mtuc-checkout-payment").length) {
				return;
			}
			bootClassicCheckoutPayment();
		});

		$(document.body).on("payment_method_selected" + NS, function () {
			window.setTimeout(function () {
				bootClassicCheckoutPayment();
				if (!isOurPaymentSelected()) {
					releasePlaceOrderButton();
					releasePlaceOrderConsentsTooltip();
				}
			}, 0);
		});
	});
})(jQuery);
