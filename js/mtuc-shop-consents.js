(function (global) {
	"use strict";

	// The server decides applicability; this controller only applies that decision.
	global.mtucCreateShopConsents = function (root) {
		const rows = Array.from(root.querySelectorAll("[data-mtuc-consent-row]"));
		let generation = 0;
		let selection = null;
		let identity = null;
		let context = null;
		let active = false;
		let destroyed = false;
		const id = (row) => String(row.getAttribute("data-mtuc-consent-row"));
		const box = (row) => row.querySelector(".mtuc-popup__consent-checkbox");
		const clearScoped = () => rows.forEach((row) => {
			if (row.getAttribute("data-mtuc-interest-scope") !== "all" && box(row)) {
				box(row).checked = false;
			}
		});
		const hide = () => rows.forEach((row) => {
			row.hidden = true;
			if (box(row)) box(row).disabled = true;
		});
		const begin = (key) => {
			if (selection !== null && selection !== key) clearScoped();
			selection = key;
			active = false;
			context = null;
			hide();
			return ++generation;
		};
		const isCurrent = (token) => !destroyed && token === generation;
		const apply = (token, next) => {
			if (!isCurrent(token) || !next || !Array.isArray(next.applicable_ids) ||
				!Array.isArray(next.required_ids) || typeof next.scheme_identity !== "string") return false;
			if (identity !== null && identity !== next.scheme_identity) clearScoped();
			identity = next.scheme_identity;
			context = next;
			const applicable = next.applicable_ids.map(String);
			rows.forEach((row) => {
				const visible = applicable.includes(id(row));
				row.hidden = !visible;
				if (box(row)) {
					box(row).disabled = !visible;
					if (!visible) box(row).checked = false;
				}
			});
			// A new mandatory consent missing from this page requires a fresh render.
			active = next.valid === true && next.required_ids.every((required) =>
				rows.some((row) => id(row) === String(required) && !row.hidden && box(row)));
			if (!active) hide();
			return active;
		};
		const resolved = () => active && !destroyed;
		const ready = () => resolved() && context.required_ids.every((required) =>
			rows.some((row) => id(row) === String(required) && box(row) &&
				!box(row).disabled && box(row).checked));
		const acceptedIds = () => resolved() ? rows.filter((row) =>
			!row.hidden && box(row) && !box(row).disabled && box(row).checked).map(id) : [];
		hide();
		return {
			begin, isCurrent, apply, resolved, ready, acceptedIds,
			destroy: () => { begin(selection); destroyed = true; },
		};
	};
})(typeof window === "undefined" ? globalThis : window);
