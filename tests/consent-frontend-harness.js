"use strict";
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");
let assertions = 0;
const check = (ok, message) => {
	assertions += 1;
	if (!ok) throw new Error(message);
};
const contextFor = (zero, identity = zero ? "ZERO|12" : "BASE|6") => ({
	valid: true, interest_state: zero ? "zero" : "nonzero", scheme_identity: identity,
	applicable_ids: zero ? [1, 2, 3, 4] : [1, 2, 5, 6], required_ids: zero ? [2, 4] : [2, 6],
});
function environment(mode, source = "product") {
	const elements = new Map();
	const requests = [];
	const timers = new Map();
	const alerts = [];
	let timer = 0;
	const node = (key) => {
		if (!elements.has(key)) elements.set(key, { key, value: "", attrs: {}, props: {}, data: {}, events: {}, options: [], classes: new Set() });
		return elements.get(key);
	};
	const rows = ["all", "all", "zero_interest", "zero_interest", "nonzero_interest", "nonzero_interest"].map((scope, i) => {
		const checkbox = i % 2 ? node("box" + (i + 1)) : null;
		if (checkbox) { checkbox.checked = false; checkbox.disabled = false; }
		return { hidden: false, scope, checkbox,
			getAttribute: (key) => key === "data-mtuc-consent-row" ? String(i + 1) : scope,
			querySelector: () => checkbox,
		};
	});
	const root = node(mode === "popup" ? "#mtuc-product-popup" : "#mtuc-checkout-payment");
	root.querySelectorAll = () => rows;
	class Collection extends Array {
		val(value) { if (value === undefined) return this[0]?.value; this.forEach((n) => n.value = value); return this; }
		prop(key, value) { if (value === undefined) return this[0]?.[key]; this.forEach((n) => n[key] = value); return this; }
		attr(key, value) { if (value === undefined) return this[0]?.attrs[key]; this.forEach((n) => n.attrs[key] = value); return this; }
		data(key, value) { if (value === undefined) return this[0]?.data[key]; this.forEach((n) => n.data[key] = value); return this; }
		removeData(key) { this.forEach((n) => delete n.data[key]); return this; }
		find(key) {
			if (key.includes("consent-checkbox")) return $(rows.map((r) => r.checkbox).filter(Boolean));
			if (key === "option") return $(this[0]?.options || []);
			return $(key);
		}
		on(events, ...args) { this.forEach((n) => events.split(" ").forEach((e) => n.events[e] = args.at(-1))); return this; }
		off(events) { this.forEach((n) => Object.keys(n.events).filter((e) => events.startsWith(".") ? e.includes(events) : events.split(" ").includes(e)).forEach((e) => delete n.events[e])); return this; }
		trigger(event) { this.forEach((n) => Object.entries(n.events).filter(([e]) => e.split(".")[0] === event).forEach(([, fn]) => fn.call(n, { preventDefault() {}, stopPropagation() {} }))); return this; }
		each(fn) { this.forEach((n, i) => fn.call(n, i, n)); return this; }
		empty() { this.forEach((n) => n.options = []); return this; }
		append(collection) { this.forEach((n) => n.options.push(...collection)); return this; }
		parent() { return $([]); }
		add(other) { return $([...this, ...other]); }
		eq(i) { return $(this[i] ? [this[i]] : []); }
		text(value) { if (value === undefined) return this[0]?.text || ""; this.forEach((n) => n.text = value); return this; }
		removeAttr(key) { this.forEach((n) => delete n.attrs[key]); return this; }
		addClass(key) { this.forEach((n) => n.classes.add(key)); return this; }
		removeClass(key) { this.forEach((n) => n.classes.delete(key)); return this; }
		toggleClass(key, on) { return on ? this.addClass(key) : this.removeClass(key); }
		hasClass(key) { return this[0]?.classes.has(key); }
		remove() { return this; }
	}
	function $(value, attrs) {
		if (typeof value === "function") { value(); return $([]); }
		if (value instanceof Collection) return value;
		if (Array.isArray(value)) return Collection.from(value);
		if (value === "<option>") { const option = node("option" + elements.size); option.value = attrs.value; return $([option]); }
		return $([typeof value === "string" ? node(value) : value]);
	}
	$.post = (url, payload) => {
		const request = { payload, done(fn) { this.success = fn; return this; }, fail(fn) { this.failure = fn; return this; },
			resolve(context) { this.success({ success: true, data: { consent_context: context, parva: 0, glp: 0, scheme_key: this.payload.scheme_key } }); },
			reject() { this.failure({}); },
		};
		requests.push(request); return request;
	};
	const document = { events: {}, body: node("body"), getElementById: (id) => elements.get("#" + id) || null, addEventListener() {} };
	const config = { ajaxUrl: "/fake", nonce: "test", blocks: mode === "blocks", source,
		i18n: { calcError: "calculation error" }, enabledSchemes: [{ key: "6:0", months: 6 }, { key: "12:0", months: 12 }], defaultSchemeKey: "6:0" };
	const sandbox = { document, jQuery: $, console, URLSearchParams, mtucCheckout: config,
		mtucPopup: { ...config, source, productId: 10 },
		mtucGetProductLineContext: () => ({ productId: 10, variationId: 0, quantity: 1, linePrice: 600 }),
		alert: (message) => alerts.push(message),
		setTimeout: (fn) => { timers.set(++timer, fn); return timer; }, clearTimeout: (id) => timers.delete(id),
	};
	sandbox.window = sandbox;
	const prefix = mode === "popup" ? "#mtuc-popup-" : "#mtuc-checkout-";
	$(prefix + "months").val("6:0"); $(prefix + "parva").val("0"); $(prefix + "offer-type").val("standard");
	$("#mtuc-popup-source").val(source);
	$('input[name="payment_method"]:checked').val("mtunicredit");
	vm.createContext(sandbox);
	for (const file of ["mtuc-shop-consents.js", mode === "popup" ? "mtuc-product-popup.js" : "mtuc-checkout-payment.js"]) {
		vm.runInContext(fs.readFileSync(path.join(__dirname, "../js", file), "utf8"), sandbox, { filename: file });
	}
	if (mode === "blocks") sandbox.mtucInitCheckoutPayment(document, config);
	return { $, prefix, rows, root, requests, timers, alerts, sandbox, document,
		change: (key) => { $(prefix + "months").val(key).trigger("change"); return requests.at(-1); },
		checkIds: (...ids) => rows.forEach((r, i) => { if (r.checkbox) r.checkbox.checked = ids.includes(i + 1); }),
	};
}
for (const [mode, source] of [["popup", "product"], ["popup", "cart"], ["classic", "checkout"], ["blocks", "checkout"]]) {
	const e = environment(mode, source);
	const label = mode + "/" + source;
	const first = e.change("6:0"); first.resolve(contextFor(false));
	check(e.rows[2].hidden && e.rows[3].checkbox.disabled && !e.rows[4].hidden, label + " nonzero visibility");
	e.checkIds(2, 6);
	const pending = e.change("12:0");
	check(e.rows[1].checkbox.checked && !e.rows[5].checkbox.checked && e.rows.every((r) => r.hidden), label + " change invalidates immediately and preserves all");
	pending.resolve(contextFor(true));
	check(!e.rows[2].hidden && e.rows[4].hidden && !e.rows[3].checkbox.checked, label + " new scoped acceptance required");
	e.checkIds(2, 4);
	const old = e.change("6:0");
	const current = e.change("12:0"); current.resolve(contextFor(true));
	old.resolve(contextFor(false));
	check(!e.rows[2].hidden && e.rows[4].hidden, label + " late success cannot overwrite current selection");
	const alertsBefore = e.alerts.length; old.reject();
	check(e.alerts.length === alertsBefore, label + " late error ignored");
	e.checkIds(2, 4);
	e.$(e.prefix + "parva").val("10").trigger("input");
	check(e.rows[3].checkbox.checked && e.rows[3].checkbox.disabled, label + " first installment preserves scoped acceptance while pending");
	for (const fn of [...e.timers.values()]) fn(); e.timers.clear();
	e.requests.at(-1).resolve(contextFor(true));
	check(e.rows[3].checkbox.checked && !e.rows[3].checkbox.disabled, label + " same scheme retains acceptance after recalculation");
	const failed = e.change("6:0"); failed.reject();
	check(e.rows.every((r) => r.hidden) && e.rows[1].checkbox.disabled, label + " current error remains blocked");
	const last = e.change("6:0"); last.resolve(contextFor(false)); e.checkIds(2, 6);
	if (mode === "popup") {
		for (const field of ["first-name", "last-name", "address"]) e.$("#mtuc-popup-" + field).val("Test");
		e.$("#mtuc-popup-phone").val("+359888123456"); e.$("#mtuc-popup-email").val("test@example.com");
		e.$("#mtuc-popup-submit").trigger("click");
		check(JSON.stringify(e.requests.at(-1).payload.mtuc_consent) === '["2","6"]', label + " popup sends applicable accepted IDs");
	} else {
		const controller = e.sandbox.mtucCheckoutBlocksController;
		check(controller.validate().valid && controller.validate().paymentMethodData.mtuc_consent === "2,6", label + " checkout CSV excludes scoped hidden IDs");
		if (mode === "classic") {
			e.$(e.document.body).trigger("update_checkout");
			check(!controller.validate().valid, "Classic refresh invalidates current form");
		}
		const inFlight = e.change("12:0");
		controller.destroy(); inFlight.resolve(contextFor(true));
		check(!controller.validate().valid, label + " destroyed controller ignores in-flight result");
		const replacement = e.sandbox.mtucInitCheckoutPayment(e.document, e.sandbox.mtucCheckout);
		e.requests.at(-1).resolve(contextFor(true));
		check(!replacement.validate().valid, label + " remount requires scoped reacceptance");
	}
}
// Direct helper checks for server context changes and missing current markup.
const e = environment("popup");
const helper = e.sandbox.mtucCreateShopConsents(e.root);
let token = helper.begin("same-key"); helper.apply(token, contextFor(false)); e.checkIds(2, 6);
token = helper.begin("same-key"); helper.apply(token, contextFor(false, "OTHER-KOP|6"));
check(e.rows[1].checkbox.checked && !e.rows[5].checkbox.checked, "KOP identity change clears scoped acceptance even with same select key");
token = helper.begin("same-key");
check(!helper.apply(token, { ...contextFor(false), required_ids: [2, 6, 99] }) && !helper.ready(), "new server mandatory ID missing from page blocks submission");
token = helper.begin("same-key");
check(!helper.apply(token, { ...contextFor(false), valid: false }) && helper.acceptedIds().length === 0, "unknown interest context cannot submit accepted IDs");
// Execute the Blocks React adapter too: pending refresh, reversed fetches, unmount.
(async () => {
	const e = environment("blocks", "checkout");
	const refs = [];
	const effects = [];
	const fetches = [];
	const mounted = [];
	let gateway;
	let subscription;
	let paymentSetup;
	let quantity = 1;
	const settings = { fieldsHtml: "initial", isAvailable: true, checkout: { ajaxUrl: "/fake", nonce: "test" } };
	e.sandbox.mtucInitCheckoutPayment = (host) => {
		const controller = { destroyed: false, destroy() { this.destroyed = true; }, validate() { return { valid: !this.destroyed, paymentMethodData: { mtuc_consent: "2,6" } }; } };
		mounted.push({ html: host.innerHTML, controller }); return controller;
	};
	e.sandbox.wc = { wcSettings: { getSetting: () => settings }, wcBlocksRegistry: { registerPaymentMethod: (value) => gateway = value }, wcBlocksData: { CART_STORE_KEY: "cart" } };
	e.sandbox.wp = {
		htmlEntities: { decodeEntities: (v) => v },
		element: {
			useRef: (value) => { const ref = { current: value }; refs.push(ref); return ref; },
			useEffect: (fn) => effects.push(fn),
			createElement: (type, props, ...children) => {
				if (props?.ref) props.ref.current = { innerHTML: "" };
				return { type, props, children };
			},
		},
		data: { select: () => ({ getCartData: () => ({ items: [{ key: "line", quantity }] }) }), subscribe: (fn) => { subscription = fn; return () => {}; } },
	};
	e.sandbox.fetch = () => new Promise((resolve) => fetches.push(resolve));
	vm.runInContext(fs.readFileSync(path.join(__dirname, "../js/mtuc-checkout-blocks.js"), "utf8"), e.sandbox);
	gateway.content.type({ eventRegistration: { onPaymentSetup: (fn) => { paymentSetup = fn; return () => {}; } }, emitResponse: { responseTypes: { SUCCESS: "success", ERROR: "error" } } });
	const cleanups = effects.map((fn) => fn());
	check(mounted.length === 1 && paymentSetup().type === "success", "Blocks adapter mounts shared controller");
	const changeCart = () => {
		quantity += 1; subscription();
		check(paymentSetup().type === "error", "Blocks cart change immediately blocks payment setup");
		for (const fn of [...e.timers.values()]) fn(); e.timers.clear();
	};
	changeCart(); changeCart();
	const flush = async () => { for (let i = 0; i < 6; i += 1) await Promise.resolve(); };
	fetches[1]({ json: () => Promise.resolve({ success: true, data: { fieldsHtml: "newest" } }) }); await flush();
	fetches[0]({ json: () => Promise.resolve({ success: true, data: { fieldsHtml: "stale" } }) }); await flush();
	check(mounted.length === 2 && mounted[1].html === "newest", "Blocks reversed refresh responses mount only newest fields");
	check(paymentSetup().type === "success", "Blocks refreshed controller can validate");
	changeCart(); cleanups[0]();
	fetches[2]({ json: () => Promise.resolve({ success: true, data: { fieldsHtml: "after-unmount" } }) }); await flush();
	check(mounted.length === 2, "Blocks unmount ignores late fields response");
	console.log(`OK consent-frontend ${assertions} assertions`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
