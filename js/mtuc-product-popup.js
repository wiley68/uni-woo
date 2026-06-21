(function ($) {
	"use strict";

	const popup = document.getElementById("mtuc-product-popup");
	if (!popup || typeof mtucPopup === "undefined") {
		return;
	}

	const movePopupToBody = () => {
		if (popup.parentNode !== document.body) {
			document.body.insertBefore(popup, document.body.firstChild);
		}
	};
	movePopupToBody();

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
			$step1.prop("hidden", true).removeClass("mtuc-popup__step--active");
			$step2.prop("hidden", false).addClass("mtuc-popup__step--active");
			return;
		}

		$step2.prop("hidden", true).removeClass("mtuc-popup__step--active");
		$step1.prop("hidden", false).addClass("mtuc-popup__step--active");
	};

	const openPopup = (offerType) => {
		$offerType.val(offerType);
		showStep(1);
		$popup
			.removeAttr("hidden")
			.addClass("is-open")
			.attr("aria-hidden", "false");
		document.body.classList.add("mtuc-popup-open");
		calculateNow();
	};

	const closePopup = () => {
		$popup
			.removeClass("is-open")
			.attr("aria-hidden", "true")
			.attr("hidden", "hidden");
		document.body.classList.remove("mtuc-popup-open");
		showStep(1);
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

	const calculateNow = () => {
		const months = parseInt($months.val(), 10) || mtucPopup.defaultMonths;
		const parva = parseFloat($parva.val()) || 0;

		$buyBtn.prop("disabled", true).addClass("is-disabled");

		$.post(mtucPopup.ajaxUrl, {
			action: "mtuc_popup_calculate",
			security: mtucPopup.nonce,
			offer_type: $offerType.val(),
			months: months,
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
			.fail(() => {
				window.alert(mtucPopup.i18n.calcError);
			});
	};

	const scheduleCalculate = () => {
		window.clearTimeout(calculateTimer);
		calculateTimer = window.setTimeout(calculateNow, 250);
	};

	$(document).on(
		"click",
		".mtuc-product-calculator__btn[data-mtuc-offer]",
		function () {
			openPopup($(this).data("mtuc-offer"));
		},
	);

	$popup.on("click", "[data-mtuc-popup-close]", closePopup);

	$months.on("change", calculateNow);

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
		// Placeholder — submit flow will be implemented later.
		window.alert(mtucPopup.i18n.submitPending);
	});

	$(document).on("keyup", function (event) {
		if (event.key === "Escape" && $popup.hasClass("is-open")) {
			closePopup();
		}
	});
})(jQuery);
