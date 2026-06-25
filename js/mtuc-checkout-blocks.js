(function () {
	"use strict";

	const settings = window.wc.wcSettings.getSetting("mtunicredit_data", {});
	const checkoutConfig = Object.assign(
		{ blocks: true },
		settings.checkout || {},
	);
	const label =
		window.wp.htmlEntities.decodeEntities(settings.title || "") ||
		"УниКредит покупки на Кредит";
	const description = window.wp.htmlEntities.decodeEntities(
		settings.description || "",
	);

	const createElement = window.wp.element.createElement;
	const useEffect = window.wp.element.useEffect;
	const useRef = window.wp.element.useRef;

	let refreshTimer = null;
	let cartSignature = "";

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

		return cart.items
			.map((item) => {
				return (
					String(item.key || "") + ":" + String(item.quantity || 0)
				);
			})
			.join("|");
	};

	const PaymentLabel = (props) => {
		const { PaymentMethodLabel } = props.components;
		return createElement(PaymentMethodLabel, { text: label });
	};

	const PaymentContent = (props) => {
		const { eventRegistration, emitResponse } = props;
		const { onPaymentSetup } = eventRegistration;
		const fieldsHostRef = useRef(null);
		const controllerRef = useRef(null);
		const fieldsHtmlRef = useRef(settings.fieldsHtml || "");

		const mountFields = () => {
			if (!fieldsHostRef.current) {
				return;
			}

			fieldsHostRef.current.innerHTML = fieldsHtmlRef.current || "";

			if (controllerRef.current && controllerRef.current.destroy) {
				controllerRef.current.destroy();
			}

			if (typeof window.mtucInitCheckoutPayment !== "function") {
				return;
			}

			controllerRef.current = window.mtucInitCheckoutPayment(
				fieldsHostRef.current,
				checkoutConfig,
			);
			window.mtucCheckoutBlocksController = controllerRef.current;
		};

		const refreshFields = () => {
			if (!checkoutConfig.ajaxUrl || !checkoutConfig.nonce) {
				return;
			}

			const params = new URLSearchParams();
			params.append("action", "mtuc_checkout_blocks_refresh");
			params.append("security", checkoutConfig.nonce);

			window
				.fetch(checkoutConfig.ajaxUrl, {
					method: "POST",
					credentials: "same-origin",
					headers: {
						"Content-Type":
							"application/x-www-form-urlencoded; charset=UTF-8",
					},
					body: params.toString(),
				})
				.then((response) => response.json())
				.then((response) => {
					if (!response || !response.success || !response.data) {
						return;
					}

					if (response.data.fieldsHtml) {
						fieldsHtmlRef.current = response.data.fieldsHtml;
					}

					if (response.data.checkout) {
						Object.assign(checkoutConfig, response.data.checkout, {
							blocks: true,
						});
					}

					mountFields();
				})
				.catch(() => {});
		};

		useEffect(() => {
			mountFields();

			return () => {
				if (controllerRef.current && controllerRef.current.destroy) {
					controllerRef.current.destroy();
				}
				if (
					window.mtucCheckoutBlocksController ===
					controllerRef.current
				) {
					window.mtucCheckoutBlocksController = null;
				}
			};
		}, []);

		useEffect(() => {
			if (!window.wp || !window.wp.data) {
				return undefined;
			}

			cartSignature = getCartSignature();

			const unsubscribe = window.wp.data.subscribe(() => {
				const nextSignature = getCartSignature();
				if (nextSignature === cartSignature) {
					return;
				}
				cartSignature = nextSignature;
				window.clearTimeout(refreshTimer);
				refreshTimer = window.setTimeout(refreshFields, 400);
			});

			return () => {
				unsubscribe();
				window.clearTimeout(refreshTimer);
			};
		}, []);

		useEffect(() => {
			if (!onPaymentSetup || !emitResponse) {
				return undefined;
			}

			const unsubscribe = onPaymentSetup(() => {
				const validation =
					controllerRef.current &&
					typeof controllerRef.current.validate === "function"
						? controllerRef.current.validate()
						: window.mtucGetCheckoutPaymentValidation();

				if (!validation.valid) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: validation.message,
						messageContext:
							emitResponse.noticeContexts &&
							emitResponse.noticeContexts.PAYMENTS
								? emitResponse.noticeContexts.PAYMENTS
								: undefined,
					};
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: validation.paymentMethodData || {},
					},
				};
			});

			return unsubscribe;
		}, [onPaymentSetup, emitResponse]);

		return createElement(
			"div",
			{ className: "mtuc-checkout-blocks-payment" },
			description
				? createElement(
						"p",
						{
							className:
								"mtuc-checkout-blocks-payment__description",
						},
						description,
					)
				: null,
			createElement("div", {
				ref: fieldsHostRef,
				className: "mtuc-checkout-blocks-payment__fields",
			}),
		);
	};

	const blockGateway = {
		name: "mtunicredit",
		label: createElement(PaymentLabel),
		ariaLabel: label,
		content: createElement(PaymentContent, null),
		edit: createElement(PaymentContent, null),
		canMakePayment: () => !!settings.isAvailable,
		supports: {
			features:
				settings.supports && settings.supports.length
					? settings.supports
					: ["products"],
		},
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod(blockGateway);
})();
