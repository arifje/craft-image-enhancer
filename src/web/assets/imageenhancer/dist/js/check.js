(function () {
	function checkAndShow() {
		if (typeof Craft !== 'undefined' && Craft.cp) {
			if (window.imageEnhancerModalMessage) {
				showQualityModal(window.imageEnhancerModalMessage);
			}
		} else {
			requestAnimationFrame(checkAndShow);
		}
	}

	checkAndShow();
})();

function showQualityModal(message) {
	const $modal = $('<div class="modal fitted" role="dialog" aria-modal="true"></div>').appendTo(Garnish.$bod);
	const $shade = $('<div class="modal-shade"></div>').appendTo(Garnish.$bod);
	const $container = $('<div class="modal-content"></div>').appendTo($modal);

	$container.append(`
		<div style="padding: 20px">
			<h1 style="margin-top: 0">Waarschuwing</h1>
			<p>${message}</p>
			<div class="buttons" style="text-align: right">
				<div class="btn submit" style="margin-top: 5px;" tabindex="0">Oké, begrepen</div>
			</div>
		</div>
	`);

	const modal = new Garnish.Modal($modal, {
		shadeClass: 'modal-shade',
		onHide: () => {
			$modal.remove();
			$shade.remove();
		}
	});

	$modal.find('.btn.submit').on('click', () => modal.hide());
}
