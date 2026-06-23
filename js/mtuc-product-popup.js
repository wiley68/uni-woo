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
		const $submitBtn = $("#mtuc-popup-submit");
		const $firstName = $("#mtuc-popup-first-name");
		const $lastName = $("#mtuc-popup-last-name");
		const $address = $("#mtuc-popup-address");
		const $phone = $("#mtuc-popup-phone");
		const $email = $("#mtuc-popup-email");
		const $firstNameError = $("#mtuc-popup-first-name-error");
		const $lastNameError = $("#mtuc-popup-last-name-error");
		const $addressError = $("#mtuc-popup-address-error");
		const $phoneError = $("#mtuc-popup-phone-error");
		const $emailError = $("#mtuc-popup-email-error");
		let calculateTimer = null;
		const PARVA_CALCULATE_DELAY = 900;
		let lastCalculation = null;
		let lastOpenTrigger = null;
		let submitInFlight = false;
		let redirectPending = false;
		const $processing = $popup.find(".mtuc-popup__processing");
		const $processingText = $popup.find(".mtuc-popup__processing-text");

		const resetParvaInput = () => {
			$parva.val("0").prop("readonly", false);
		};

		const formatPercent = (value) => {
			const num = Math.abs(parseFloat(value) || 0);
			return num.toFixed(2);
		};

		const setPopupProcessingState = (isProcessing) => {
			$popup.toggleClass("is-processing", isProcessing);
			$popup.attr("aria-busy", isProcessing ? "true" : "false");

			if (isProcessing) {
				$processing.removeAttr("hidden");
				$processingText.text(
					mtucPopup.i18n.processing ||
						"Обработване на заявката. Моля, изчакайте...",
				);
				return;
			}

			$processing.attr("hidden", "hidden");
			$processingText.text("");
		};

		const releaseSubmitUi = () => {
			submitInFlight = false;
			redirectPending = false;
			setPopupProcessingState(false);
			setSubmittingState(false);
		};

		const setSubmittingState = (isSubmitting) => {
			$submitBtn
				.toggleClass("is-submitting", isSubmitting)
				.attr("aria-busy", isSubmitting ? "true" : "false");

			if (isSubmitting) {
				$submitBtn.data(
					"mtuc-submit-label",
					$submitBtn.find(".mtuc-popup__btn-label").text(),
				);
				$submitBtn
					.find(".mtuc-popup__btn-label")
					.text(mtucPopup.i18n.submitting || "Изпращане...");
				return;
			}

			const previousLabel = $submitBtn.data("mtuc-submit-label");
			if (previousLabel) {
				$submitBtn.find(".mtuc-popup__btn-label").text(previousLabel);
			}
		};

		const getSubmitPayload = () => {
			const schemeKey = String($months.val() || "");
			const scheme = parseSchemeKey(schemeKey);
			const lineContext = getProductLineContext();
			const parva = parseFloat($parva.val()) || 0;

			return {
				action: "mtuc_popup_submit",
				security: mtucPopup.nonce,
				product_id: lineContext.productId,
				variation_id: lineContext.variationId,
				quantity: lineContext.quantity || 1,
				line_price: lineContext.linePrice.toFixed(2),
				offer_type: $offerType.val(),
				scheme_key: schemeKey,
				scheme_type: scheme.schemeType,
				months: scheme.months,
				filter_id: scheme.filterId,
				parva: parva.toFixed(2),
				first_name: String($firstName.val() || "").trim(),
				last_name: String($lastName.val() || "").trim(),
				address: String($address.val() || "").trim(),
				phone: String($phone.val() || "").trim(),
				email: String($email.val() || "").trim(),
			};
		};

		const submitPopupOrder = () => {
			if (submitInFlight) {
				return;
			}
			if (!lastCalculation) {
				window.alert(
					mtucPopup.i18n.submitNoCalc ||
						"Липсват данни за изчисление.",
				);
				return;
			}

			submitInFlight = true;
			redirectPending = false;
			setPopupProcessingState(true);
			setSubmittingState(true);

			$.post(mtucPopup.ajaxUrl, getSubmitPayload())
				.done((response) => {
					if (
						response &&
						response.data &&
						response.data.redirect_url
					) {
						redirectPending = true;
						window.location.assign(response.data.redirect_url);
						return;
					}

					if (response && response.success && response.data) {
						window.alert(
							response.data.message ||
								mtucPopup.i18n.submitPending,
						);
						releaseSubmitUi();
						closePopup();
						return;
					}

					window.alert(
						(response && response.data && response.data.message) ||
							mtucPopup.i18n.submitError,
					);
					releaseSubmitUi();
				})
				.fail((xhr) => {
					if (
						xhr.responseJSON &&
						xhr.responseJSON.data &&
						xhr.responseJSON.data.redirect_url
					) {
						redirectPending = true;
						window.location.assign(
							xhr.responseJSON.data.redirect_url,
						);
						return;
					}

					let message = mtucPopup.i18n.submitError;
					if (
						xhr.responseJSON &&
						xhr.responseJSON.data &&
						xhr.responseJSON.data.message
					) {
						message = xhr.responseJSON.data.message;
					}
					window.alert(message);
					releaseSubmitUi();
				});
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
				clearStep2FieldErrors();
				updateSubmitState();
				return;
			}

			$step2.prop("hidden", true).removeClass("mtuc-popup__step--active");
			$step1.prop("hidden", false).addClass("mtuc-popup__step--active");
			clearStep2FieldErrors();
		};

		const PHONE_ALLOWED_PATTERN = /[-0-9+() ]/;
		const PHONE_VALID_PATTERN = /^[-0-9+() ]+$/;
		const EMAIL_VALID_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

		const sanitizePhoneValue = (value) => {
			return String(value || "")
				.split("")
				.filter((char) => PHONE_ALLOWED_PATTERN.test(char))
				.join("");
		};

		const isNonEmpty = (value) => {
			return String(value || "").trim() !== "";
		};

		const isValidPhone = (value) => {
			const phone = String(value || "").trim();
			return (
				phone !== "" &&
				PHONE_VALID_PATTERN.test(phone) &&
				/\d/.test(phone)
			);
		};

		const isValidEmail = (value) => {
			const email = String(value || "").trim();
			return email !== "" && EMAIL_VALID_PATTERN.test(email);
		};

		const isStep2FormValid = () => {
			return (
				isNonEmpty($firstName.val()) &&
				isNonEmpty($lastName.val()) &&
				isNonEmpty($address.val()) &&
				isValidPhone($phone.val()) &&
				isValidEmail($email.val())
			);
		};

		const getRequiredFieldError = () => {
			return mtucPopup.i18n.fieldRequired || "Полето е задължително.";
		};

		const getPhoneFieldError = (value) => {
			const phone = String(value || "").trim();
			if (phone === "") {
				return getRequiredFieldError();
			}
			if (!isValidPhone(phone)) {
				return (
					mtucPopup.i18n.phoneInvalid ||
					"Въведете валиден телефонен номер."
				);
			}
			return "";
		};

		const getEmailFieldError = (value) => {
			const email = String(value || "").trim();
			if (email === "") {
				return getRequiredFieldError();
			}
			if (!isValidEmail(email)) {
				return (
					mtucPopup.i18n.emailInvalid ||
					"Въведете валиден e-mail адрес."
				);
			}
			return "";
		};

		const getStep2FieldErrors = () => {
			return {
				firstName: isNonEmpty($firstName.val())
					? ""
					: getRequiredFieldError(),
				lastName: isNonEmpty($lastName.val())
					? ""
					: getRequiredFieldError(),
				address: isNonEmpty($address.val())
					? ""
					: getRequiredFieldError(),
				phone: getPhoneFieldError($phone.val()),
				email: getEmailFieldError($email.val()),
			};
		};

		const setStep2FieldErrors = (errors) => {
			$firstNameError.text(errors.firstName || "");
			$lastNameError.text(errors.lastName || "");
			$addressError.text(errors.address || "");
			$phoneError.text(errors.phone || "");
			$emailError.text(errors.email || "");
		};

		const clearStep2FieldErrors = () => {
			setStep2FieldErrors({
				firstName: "",
				lastName: "",
				address: "",
				phone: "",
				email: "",
			});
		};

		const getStep2CustomerDefaults = () => {
			const customer =
				mtucPopup.customer && typeof mtucPopup.customer === "object"
					? mtucPopup.customer
					: {};

			return {
				firstName: String(customer.first_name || ""),
				lastName: String(customer.last_name || ""),
				address: String(customer.address || ""),
				phone: String(customer.phone || ""),
				email: String(customer.email || ""),
			};
		};

		const resetStep2Form = () => {
			const defaults = getStep2CustomerDefaults();

			$firstName.val(defaults.firstName);
			$lastName.val(defaults.lastName);
			$address.val(defaults.address);
			$phone.val(defaults.phone);
			$email.val(defaults.email);
			clearStep2FieldErrors();
			updateSubmitState();
		};

		const prefillStep2CustomerFields = () => {
			const defaults = getStep2CustomerDefaults();

			if (!isNonEmpty($address.val()) && defaults.address) {
				$address.val(defaults.address);
			}
			if (!isNonEmpty($phone.val()) && defaults.phone) {
				$phone.val(defaults.phone);
			}
		};

		const validateStep2Form = (showErrors) => {
			const errors = getStep2FieldErrors();
			const isValid = !Object.values(errors).some(
				(message) => message !== "",
			);

			if (showErrors) {
				setStep2FieldErrors(errors);
			}

			return isValid;
		};

		const updateSubmitState = () => {
			const isValid = isStep2FormValid();
			$submitBtn
				.toggleClass("is-disabled", !isValid)
				.attr("aria-disabled", isValid ? "false" : "true");
		};

		const onStep2FieldInput = function () {
			const errors = getStep2FieldErrors();
			const fieldId = this.id;

			if (fieldId === "mtuc-popup-first-name") {
				$firstNameError.text(errors.firstName);
			} else if (fieldId === "mtuc-popup-last-name") {
				$lastNameError.text(errors.lastName);
			} else if (fieldId === "mtuc-popup-address") {
				$addressError.text(errors.address);
			} else if (fieldId === "mtuc-popup-phone") {
				$phoneError.text(errors.phone);
			} else if (fieldId === "mtuc-popup-email") {
				$emailError.text(errors.email);
			}

			updateSubmitState();
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
			releaseSubmitUi();
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
			if (submitInFlight || redirectPending) {
				return;
			}

			window.clearTimeout(calculateTimer);
			calculateTimer = null;
			releasePopupFocus();
			$popup
				.removeClass("is-open")
				.attr("aria-hidden", "true")
				.attr("hidden", "hidden");
			document.body.classList.remove("mtuc-popup-open");
			resetStep2Form();
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

			$("#mtuc-popup-glp").text(
				(data.glp_display || formatPercent(data.glp)) + "%",
			);
			$("#mtuc-popup-gpr").text(
				(data.gpr_display || formatPercent(data.gpr)) + "%",
			);

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
				quantity: 1,
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
			calculateTimer = window.setTimeout(
				calculateNow,
				PARVA_CALCULATE_DELAY,
			);
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

		$parva.on("input change", function () {
			if ($(this).prop("readonly")) {
				return;
			}
			scheduleCalculate();
		});

		$("#mtuc-popup-back").on("click", function () {
			if (submitInFlight || redirectPending) {
				return;
			}
			showStep(1);
		});

		$("#mtuc-popup-buy").on("click", function () {
			if (!lastCalculation) {
				return;
			}
			prefillStep2CustomerFields();
			showStep(2);
			updateSubmitState();
		});

		$firstName
			.add($lastName)
			.add($address)
			.add($email)
			.on("input change", onStep2FieldInput);
		$phone.on("input", function () {
			const sanitized = sanitizePhoneValue($(this).val());
			if ($(this).val() !== sanitized) {
				$(this).val(sanitized);
			}
			onStep2FieldInput.call(this);
		});
		$phone.on("change blur", onStep2FieldInput);

		updateSubmitState();

		$("#mtuc-popup-submit").on("click", function () {
			if (submitInFlight) {
				return;
			}
			if (!validateStep2Form(true)) {
				return;
			}
			submitPopupOrder();
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
