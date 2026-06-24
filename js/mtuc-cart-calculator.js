(function ($) {
	"use strict";

	window.mtucGetProductLineContext = function () {
		const cartTotal =
			typeof mtucCartCalculator !== "undefined" &&
			typeof mtucCartCalculator.cartTotal === "number"
				? mtucCartCalculator.cartTotal
				: typeof mtucPopup !== "undefined" &&
					  typeof mtucPopup.cartTotal === "number"
					? mtucPopup.cartTotal
					: 0;

		return {
			source: "cart",
			linePrice: cartTotal,
			productId: 0,
			variationId: 0,
			quantity: 1,
		};
	};
})(jQuery);
