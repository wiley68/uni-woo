(function () {
	"use strict";

	function togglePanel() {
		var panel = document.getElementById("mtuc-reklama-panel");
		if (!panel) {
			return;
		}

		panel.classList.toggle("is-visible");
	}

	function openUrl(url) {
		window.open(url, "_blank", "noopener,noreferrer");
	}

	window.mtucReklamaToggle = togglePanel;
	window.mtucReklamaOpenUrl = openUrl;
})();
