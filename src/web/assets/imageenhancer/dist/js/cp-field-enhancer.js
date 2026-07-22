(function () {
	'use strict';

	const defaults = {
		craftMajorVersion: 4,
		uploadRequirementAssistantEnabled: false,
		providerChoiceEnabled: false,
		imageEnhancementProvider: 'openai',
		imageEnhancementModel: 'gpt-image-2',
		xAiImageEnhancementModel: 'grok-imagine-image-quality',
		googleImageEnhancementModel: 'gemini-3.1-flash-image',
		allowedFieldHandles: [],
		routes: {
			uploadAssistant: 'craft-image-enhancer/upload-assistant/upload',
			uploadLocalRepair: 'craft-image-enhancer/upload-assistant/local-repair',
			uploadFinalize: 'craft-image-enhancer/upload-assistant/finalize',
			uploadDiscard: 'craft-image-enhancer/upload-assistant/discard',
			assetInfo: 'craft-image-enhancer/article-image/asset-info',
			enhance: 'craft-image-enhancer/article-image/enhance',
			status: 'craft-image-enhancer/article-image/status',
			cancel: 'craft-image-enhancer/article-image/cancel',
			keep: 'craft-image-enhancer/article-image/keep',
			discard: 'craft-image-enhancer/article-image/discard',
		},
		modelOptions: {
			openai: [
				{ label: 'GPT Image 2', value: 'gpt-image-2' },
				{ label: 'GPT Image 1', value: 'gpt-image-1' },
			],
			xai: [
				{ label: 'Grok Imagine Image Quality', value: 'grok-imagine-image-quality' },
			],
			google: [
				{ label: 'Gemini 3.1 Flash Image (Nano Banana 2)', value: 'gemini-3.1-flash-image' },
				{ label: 'Gemini 3 Pro Image (Nano Banana Pro)', value: 'gemini-3-pro-image' },
				{ label: 'Gemini 2.5 Flash Image (Nano Banana)', value: 'gemini-2.5-flash-image' },
			],
		},
	};
	const config = mergeConfig(defaults, window.ImageEnhancerCp || {});
	const providerStorageKey = 'craft-image-enhancer:cp:provider-preference';
	const scanSelector = [
		'.field .element',
		'.field .element-card',
		'.field .element[data-id]',
		'.field .element-card[data-id]',
		'.field .asset[data-id]',
		'.field [data-asset-id]',
		'.field [data-type*="Asset"][data-id]',
	].join(',');
	let observer = null;
	let scanBurstTimer = null;
	let activeUploadRepair = false;
	const uploadRepairQueue = [];

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback, { once: true });
			return;
		}

		callback();
	}

	function init() {
		if (!window.Craft || !document.body) {
			return;
		}

		scanAssetFields(document);
		scanUploadAssistantInputs(document);
		document.addEventListener('click', onDocumentClick, true);
		document.addEventListener('click', onPotentialTabChange, true);
		document.addEventListener('keydown', onPotentialTabChange, true);
		window.addEventListener('hashchange', scheduleScanBurst);
		observer = new MutationObserver(() => {
			scheduleScanBurst();
		});
		observer.observe(document.body, {
			childList: true,
			subtree: true,
		});
	}

	function onPotentialTabChange(event) {
		if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) {
			return;
		}

		const target = event.target.closest?.([
			'a[href^="#"]',
			'button[role="tab"]',
			'[role="tab"]',
			'[data-tab]',
			'.tabs a',
			'.pane-tabs a',
			'.fieldlayout-tabs a',
		].join(','));

		if (!target) {
			return;
		}

		scheduleScanBurst();
	}

	function scheduleScanBurst() {
		window.clearTimeout(scanBurstTimer);
		scanBurstTimer = window.setTimeout(() => {
			scanAssetFields(document);
			scanUploadAssistantInputs(document);
			window.setTimeout(() => {
				scanAssetFields(document);
				scanUploadAssistantInputs(document);
			}, 250);
			window.setTimeout(() => {
				scanAssetFields(document);
				scanUploadAssistantInputs(document);
			}, 750);
		}, 50);
	}

	function scanUploadAssistantInputs(root) {
		if (!config.uploadRequirementAssistantEnabled || !window.jQuery || !window.Craft?.AssetSelectInput) {
			return;
		}

		const containers = new Set();
		if (root.matches?.('.elementselect')) {
			containers.add(root);
		}
		root.querySelectorAll?.('.elementselect').forEach((container) => containers.add(container));
		containers.forEach(setupUploadAssistantInput);
	}

	function setupUploadAssistantInput(container) {
		if (container.dataset.imageEnhancerUploadAssistantReady === '1' || !isAllowedUploadField(container)) {
			return;
		}

		const input = window.jQuery(container).data('elementSelect');
		if (!input || !(input instanceof Craft.AssetSelectInput) || !input.uploader?.uploader) {
			return;
		}

		const route = config.routes.uploadAssistant;
		const originalDone = input.uploader.events?.fileuploaddone;
		if (!route || typeof originalDone !== 'function') {
			return;
		}

		const $uploader = input.uploader.$element;
		$uploader.off('fileuploaddone', originalDone);
		$uploader.off('fileuploaddone.imageEnhancerUploadAssistant');
		$uploader.on('fileuploaddone.imageEnhancerUploadAssistant', (event, data = null) => {
			const result = event instanceof CustomEvent ? event.detail : data?.result;
			if (!result?.needsRepair) {
				originalDone(event, data);
				return;
			}

			finishUploadProgress(input);
			enqueueUploadRepair(input, result);
		});

		input.uploader.uploader.fileupload('option', {
			url: Craft.getActionUrl(route),
		});
		container.dataset.imageEnhancerUploadAssistantReady = '1';
	}

	function isAllowedUploadField(container) {
		const field = container.closest('.field');
		const handle = getFieldHandle(field);
		const allowedHandles = Array.isArray(config.allowedFieldHandles)
			? config.allowedFieldHandles.filter(Boolean)
			: [];

		if (allowedHandles.length > 0) {
			return Boolean(handle && allowedHandles.includes(handle));
		}

		return Boolean(field && !isNestedField(field));
	}

	function finishUploadProgress(input) {
		if (!input.uploader.isLastUpload()) {
			return;
		}

		input.progressBar?.hideProgressBar();
		input.$container?.removeClass('uploading');
	}

	function enqueueUploadRepair(input, result) {
		uploadRepairQueue.push({ input, result });
		openNextUploadRepair();
	}

	function openNextUploadRepair() {
		if (activeUploadRepair || uploadRepairQueue.length === 0) {
			return;
		}

		activeUploadRepair = true;
		const { input, result } = uploadRepairQueue.shift();
		const modal = new CpEnhancerModal({
			assetId: result.assetId,
			originalUrl: result.previewUrl,
			card: null,
			assetInput: input,
			uploadRepair: result,
			onDestroyed: () => {
				activeUploadRepair = false;
				openNextUploadRepair();
			},
		});

		modal.show().catch(async (error) => {
			await discardQueuedUpload(result.repairToken);
			activeUploadRepair = false;
			openNextUploadRepair();
			Craft.cp?.displayError(error instanceof Error ? error.message : 'Could not open the upload assistant.');
		});
	}

	async function discardQueuedUpload(repairToken) {
		if (!repairToken || !config.routes.uploadDiscard) {
			return;
		}

		try {
			await Craft.sendActionRequest('POST', config.routes.uploadDiscard, {
				data: { repairToken },
			});
		} catch (error) {
			// The temporary upload will be cleaned up by Craft if this request fails.
		}
	}

	function scanAssetFields(root) {
		const candidates = new Set();
		if (root.matches && root.matches(scanSelector)) {
			candidates.add(root);
		}

		const closestCandidate = root.closest?.(scanSelector);
		if (closestCandidate) {
			candidates.add(closestCandidate);
		}

		root.querySelectorAll?.(scanSelector).forEach((candidate) => {
			candidates.add(candidate);
		});

		candidates.forEach(attachEnhanceButton);
	}

	function attachEnhanceButton(candidate) {
		const card = getAssetCard(candidate);
		if (!card || card.dataset.imageEnhancerCpReady === '1') {
			return;
		}

		const assetId = getAssetId(card);
		const originalUrl = getImageUrl(card);
		if (!assetId || !isAssetElement(card) || !isAllowedAssetField(card)) {
			return;
		}

		const action = document.createElement('div');
		action.className = 'image-enhancer-cp-field-action';
		action.innerHTML = '<button type="button" class="image-enhancer-cp-field-button">Enhance</button>';
		const button = action.querySelector('button');
		button.dataset.imageEnhancerCpAssetId = assetId;
		button.dataset.imageEnhancerCpOriginalUrl = originalUrl;

		card.appendChild(action);
		card.dataset.imageEnhancerCpReady = '1';
	}

	function getAssetCard(candidate) {
		return candidate.closest?.('.element[data-id], .element-card[data-id], [data-asset-id]') || candidate;
	}

	function getAssetId(card) {
		const explicitId = card.dataset.assetId || card.dataset.id;
		if (explicitId && /^\d+$/.test(String(explicitId))) {
			return String(explicitId);
		}

		const hiddenInput = card.querySelector('input[type="hidden"][value]');
		const inputValue = hiddenInput?.value || '';

		return /^\d+$/.test(inputValue) ? inputValue : '';
	}

	function isAssetElement(card) {
		const type = card.dataset.type || card.getAttribute('data-type') || '';
		if (type) {
			return type.includes('Asset');
		}

		const kind = (card.dataset.kind || card.getAttribute('data-kind') || '').toLowerCase();
		if (kind && kind !== 'image') {
			return false;
		}

		return Boolean(card.dataset.assetId ||
			card.classList.contains('asset') ||
			kind === 'image' ||
			card.querySelector('[data-asset-id]') ||
			card.closest('[data-type*="Asset"]') ||
			card.querySelector('img') ||
			getBackgroundImageUrl(card));
	}

	function isAllowedAssetField(card) {
		const field = getContainingField(card);
		const handle = getFieldHandle(field);
		const allowedHandles = Array.isArray(config.allowedFieldHandles)
			? config.allowedFieldHandles.filter(Boolean)
			: [];

		if (allowedHandles.length > 0) {
			return Boolean(handle && allowedHandles.includes(handle));
		}

		return Boolean(field && !isNestedField(field) && !isNestedAssetCard(card));
	}

	function getContainingField(card) {
		const fields = [];
		let node = card.closest?.('.field') || null;
		while (node) {
			fields.push(node);
			node = node.parentElement?.closest?.('.field') || null;
		}

		return fields.find((field) => getFieldHandle(field)) || fields[0] || null;
	}

	function getFieldHandle(field) {
		if (!field) {
			return '';
		}

		const dataHandle = field.dataset.handle || field.dataset.attribute || field.getAttribute('data-handle') || '';
		if (dataHandle) {
			return normalizeFieldHandle(dataHandle);
		}

		const idMatches = [...String(field.id || '').matchAll(/fields-([A-Za-z0-9_]+)-field/g)];
		if (idMatches.length > 0) {
			return idMatches[idMatches.length - 1][1];
		}

		const input = field.querySelector('input[name], select[name], textarea[name]');
		const name = input?.getAttribute('name') || '';
		const nestedMatches = [...name.matchAll(/\[fields\]\[([^\]]+)\]/g)];
		if (nestedMatches.length > 0) {
			return nestedMatches[nestedMatches.length - 1][1];
		}

		const topLevelMatch = name.match(/^fields\[([^\]]+)\]/);

		return topLevelMatch ? topLevelMatch[1] : '';
	}

	function normalizeFieldHandle(handle) {
		return String(handle).replace(/^field:/, '').replace(/^fields\./, '');
	}

	function isNestedField(field) {
		return Boolean(field.closest('.matrixblock, .matrix-block, .matrixblock-container, .matrix-field, [data-type*="Matrix"], [data-layout-element="matrix"]'));
	}

	function isNestedAssetCard(card) {
		return Boolean(card.closest('.matrixblock, .matrix-block, .matrixblock-container, .matrix-field, [data-type*="Matrix"], [data-layout-element="matrix"]'));
	}

	function getImageUrl(card) {
		const image = card.querySelector('img[src], img[srcset]');
		if (image) {
			return image.currentSrc || image.src || firstSrcsetUrl(image.getAttribute('srcset')) || '';
		}

		return getBackgroundImageUrl(card);
	}

	function firstSrcsetUrl(srcset) {
		if (!srcset) {
			return '';
		}

		return srcset.split(',')[0]?.trim().split(/\s+/)[0] || '';
	}

	function getBackgroundImageUrl(card) {
		const backgroundNode = Array.from(card.querySelectorAll('*')).find((node) => {
			const style = window.getComputedStyle(node);
			return style.backgroundImage && style.backgroundImage !== 'none';
		});
		const backgroundImage = backgroundNode ? window.getComputedStyle(backgroundNode).backgroundImage : '';
		const match = backgroundImage.match(/url\(["']?(.+?)["']?\)/);

		return match ? match[1] : '';
	}

	function onDocumentClick(event) {
		const button = event.target.closest?.('.image-enhancer-cp-field-button');
		if (!button) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		const card = button.closest('.element[data-id], .element-card[data-id], [data-asset-id]');
		const modal = new CpEnhancerModal({
			assetId: button.dataset.imageEnhancerCpAssetId,
			originalUrl: button.dataset.imageEnhancerCpOriginalUrl || getImageUrl(card),
			card,
		});
		button.disabled = true;
		modal.show()
			.catch((error) => {
				const message = error instanceof Error ? error.message : 'Could not open image enhancer.';
				if (window.Craft?.cp) {
					Craft.cp.displayError(message);
				}
			})
			.finally(() => {
				button.disabled = false;
			});
	}

	class CpEnhancerModal {
		constructor({ assetId, originalUrl, card, assetInput = null, uploadRepair = null, onDestroyed = null }) {
			this.assetId = assetId;
			this.originalUrl = originalUrl;
			this.card = card;
			this.assetInput = assetInput;
			this.uploadRepair = uploadRepair;
			this.repairToken = uploadRepair?.repairToken || '';
			this.onDestroyed = onDestroyed;
			this.uploadFinalized = false;
			this.uploadDiscarded = false;
			this.destroyed = false;
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.enhancedUrl = '';
			this.pollTimer = null;
			this.statusStartedAt = 0;
			this.statusTickTimer = null;
			this.selectedProvider = this.getInitialProvider();
			this.selectedModel = this.getInitialModel(this.selectedProvider);
			this.modal = null;
			this.root = null;
		}

		async show() {
			if (!this.uploadRepair) {
				await this.resolveAssetInfo();
			}
			this.build();

			if (window.Garnish && window.jQuery && Garnish.$bod) {
				this.$root = window.jQuery(this.root).appendTo(Garnish.$bod);
				this.modal = new Garnish.Modal(this.$root, {
					onHide: () => this.destroy(),
				});
			} else {
				document.body.appendChild(this.root);
				this.root.classList.add('is-visible');
			}
		}

		async resolveAssetInfo() {
			if (!this.assetId) {
				return;
			}

			try {
				const response = await this.request('assetInfo', {
					assetId: this.assetId,
				});
				const assetUrl = response.url || response.assetUrl || response.imageUrl || '';
				if (assetUrl) {
					this.originalUrl = assetUrl;
				}
			} catch (error) {
				if (!this.originalUrl) {
					throw error;
				}
			}
		}

		build() {
			const title = this.uploadRepair ? 'Image does not meet field requirements' : 'Enhance image';
			const description = this.uploadRepair
				? 'Review the uploaded image and choose how to make it selectable for this field.'
				: 'Queue an enhancement, compare the result, then save it over the current asset.';
			const root = document.createElement('div');
			root.className = 'modal image-enhancer-cp-modal';
			root.innerHTML = [
				'<div class="image-enhancer-cp-shell">',
				'  <div class="image-enhancer-cp-header">',
				'    <div>',
				`      <h2>${title}</h2>`,
				`      <p>${description}</p>`,
				'    </div>',
				'    <button type="button" class="image-enhancer-cp-close" aria-label="Close">&times;</button>',
				'  </div>',
				'  <div class="image-enhancer-upload-summary" data-upload-summary hidden>',
				'    <div class="image-enhancer-upload-summary__facts" data-upload-facts></div>',
				'    <div class="image-enhancer-upload-summary__issues">',
				'      <h3>Why it cannot be selected</h3>',
				'      <ul data-upload-issues></ul>',
				'    </div>',
				'  </div>',
				'  <div class="image-enhancer-cp-provider" data-provider-controls hidden>',
				'    <label><span>Provider</span><select data-provider></select></label>',
				'    <label><span>Model</span><select data-model></select></label>',
				'  </div>',
				'  <div class="image-enhancer-cp-stage">',
				'    <div class="image-enhancer-cp-single" data-single>',
				'      <img data-original alt="">',
				'    </div>',
				'    <div class="image-enhancer-cp-compare" data-compare hidden>',
				'      <img data-compare-original alt="">',
				'      <div class="image-enhancer-cp-enhanced-wrap" data-enhanced-wrap>',
				'        <img data-enhanced alt="">',
				'      </div>',
				'      <div class="image-enhancer-cp-divider" data-divider><span></span></div>',
				'      <input type="range" min="0" max="100" value="50" aria-label="Compare original and enhanced image" data-range>',
				'    </div>',
				'  </div>',
				'  <div class="image-enhancer-cp-status" data-status hidden>',
				'    <span class="image-enhancer-cp-spinner" aria-hidden="true"></span>',
				'    <span data-status-text></span>',
				'  </div>',
				'  <div class="image-enhancer-cp-error" data-error hidden></div>',
				'  <div class="image-enhancer-cp-actions">',
				'    <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-local-repair>Resize locally</button>',
				'    <button type="button" class="btn submit" data-enhance>Enhance</button>',
				'    <button type="button" class="btn image-enhancer-cp-is-hidden" data-cancel>Cancel</button>',
				'    <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-keep>Save replacement</button>',
				'    <button type="button" class="btn image-enhancer-cp-is-hidden" data-discard>Discard</button>',
				'    <button type="button" class="btn image-enhancer-cp-is-hidden" data-discard-upload>Discard upload</button>',
				'  </div>',
				'</div>',
			].join('');

			this.root = root;
			this.originalImage = root.querySelector('[data-original]');
			this.compareOriginalImage = root.querySelector('[data-compare-original]');
			this.enhancedImage = root.querySelector('[data-enhanced]');
			this.single = root.querySelector('[data-single]');
			this.compare = root.querySelector('[data-compare]');
			this.enhancedWrap = root.querySelector('[data-enhanced-wrap]');
			this.divider = root.querySelector('[data-divider]');
			this.range = root.querySelector('[data-range]');
			this.status = root.querySelector('[data-status]');
			this.statusText = root.querySelector('[data-status-text]');
			this.error = root.querySelector('[data-error]');
			this.enhanceButton = root.querySelector('[data-enhance]');
			this.localRepairButton = root.querySelector('[data-local-repair]');
			this.cancelButton = root.querySelector('[data-cancel]');
			this.keepButton = root.querySelector('[data-keep]');
			this.discardButton = root.querySelector('[data-discard]');
			this.discardUploadButton = root.querySelector('[data-discard-upload]');
			this.closeButton = root.querySelector('.image-enhancer-cp-close');
			this.uploadSummary = root.querySelector('[data-upload-summary]');
			this.uploadFacts = root.querySelector('[data-upload-facts]');
			this.uploadIssues = root.querySelector('[data-upload-issues]');
			this.providerControls = root.querySelector('[data-provider-controls]');
			this.providerSelect = root.querySelector('[data-provider]');
			this.modelSelect = root.querySelector('[data-model]');

			this.originalImage.src = this.originalUrl;
			this.compareOriginalImage.src = this.originalUrl;
			this.range.addEventListener('input', () => this.updateComparison());
			root.querySelector('[data-local-repair]').addEventListener('click', () => this.repairLocally());
			root.querySelector('[data-enhance]').addEventListener('click', () => this.enhance());
			root.querySelector('[data-cancel]').addEventListener('click', () => this.cancel());
			root.querySelector('[data-keep]').addEventListener('click', () => this.keep());
			root.querySelector('[data-discard]').addEventListener('click', () => this.discard());
			root.querySelector('[data-discard-upload]').addEventListener('click', () => this.discardUpload());
			this.closeButton.addEventListener('click', () => this.requestClose());

			if (this.uploadRepair) {
				this.enhanceButton.textContent = 'Enhance with AI';
				this.keepButton.textContent = 'Use enhanced image';
				this.discardButton.textContent = 'Back';
				this.updateUploadRepairDetails(this.uploadRepair);
			}

			this.setupProviderControls();
			this.setActionState('idle');
			this.updateComparison();
			if (!this.uploadRepair) {
				this.restoreStatus();
			}
		}

		updateUploadRepairDetails(details) {
			this.uploadRepair = { ...this.uploadRepair, ...details };
			this.uploadSummary.hidden = false;
			this.uploadFacts.replaceChildren();

			const facts = [
				['File', this.uploadRepair.filename || 'Uploaded image'],
				['Current size', `${this.uploadRepair.width || 0} × ${this.uploadRepair.height || 0} px`],
				['File size', this.uploadRepair.fileSizeLabel || 'Unknown'],
			];
			if (this.uploadRepair.targetWidth && this.uploadRepair.targetHeight) {
				facts.push(['Proposed size', `${this.uploadRepair.targetWidth} × ${this.uploadRepair.targetHeight} px`]);
			}

			facts.forEach(([label, value]) => {
				const fact = document.createElement('div');
				const factLabel = document.createElement('span');
				const factValue = document.createElement('strong');
				factLabel.textContent = label;
				factValue.textContent = value;
				fact.append(factLabel, factValue);
				this.uploadFacts.appendChild(fact);
			});

			this.uploadIssues.replaceChildren();
			(this.uploadRepair.violations || []).forEach((violation) => {
				const item = document.createElement('li');
				item.textContent = violation.message || `${violation.label || 'Image'} does not meet the field requirement.`;
				this.uploadIssues.appendChild(item);
			});

			this.localRepairButton.disabled = !this.uploadRepair.repairable ||
				!this.uploadRepair.targetWidth ||
				!this.uploadRepair.targetHeight;
			this.localRepairButton.textContent = this.uploadRepair.targetWidth && this.uploadRepair.targetHeight
				? `Resize to ${this.uploadRepair.targetWidth} × ${this.uploadRepair.targetHeight}`
				: 'Resize locally';
		}

		async repairLocally() {
			if (!this.uploadRepair || this.localRepairButton.disabled) {
				return;
			}

			this.clearError();
			this.setRepairProcessing(true, 'Resizing image...');
			try {
				const response = await this.request('uploadLocalRepair', {
					repairToken: this.repairToken,
				});
				if (!response.complete) {
					this.updateUploadRepairDetails(response);
					this.refreshUploadRepairPreview();
					throw new Error(response.message || 'The resized image still does not meet every field requirement.');
				}

				await this.completeUploadRepair(response, 'Image resized and added to the field.');
			} catch (error) {
				this.setRepairProcessing(false);
				this.showError(error);
			}
		}

		async completeUploadRepair(response, notice) {
			if (!response.assetId || !this.assetInput) {
				throw new Error('The repaired asset could not be added to this field.');
			}

			this.uploadFinalized = true;
			try {
				await selectRepairedAsset(this.assetInput, response.assetId);
			} catch (error) {
				Craft.cp?.displayError('The image was saved, but the asset field could not refresh. Reload this entry to see it.');
				this.close();
				return;
			}
			Craft.cp?.runQueue?.();
			if (window.Craft?.cp) {
				Craft.cp.displayNotice(notice);
			}
			this.close();
		}

		refreshUploadRepairPreview() {
			this.originalUrl = withCacheBuster(this.originalUrl);
			this.originalImage.src = this.originalUrl;
			this.compareOriginalImage.src = this.originalUrl;
		}

		async discardUpload() {
			if (!this.uploadRepair || this.uploadDiscarded || this.uploadFinalized) {
				this.close();
				return;
			}

			this.clearError();
			this.setRepairProcessing(true, 'Discarding upload...');
			try {
				if (this.previewId) {
					await this.request('discard', {
						assetId: this.assetId,
						previewId: this.previewId,
						token: this.token,
					});
					this.previewId = '';
				}
				await this.request('uploadDiscard', {
					repairToken: this.repairToken,
				});
				this.uploadDiscarded = true;
				this.close();
			} catch (error) {
				this.setRepairProcessing(false);
				this.showError(error);
			}
		}

		async requestClose() {
			if (!this.uploadRepair || this.uploadFinalized || this.uploadDiscarded) {
				this.close();
				return;
			}

			const confirmed = window.confirm('Discard this uploaded image?');
			if (confirmed) {
				await this.discardUpload();
			}
		}

		async cleanupPendingUpload() {
			if (!this.uploadRepair || this.uploadFinalized || this.uploadDiscarded) {
				return;
			}

			this.uploadDiscarded = true;
			try {
				if (this.token) {
					await this.request('cancel', {
						assetId: this.assetId,
						token: this.token,
						jobId: this.jobId,
						uploadRepairToken: this.repairToken,
					});
				}
				if (this.previewId) {
					await this.request('discard', {
						assetId: this.assetId,
						previewId: this.previewId,
						token: this.token,
						uploadRepairToken: this.repairToken,
					});
				}
				await this.request('uploadDiscard', {
					repairToken: this.repairToken,
				});
			} catch (error) {
				// Craft's temporary asset cleanup remains the final fallback.
			}
		}

		setupProviderControls() {
			if (!config.providerChoiceEnabled) {
				return;
			}

			this.providerControls.hidden = false;
			this.providerSelect.innerHTML = '';
			[
				{ label: 'OpenAI', value: 'openai' },
				{ label: 'Grok Imagine', value: 'xai' },
				{ label: 'Google Nano Banana', value: 'google' },
			].forEach((option) => {
				this.providerSelect.appendChild(createOption(option));
			});

			this.providerSelect.value = this.selectedProvider;
			this.providerSelect.addEventListener('change', () => {
				this.selectedProvider = this.providerSelect.value;
				this.selectedModel = this.getInitialModel(this.selectedProvider);
				this.populateModelSelect();
				this.persistProviderPreference();
			});
			this.modelSelect.addEventListener('change', () => {
				this.selectedModel = this.modelSelect.value;
				this.persistProviderPreference();
			});
			this.populateModelSelect();
		}

		populateModelSelect() {
			this.modelSelect.innerHTML = '';
			const options = this.getModelOptions(this.selectedProvider);
			options.forEach((option) => {
				this.modelSelect.appendChild(createOption(option));
			});
			this.modelSelect.value = options.some((option) => option.value === this.selectedModel)
				? this.selectedModel
				: options[0]?.value || '';
			this.selectedModel = this.modelSelect.value;
		}

		async restoreStatus() {
			try {
				const response = await this.request('status', {
					assetId: this.assetId,
				});

				if (['queued', 'running', 'pending'].includes(response.status) && response.token) {
					this.token = response.token;
					this.jobId = response.jobId || '';
					this.setBusy(true, response.progressLabel || 'Queued');
					this.poll();
					return;
				}

				if (response.status === 'complete' && (response.enhancedUrl || response.imageUrl || response.assetUrl || response.url)) {
					this.applyPreview(response);
				}
			} catch (error) {
				this.showError(error);
			}
		}

		async enhance() {
			this.clearError();
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.enhancedUrl = '';
			this.setPreviewMode(false);
			this.setBusy(true, 'Queued...');

			try {
				const payload = {
					assetId: this.assetId,
				};
				if (this.uploadRepair) {
					payload.uploadRepairToken = this.repairToken;
				}
				if (config.providerChoiceEnabled) {
					payload.imageEnhancementProvider = this.selectedProvider;
					payload.imageEnhancementModel = this.selectedModel;
					this.persistProviderPreference();
				}

				const response = await this.request('enhance', payload);
				this.token = response.token || '';
				this.jobId = response.jobId || '';

				if (response.queued || this.token) {
					this.poll();
					return;
				}

				this.applyPreview(response);
			} catch (error) {
				this.setBusy(false);
				this.showError(error);
			}
		}

		async poll() {
			window.clearTimeout(this.pollTimer);

			try {
				const response = await this.request('status', {
					assetId: this.assetId,
					token: this.token,
					jobId: this.jobId,
					uploadRepairToken: this.repairToken,
				});

				if (response.status === 'complete') {
					this.applyPreview(response);
					return;
				}

				if (response.status === 'failed') {
					throw new Error(response.message || response.previousError || 'Enhancement failed.');
				}

				if (response.status === 'canceled') {
					this.setBusy(false);
					this.setStatus('Canceled', false);
					return;
				}

				const label = response.status === 'running'
					? 'Running'
					: (response.progressLabel || 'Queued');
				this.setBusy(true, label, response.status === 'running');
				this.pollTimer = window.setTimeout(() => this.poll(), 1500);
			} catch (error) {
				this.setBusy(false);
				this.showError(error);
			}
		}

		async cancel() {
			if (!this.token) {
				this.setBusy(false);
				return;
			}

			this.clearError();
			this.setBusy(true, 'Canceling...');

			try {
				await this.request('cancel', {
					assetId: this.assetId,
					token: this.token,
					jobId: this.jobId,
					uploadRepairToken: this.repairToken,
				});
				window.clearTimeout(this.pollTimer);
				this.setBusy(false);
				this.setStatus('Canceled', false);
			} catch (error) {
				this.setBusy(false);
				this.showError(error);
			}
		}

		async keep() {
			if (!this.previewId) {
				this.showError(new Error('Enhanced preview asset not found.'));
				return;
			}

			this.clearError();
			this.setPreviewProcessing(true, 'Saving replacement...');

			try {
				const response = await this.request('keep', {
					assetId: this.assetId,
					previewId: this.previewId,
					token: this.token,
					previewUrl: this.enhancedUrl,
					uploadRepairToken: this.repairToken,
				});
				if (this.uploadRepair) {
					const finalized = await this.request('uploadFinalize', {
						repairToken: this.repairToken,
					});
					if (!finalized.complete) {
						this.previewId = '';
						this.enhancedUrl = '';
						this.updateUploadRepairDetails(finalized);
						this.refreshUploadRepairPreview();
						this.setPreviewMode(false);
						throw new Error(finalized.message || 'The enhanced image still does not meet every field requirement.');
					}

					await this.completeUploadRepair(finalized, 'Enhanced image added to the field.');
					return;
				}
				const imageUrl = response.imageUrl || this.enhancedUrl;
				refreshAssetFieldImage(this.assetId, withCacheBuster(imageUrl), this.card);
				if (window.Craft?.cp) {
					Craft.cp.displayNotice('Enhanced image saved.');
				}
				this.close();
			} catch (error) {
				if (this.uploadRepair && !this.previewId) {
					this.setRepairProcessing(false);
				} else {
					this.setPreviewProcessing(false);
				}
				this.showError(error);
			}
		}

		async discard() {
			if (!this.previewId) {
				this.close();
				return;
			}

			this.clearError();
			this.setPreviewProcessing(true, 'Discarding...');

			try {
				await this.request('discard', {
					assetId: this.assetId,
					previewId: this.previewId,
					token: this.token,
					uploadRepairToken: this.repairToken,
				});
				if (this.uploadRepair) {
					this.previewId = '';
					this.enhancedUrl = '';
					this.setPreviewMode(false);
					this.setStatus('', false);
					return;
				}
				this.close();
			} catch (error) {
				this.setPreviewProcessing(false);
				this.showError(error);
			}
		}

		applyPreview(response) {
			const enhancedUrl = response.enhancedUrl || response.imageUrl || response.assetUrl || response.url;
			if (!enhancedUrl) {
				throw new Error('The enhancement response did not include an enhanced image URL.');
			}

			this.previewId = response.previewId || response.previewToken || response.enhancedAssetId || response.tempAssetId || '';
			this.token = response.token || this.token;
			this.enhancedUrl = enhancedUrl;
			this.enhancedImage.src = enhancedUrl;
			this.setBusy(false);
			this.setPreviewMode(true);
			this.updateComparison();
		}

		setPreviewMode(enabled) {
			this.single.hidden = enabled;
			this.compare.hidden = !enabled;
			this.setActionState(enabled ? 'preview' : 'idle');
		}

		setBusy(isBusy, label = '', includeCounter = false) {
			this.enhanceButton.disabled = isBusy;
			this.keepButton.disabled = isBusy;
			this.discardButton.disabled = isBusy;
			this.cancelButton.disabled = false;
			this.closeButton.disabled = isBusy;
			this.setActionState(isBusy ? 'busy' : 'idle');
			this.setStatus(label, isBusy, includeCounter);
		}

		setRepairProcessing(isProcessing, label = '') {
			this.closeButton.disabled = isProcessing;
			this.localRepairButton.disabled = isProcessing || !this.uploadRepair?.repairable;
			this.enhanceButton.disabled = isProcessing;
			this.cancelButton.disabled = true;
			this.keepButton.disabled = true;
			this.discardButton.disabled = true;
			this.discardUploadButton.disabled = isProcessing;
			this.setActionState(isProcessing ? 'processing' : 'idle');
			this.setStatus(label, isProcessing);
		}

		setPreviewProcessing(isProcessing, label = '') {
			this.closeButton.disabled = isProcessing;
			this.enhanceButton.disabled = true;
			this.cancelButton.disabled = true;
			this.keepButton.disabled = isProcessing;
			this.discardButton.disabled = isProcessing;
			this.setActionState('preview');
			this.setStatus(label, isProcessing);
		}

		setActionState(state) {
			if (this.uploadRepair) {
				this.toggleButton(this.localRepairButton, state !== 'idle');
				this.toggleButton(this.enhanceButton, state !== 'idle');
				this.toggleButton(this.cancelButton, state !== 'busy');
				this.toggleButton(this.keepButton, state !== 'preview');
				this.toggleButton(this.discardButton, state !== 'preview');
				this.toggleButton(this.discardUploadButton, state !== 'idle');
				return;
			}

			this.toggleButton(this.enhanceButton, state !== 'idle');
			this.toggleButton(this.cancelButton, state !== 'busy');
			this.toggleButton(this.keepButton, state !== 'preview');
			this.toggleButton(this.discardButton, state !== 'preview');
		}

		toggleButton(button, isHidden) {
			button.classList.toggle('image-enhancer-cp-is-hidden', isHidden);
			button.hidden = isHidden;
			button.setAttribute('aria-hidden', isHidden ? 'true' : 'false');
		}

		setStatus(label, isLoading = false, includeCounter = false) {
			window.clearInterval(this.statusTickTimer);

			if (!label) {
				this.status.hidden = true;
				this.statusText.textContent = '';
				return;
			}

			this.status.hidden = false;
			this.status.classList.toggle('is-loading', isLoading);

			if (!includeCounter) {
				this.statusText.textContent = label;
				return;
			}

			this.statusStartedAt = this.statusStartedAt || Date.now();
			const update = () => {
				const seconds = Math.max(0, Math.floor((Date.now() - this.statusStartedAt) / 1000));
				this.statusText.textContent = `${label} (${seconds}s)`;
			};
			update();
			this.statusTickTimer = window.setInterval(update, 1000);
		}

		updateComparison() {
			const value = this.range.value || 50;
			this.enhancedWrap.style.clipPath = `inset(0 ${100 - Number(value)}% 0 0)`;
			this.divider.style.left = `${value}%`;
		}

		async request(action, payload) {
			const route = config.routes[action];
			if (!route) {
				throw new Error(`Missing action route for ${action}.`);
			}
			if (!window.Craft?.sendActionRequest) {
				throw new Error('Craft action requests are unavailable.');
			}

			const response = await Craft.sendActionRequest('POST', route, {
				data: payload,
			});
			const data = response.data || response;
			if (data.success === false) {
				throw new Error(data.message || 'Image enhancement request failed.');
			}

			return data;
		}

		showError(error) {
			const message = error instanceof Error ? error.message : 'Image enhancement failed.';
			this.error.textContent = message;
			this.error.hidden = false;
			if (window.Craft?.cp) {
				Craft.cp.displayError(message);
			}
		}

		clearError() {
			this.error.textContent = '';
			this.error.hidden = true;
		}

		close() {
			window.clearTimeout(this.pollTimer);
			window.clearInterval(this.statusTickTimer);

			if (this.modal?.hide) {
				this.modal.hide();
				return;
			}

			this.destroy();
		}

		destroy() {
			if (this.destroyed) {
				return;
			}
			this.destroyed = true;
			void this.cleanupPendingUpload();
			window.clearTimeout(this.pollTimer);
			window.clearInterval(this.statusTickTimer);
			this.root?.remove();
			if (typeof this.onDestroyed === 'function') {
				this.onDestroyed();
			}
		}

		getInitialProvider() {
			const preference = getProviderPreference();
			if (config.providerChoiceEnabled && preference.provider && config.modelOptions[preference.provider]) {
				return preference.provider;
			}
			if (config.imageEnhancementProvider && config.imageEnhancementProvider !== 'frontend') {
				return config.imageEnhancementProvider;
			}

			return 'openai';
		}

		getInitialModel(provider) {
			const preference = getProviderPreference();
			const options = this.getModelOptions(provider);
			if (
				config.providerChoiceEnabled &&
				preference.provider === provider &&
				options.some((option) => option.value === preference.model)
			) {
				return preference.model;
			}

			const configuredModel = provider === 'xai'
				? config.xAiImageEnhancementModel
				: (provider === 'google' ? config.googleImageEnhancementModel : config.imageEnhancementModel);

			return options.some((option) => option.value === configuredModel)
				? configuredModel
				: options[0]?.value || '';
		}

		getModelOptions(provider) {
			return normalizeOptions(config.modelOptions[provider] || []);
		}

		persistProviderPreference() {
			try {
				window.localStorage?.setItem(providerStorageKey, JSON.stringify({
					provider: this.selectedProvider,
					model: this.selectedModel,
				}));
			} catch (error) {
				// Ignore storage errors in the CP.
			}
		}
	}

	async function selectRepairedAsset(input, assetId) {
		if (!input?.canAddMoreElements?.()) {
			throw new Error('The field limit has been reached, so the repaired image could not be selected.');
		}

		const viewMode = input.settings.viewMode;
		const craftMajorVersion = Number.parseInt(String(config.craftMajorVersion || 4), 10);
		if (craftMajorVersion === 4) {
			const response = await Craft.sendActionRequest('POST', 'elements/get-element-html', {
				data: {
					elementId: assetId,
					siteId: input.settings.criteria?.siteId,
					thumbSize: viewMode,
				},
			});
			const element = window.jQuery(response.data.html);
			await Craft.appendHeadHtml(response.data.headHtml);
			input.selectUploadedFile(Craft.getElementInfo(element));
			input.$container.trigger('change');
			Craft.cp?.setEdited?.(true);
			scheduleScanBurst();
			return;
		}

		const response = await Craft.sendActionRequest('POST', 'app/render-elements', {
			data: {
				elements: [
					{
						type: 'craft\\elements\\Asset',
						id: assetId,
						siteId: input.settings.criteria?.siteId,
						instances: [
							{
								context: 'field',
								ui: ['list', 'list-inline', 'large', 'thumbs'].includes(viewMode) ? 'chip' : 'card',
								size: ['large', 'thumbs'].includes(viewMode) ? 'large' : 'small',
								showActionMenu: input.settings.showActionMenu,
							},
						],
					},
				],
			},
		});

		const elementHtml = response.data?.elements?.[assetId]?.[0];
		if (!elementHtml) {
			throw new Error('Craft could not render the repaired asset.');
		}

		const elementInfo = Craft.getElementInfo(elementHtml);
		await input.selectElements([elementInfo]);
		await Craft.appendHeadHtml(response.data.headHtml);
		await Craft.appendBodyHtml(response.data.bodyHtml);
		input.$container.trigger('change');
		Craft.cp?.setEdited?.(true);
		scheduleScanBurst();
	}

	function refreshAssetFieldImage(assetId, imageUrl, sourceCard) {
		if (!assetId || !imageUrl) {
			return;
		}

		const cards = getAssetCardsForId(assetId);
		if (sourceCard) {
			cards.add(sourceCard);
		}

		cards.forEach((card) => updateCardImage(card, imageUrl));
		markAssetFieldsUpdated(cards, assetId, imageUrl);
		preloadImage(imageUrl);
	}

	function getAssetCardsForId(assetId) {
		const cards = new Set();
		const selector = [
			`.element[data-id="${assetId}"]`,
			`.element-card[data-id="${assetId}"]`,
			`[data-asset-id="${assetId}"]`,
			`[data-id="${assetId}"][data-type*="Asset"]`,
		].join(',');

		document.querySelectorAll(selector).forEach((card) => {
			cards.add(getAssetCard(card));
		});

		document.querySelectorAll(`input[type="hidden"][value="${assetId}"]`).forEach((input) => {
			const card = input.closest('.element[data-id], .element-card[data-id], [data-asset-id]');
			if (card) {
				cards.add(card);
			}
		});

		return cards;
	}

	function updateCardImage(card, imageUrl) {
		if (!card || !imageUrl) {
			return;
		}

		const oldUrl = getImageUrl(card);

		card.querySelectorAll('img').forEach((image) => {
			image.removeAttribute('srcset');
			image.removeAttribute('data-srcset');
			image.removeAttribute('data-src');
			image.removeAttribute('data-lazy-src');
			image.removeAttribute('data-lazy-srcset');
			image.dataset.src = imageUrl;
			image.src = imageUrl;
		});

		card.querySelectorAll('source').forEach((source) => {
			source.srcset = imageUrl;
			source.removeAttribute('data-srcset');
		});

		Array.from(card.querySelectorAll('*')).forEach((node) => {
			replaceUrlAttributes(node, oldUrl, imageUrl);
		});
		replaceUrlAttributes(card, oldUrl, imageUrl);

		[card, ...Array.from(card.querySelectorAll('*'))].forEach((node) => {
			const style = window.getComputedStyle(node);
			if (style.backgroundImage && style.backgroundImage !== 'none') {
				node.style.backgroundImage = `url("${imageUrl}")`;
			}
		});

		const button = card.querySelector('.image-enhancer-cp-field-button');
		if (button) {
			button.dataset.imageEnhancerCpOriginalUrl = imageUrl;
		}
	}

	function replaceUrlAttributes(node, oldUrl, imageUrl) {
		if (!node || !oldUrl) {
			return;
		}

		[
			'src',
			'srcset',
			'data-src',
			'data-srcset',
			'data-url',
			'data-image-url',
			'data-thumb-url',
			'data-thumbnail-url',
			'style',
		].forEach((attribute) => {
			const value = node.getAttribute?.(attribute);
			if (value && value.includes(oldUrl)) {
				node.setAttribute(attribute, value.split(oldUrl).join(imageUrl));
			}
		});
	}

	function markAssetFieldsUpdated(cards, assetId, imageUrl) {
		cards.forEach((card) => {
			const field = card.closest('.field');
			if (!field) {
				return;
			}

			field.querySelectorAll(`input[type="hidden"][value="${assetId}"]`).forEach((input) => {
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
			});

			field.dispatchEvent(new CustomEvent('imageenhancer:asset-updated', {
				bubbles: true,
				detail: {
					assetId,
					imageUrl,
				},
			}));
		});

		if (window.Craft?.cp?.setEdited) {
			Craft.cp.setEdited(true);
		}
	}

	function preloadImage(imageUrl) {
		const image = new Image();
		image.src = imageUrl;
	}

	function withCacheBuster(url) {
		if (!url) {
			return '';
		}

		return `${url}${url.includes('?') ? '&' : '?'}v=${Date.now()}`;
	}

	function createOption(option) {
		const element = document.createElement('option');
		element.value = option.value;
		element.textContent = option.label || option.value;

		return element;
	}

	function normalizeOptions(options) {
		if (!Array.isArray(options)) {
			return [];
		}

		return options
			.map((option) => {
				if (typeof option === 'string') {
					return { label: option, value: option };
				}

				return {
					label: option.label || option.value,
					value: option.value,
				};
			})
			.filter((option) => option.value);
	}

	function getProviderPreference() {
		try {
			return JSON.parse(window.localStorage?.getItem(providerStorageKey) || '{}') || {};
		} catch (error) {
			return {};
		}
	}

	function mergeConfig(base, override) {
		return {
			...base,
			...override,
			routes: {
				...base.routes,
				...(override.routes || {}),
			},
			modelOptions: {
				...base.modelOptions,
				...(override.modelOptions || {}),
			},
		};
	}

	ready(init);
})();
