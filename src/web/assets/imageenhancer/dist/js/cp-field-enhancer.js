/**
 * Image Enhancer: CP asset field integration.
 *
 * Hand-authored source. There is no build step for this asset bundle: this file
 * is served as-is from dist/ by ImageEnhancerAsset, so edit it directly and keep
 * it valid browser JavaScript (no imports, no transpile-only syntax).
 *
 * Never insert server-provided or user-provided text with innerHTML; use
 * textContent (or Craft.escapeHtml when building markup is unavoidable).
 */
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
			createVideo: 'craft-image-enhancer/article-image/create-video',
			blurFaces: 'craft-image-enhancer/article-image/blur-faces',
			status: 'craft-image-enhancer/article-image/status',
			cancel: 'craft-image-enhancer/article-image/cancel',
			reset: 'craft-image-enhancer/article-image/reset',
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
		videoProviderOptions: [
			{ label: 'Google', value: 'google' },
			{ label: 'Grok Imagine (xAI)', value: 'xai' },
		],
		videoModelOptions: {
			google: [
				{ label: 'Gemini Omni 1.1 Flash', value: 'gemini-omni-1.1-flash' },
			],
			xai: [
				{ label: 'Grok Imagine Video 1.5', value: 'grok-imagine-video-1.5' },
				{ label: 'Grok Imagine Video', value: 'grok-imagine-video' },
			],
		},
	};
	const config = mergeConfig(defaults, window.ImageEnhancerCp || {});
	const providerStorageKey = 'craft-image-enhancer:cp:provider-preference';
	const videoProviderStorageKey = 'craft-image-enhancer:cp:video-provider-preference';
	const scanSelector = [
		'.field .element',
		'.field .element-card',
		'.field .element[data-id]',
		'.field .element-card[data-id]',
		'.field .asset[data-id]',
		'.field [data-asset-id]',
		'.field [data-type*="Asset"][data-id]',
	].join(',');
	// Added nodes matching this selector (or containing a match) warrant a rescan.
	const relevantMutationSelector = [
		scanSelector,
		'.elementselect',
		'.field',
	].join(',');
	// Mutations inside these containers are the plugin's own UI and never need a rescan.
	const ignoredMutationSelector = [
		'.image-enhancer-cp-modal',
		'.image-enhancer-cp-field-action',
	].join(',');
	const scanDebounceMs = 50;
	const scanMaxWaitMs = 400;
	const scanFollowUpDelays = [250, 750];
	let observer = null;
	let scanDebounceTimer = null;
	let scanFollowUpTimers = [];
	let scanPendingSince = 0;
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
		observer = new MutationObserver((mutations) => {
			if (mutations.some(isRelevantMutation)) {
				scheduleScanBurst();
			}
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

	function isRelevantMutation(mutation) {
		if (mutation.type !== 'childList' || mutation.addedNodes.length === 0) {
			return false;
		}

		const target = mutation.target;
		if (!(target instanceof Element) || isIgnoredMutationTarget(target)) {
			return false;
		}

		return Array.from(mutation.addedNodes).some(isRelevantAddedNode);
	}

	function isIgnoredMutationTarget(element) {
		return Boolean(element.isContentEditable || element.closest(ignoredMutationSelector));
	}

	function isRelevantAddedNode(node) {
		if (!(node instanceof Element) || node.matches(ignoredMutationSelector)) {
			return false;
		}

		if (node.matches(relevantMutationSelector) || node.querySelector(relevantMutationSelector)) {
			return true;
		}

		// Lazy-loaded thumbnails can arrive after the asset card itself.
		return node.tagName === 'IMG' && Boolean(node.closest('.field'));
	}

	/**
	 * Debounced full-document scan, followed by two settle-time rescans.
	 *
	 * A new trigger cancels any pending follow-up scans, so sustained DOM churn
	 * never stacks overlapping scans. The debounce is capped by scanMaxWaitMs so
	 * continuous mutations cannot starve the scan either.
	 */
	function scheduleScanBurst() {
		cancelFollowUpScans();
		window.clearTimeout(scanDebounceTimer);

		const now = Date.now();
		scanPendingSince = scanPendingSince || now;
		const delay = Math.min(scanDebounceMs, Math.max(0, scanPendingSince + scanMaxWaitMs - now));
		scanDebounceTimer = window.setTimeout(runScanBurst, delay);
	}

	function runScanBurst() {
		scanDebounceTimer = null;
		scanPendingSince = 0;
		runScan();
		scanFollowUpTimers = scanFollowUpDelays.map((delay) => window.setTimeout(runScan, delay));
	}

	function cancelFollowUpScans() {
		scanFollowUpTimers.forEach((timer) => window.clearTimeout(timer));
		scanFollowUpTimers = [];
	}

	function runScan() {
		scanAssetFields(document);
		scanUploadAssistantInputs(document);
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
			triggerElement: getUploadTriggerElement(input),
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

	function getUploadTriggerElement(input) {
		const candidates = [input?.$uploadBtn, input?.$addElementBtn];
		const $button = candidates.find(($candidate) => $candidate && $candidate.length > 0);

		return $button ? $button.get(0) : null;
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
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn small image-enhancer-cp-field-button';
		button.textContent = 'Enhance';
		action.appendChild(button);
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
			triggerElement: button,
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
		constructor({ assetId, originalUrl, card, assetInput = null, uploadRepair = null, onDestroyed = null, triggerElement = null }) {
			this.assetId = assetId;
			this.originalUrl = originalUrl;
			this.card = card;
			this.triggerElement = triggerElement;
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
			this.operation = 'enhance';
			this.isCustomEditMode = false;
			this.isVideoMode = false;
			this.isManualBlurMode = false;
			this.manualBlurRegions = [];
			this.currentManualBlurRegion = null;
			this.manualBlurDrawStart = null;
			this.manualBlurRegionSequence = 0;
			this.pollTimer = null;
			// Bumped whenever an operation starts, is canceled, or the modal closes;
			// responses captured under an older value are stale and ignored.
			this.operationSequence = 0;
			// Bumped per status request so overlapping polls only honor the latest.
			this.pollRequestSequence = 0;
			this.statusStartedAt = 0;
			this.statusTickTimer = null;
			this.videoDownloadUrl = '';
			this.manualBlurResizeHandler = () => this.renderManualBlurRegions();
			this.selectedProvider = this.getInitialProvider();
			this.selectedModel = this.getInitialModel(this.selectedProvider);
			this.selectedVideoProvider = this.getInitialVideoProvider();
			this.selectedVideoModel = this.getInitialVideoModel(this.selectedVideoProvider);
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
				const modalSettings = {
					onHide: () => this.destroy(),
				};
				// Return focus to the button that opened the modal once it closes.
				if (this.triggerElement) {
					modalSettings.triggerElement = window.jQuery(this.triggerElement);
				}
				this.modal = new Garnish.Modal(this.$root, modalSettings);
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
				: 'Enhance, edit, blur, or animate the image. Image previews can replace the asset; generated videos are download only.';
			const root = document.createElement('div');
			root.className = 'modal image-enhancer-cp-modal';
			root.innerHTML = [
				'<div class="image-enhancer-cp-shell">',
				'  <div class="image-enhancer-cp-body">',
				'  <div class="image-enhancer-cp-header">',
				'    <div>',
				'      <h2 data-title></h2>',
				'      <p data-description></p>',
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
				'  <div class="image-enhancer-cp-custom-edit" data-custom-edit-panel hidden>',
				'    <label for="image-enhancer-custom-prompt">Custom edit instructions</label>',
				'    <textarea id="image-enhancer-custom-prompt" class="text fullwidth" rows="3" maxlength="4000" placeholder="For example: Flip the image horizontally, or remove all persons." data-custom-prompt></textarea>',
				'    <p>Only the requested edit is applied. The original dimensions and unrelated content are preserved where possible.</p>',
				'  </div>',
				'  <div class="image-enhancer-cp-video" data-video-panel hidden>',
				'    <div class="image-enhancer-cp-video-provider">',
				'      <label><span>Provider</span><select data-video-provider></select></label>',
				'      <label><span>Model</span><select data-video-model></select></label>',
				'    </div>',
				'    <label>Video instructions <span>(optional)</span></label>',
				'    <textarea class="text fullwidth" rows="3" maxlength="4000" placeholder="For example: Slowly push the camera in while the subject looks toward the camera." data-video-prompt></textarea>',
				'    <p>Creates a downloadable 720p MP4 with the selected provider. The image remains unchanged.</p>',
				'  </div>',
				'  <div class="image-enhancer-cp-stage">',
				'    <div class="image-enhancer-cp-single" data-single>',
				'      <img data-original alt="">',
				'      <div class="image-enhancer-cp-manual-blur-layer" data-manual-blur-layer title="Drag around each face or area to blur" hidden></div>',
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
				'  </div>',
				'  <div class="image-enhancer-cp-footer">',
				'    <div class="image-enhancer-cp-feedback">',
				'      <div class="image-enhancer-cp-status" data-status aria-live="polite" hidden>',
				'        <span class="image-enhancer-cp-spinner" aria-hidden="true"></span>',
				'        <span data-status-text></span>',
				'      </div>',
				'      <div class="image-enhancer-cp-error" data-error role="alert" hidden></div>',
				'    </div>',
				'    <div class="image-enhancer-cp-actions">',
				'      <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-local-repair>Resize locally</button>',
				'      <button type="button" class="btn submit" data-enhance>Enhance</button>',
				'      <button type="button" class="btn" data-open-custom-edit>Custom edit</button>',
				'      <button type="button" class="btn" data-blur-faces>Blur faces</button>',
				'      <button type="button" class="btn" data-custom-blur>Custom blur</button>',
				'      <button type="button" class="btn" data-open-video>Create Video</button>',
				'      <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-apply-custom-edit>Apply edit</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-cancel-custom-edit>Cancel</button>',
				'      <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-create-video>Generate video</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-cancel-video>Cancel</button>',
				'      <a class="btn submit image-enhancer-cp-is-hidden" href="#" download data-download-video>Download video</a>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-done-video>Done</button>',
				'      <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-apply-custom-blur>Apply blur</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-undo-custom-blur>Undo</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-cancel-custom-blur>Cancel</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-cancel>Cancel</button>',
				'      <button type="button" class="btn submit image-enhancer-cp-is-hidden" data-keep>Save replacement</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-discard>Discard</button>',
				'      <button type="button" class="btn image-enhancer-cp-is-hidden" data-discard-upload>Discard upload</button>',
				'    </div>',
				'  </div>',
				'</div>',
			].join('');

			this.root = root;
			root.querySelector('[data-title]').textContent = title;
			root.querySelector('[data-description]').textContent = description;
			this.originalImage =root.querySelector('[data-original]');
			this.compareOriginalImage = root.querySelector('[data-compare-original]');
			this.enhancedImage = root.querySelector('[data-enhanced]');
			this.single = root.querySelector('[data-single]');
			this.manualBlurLayer = root.querySelector('[data-manual-blur-layer]');
			this.compare = root.querySelector('[data-compare]');
			this.enhancedWrap = root.querySelector('[data-enhanced-wrap]');
			this.divider = root.querySelector('[data-divider]');
			this.range = root.querySelector('[data-range]');
			this.status = root.querySelector('[data-status]');
			this.statusText = root.querySelector('[data-status-text]');
			this.error = root.querySelector('[data-error]');
			this.enhanceButton = root.querySelector('[data-enhance]');
			this.customEditButton = root.querySelector('[data-open-custom-edit]');
			this.applyCustomEditButton = root.querySelector('[data-apply-custom-edit]');
			this.cancelCustomEditButton = root.querySelector('[data-cancel-custom-edit]');
			this.customEditPanel = root.querySelector('[data-custom-edit-panel]');
			this.customPromptInput = root.querySelector('[data-custom-prompt]');
			this.openVideoButton = root.querySelector('[data-open-video]');
			this.createVideoButton = root.querySelector('[data-create-video]');
			this.cancelVideoButton = root.querySelector('[data-cancel-video]');
			this.downloadVideoButton = root.querySelector('[data-download-video]');
			this.doneVideoButton = root.querySelector('[data-done-video]');
			this.videoPanel = root.querySelector('[data-video-panel]');
			this.videoPromptInput = root.querySelector('[data-video-prompt]');
			this.videoProviderSelect = root.querySelector('[data-video-provider]');
			this.videoModelSelect = root.querySelector('[data-video-model]');
			this.blurFacesButton = root.querySelector('[data-blur-faces]');
			this.customBlurButton = root.querySelector('[data-custom-blur]');
			this.applyCustomBlurButton = root.querySelector('[data-apply-custom-blur]');
			this.undoCustomBlurButton = root.querySelector('[data-undo-custom-blur]');
			this.cancelCustomBlurButton = root.querySelector('[data-cancel-custom-blur]');
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

			this.range.addEventListener('input', () => this.updateComparison());
			root.querySelector('[data-local-repair]').addEventListener('click', () => this.repairLocally());
			root.querySelector('[data-enhance]').addEventListener('click', () => this.enhance());
			root.querySelector('[data-open-custom-edit]').addEventListener('click', () => this.startCustomEditMode());
			root.querySelector('[data-apply-custom-edit]').addEventListener('click', () => this.applyCustomEdit());
			root.querySelector('[data-cancel-custom-edit]').addEventListener('click', () => this.cancelCustomEditMode());
			root.querySelector('[data-open-video]').addEventListener('click', () => this.startVideoMode());
			root.querySelector('[data-create-video]').addEventListener('click', () => this.createVideo());
			root.querySelector('[data-cancel-video]').addEventListener('click', () => this.cancelVideoMode());
			root.querySelector('[data-done-video]').addEventListener('click', () => this.finishVideoMode());
			root.querySelector('[data-blur-faces]').addEventListener('click', () => this.blurFaces());
			root.querySelector('[data-custom-blur]').addEventListener('click', () => this.startManualBlurMode());
			root.querySelector('[data-apply-custom-blur]').addEventListener('click', () => this.applyManualBlur());
			root.querySelector('[data-undo-custom-blur]').addEventListener('click', () => this.undoManualBlurRegion());
			root.querySelector('[data-cancel-custom-blur]').addEventListener('click', () => this.cancelManualBlurMode());
			root.querySelector('[data-cancel]').addEventListener('click', () => this.cancel());
			root.querySelector('[data-keep]').addEventListener('click', () => this.keep());
			root.querySelector('[data-discard]').addEventListener('click', () => this.discard());
			root.querySelector('[data-discard-upload]').addEventListener('click', () => this.discardUpload());
			this.closeButton.addEventListener('click', () => this.requestClose());
			this.originalImage.addEventListener('load', this.manualBlurResizeHandler);
			this.manualBlurLayer.addEventListener('pointerdown', (event) => this.startManualBlurDraw(event));
			this.manualBlurLayer.addEventListener('pointermove', (event) => this.moveManualBlurDraw(event));
			this.manualBlurLayer.addEventListener('pointerup', (event) => this.finishManualBlurDraw(event));
			this.manualBlurLayer.addEventListener('pointercancel', (event) => this.cancelManualBlurDraw(event));
			this.manualBlurLayer.addEventListener('pointerleave', (event) => this.handleManualBlurPointerLeave(event));
			this.customPromptInput.addEventListener('keydown', (event) => {
				if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
					event.preventDefault();
					this.applyCustomEdit();
				}
			});
			this.videoPromptInput.addEventListener('keydown', (event) => {
				if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
					event.preventDefault();
					this.createVideo();
				}
			});
			window.addEventListener('resize', this.manualBlurResizeHandler);
			this.originalImage.src = this.originalUrl;
			this.compareOriginalImage.src = this.originalUrl;

			if (this.uploadRepair) {
				this.enhanceButton.textContent = 'Resize & Enhance';
				this.keepButton.textContent = 'Use enhanced image';
				this.discardButton.textContent = 'Back';
				this.updateUploadRepairDetails(this.uploadRepair);
			}

			this.setupProviderControls();
			this.setupVideoProviderControls();
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
			this.providerSelect.replaceChildren();
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
			this.modelSelect.replaceChildren();
			const options = this.getModelOptions(this.selectedProvider);
			options.forEach((option) => {
				this.modelSelect.appendChild(createOption(option));
			});
			this.modelSelect.value = options.some((option) => option.value === this.selectedModel)
				? this.selectedModel
				: options[0]?.value || '';
			this.selectedModel = this.modelSelect.value;
		}

		setupVideoProviderControls() {
			const options = this.getVideoProviderOptions();
			this.videoProviderSelect.replaceChildren();
			options.forEach((option) => {
				this.videoProviderSelect.appendChild(createOption(option));
			});

			if (!options.some((option) => option.value === this.selectedVideoProvider)) {
				this.selectedVideoProvider = options[0]?.value || 'google';
				this.selectedVideoModel = this.getInitialVideoModel(this.selectedVideoProvider);
			}

			this.videoProviderSelect.value = this.selectedVideoProvider;
			this.videoProviderSelect.addEventListener('change', () => {
				this.selectedVideoProvider = this.videoProviderSelect.value;
				this.selectedVideoModel = this.getInitialVideoModel(this.selectedVideoProvider);
				this.populateVideoModelSelect();
				this.persistVideoProviderPreference();
			});
			this.videoModelSelect.addEventListener('change', () => {
				this.selectedVideoModel = this.videoModelSelect.value;
				this.persistVideoProviderPreference();
			});
			this.populateVideoModelSelect();
		}

		populateVideoModelSelect() {
			this.videoModelSelect.replaceChildren();
			const options = this.getVideoModelOptions(this.selectedVideoProvider);
			options.forEach((option) => {
				this.videoModelSelect.appendChild(createOption(option));
			});
			this.videoModelSelect.value = options.some((option) => option.value === this.selectedVideoModel)
				? this.selectedVideoModel
				: options[0]?.value || '';
			this.selectedVideoModel = this.videoModelSelect.value;
		}

		async restoreStatus() {
			const operationSequence = this.operationSequence;
			try {
				const response = await this.request('status', {
					assetId: this.assetId,
				});
				// The editor may have started another operation while this was in flight.
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}

				if (['queued', 'running', 'pending'].includes(response.status) && response.token) {
					this.token = response.token;
					this.jobId = response.jobId || '';
					this.operation = this.resolveOperation(response);
					if (this.operation === 'createVideo') {
						this.syncVideoSelection(response);
						this.providerControls.hidden = true;
					}
					this.setBusy(true, response.progressLabel || 'Queued');
					this.poll();
					return;
				}

				if (response.status === 'complete' && response.operation === 'createVideo' && response.downloadUrl) {
					this.applyVideoResult(response);
					return;
				}

				if (response.status === 'complete' && (response.enhancedUrl || response.imageUrl || response.assetUrl || response.url)) {
					this.applyPreview(response);
					return;
				}

				if (response.status === 'failed' && response.operation === 'createVideo') {
					this.token = response.token || '';
					this.jobId = response.jobId || '';
					this.operation = 'createVideo';
					this.syncVideoSelection(response);
					this.startVideoMode(true);
					this.showError(new Error(response.message || 'Video generation failed.'));
				}
			} catch (error) {
				if (this.isCurrentOperation(operationSequence)) {
					this.showError(error);
				}
			}
		}

		/**
		 * Stops any in-flight polling and marks earlier responses as stale.
		 * Call this at the start of every action that starts a new operation.
		 */
		beginOperation() {
			this.invalidateOperation();
			this.statusStartedAt = 0;

			return this.operationSequence;
		}

		invalidateOperation() {
			window.clearTimeout(this.pollTimer);
			this.pollTimer = null;
			this.operationSequence += 1;
		}

		isCurrentOperation(operationSequence) {
			return !this.destroyed && operationSequence === this.operationSequence;
		}

		async enhance() {
			const operationSequence = this.beginOperation();
			this.clearError();
			this.hideCustomEditMode(false);
			this.hideVideoMode(false);
			this.operation = 'enhance';
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
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.token = response.token || '';
				this.jobId = response.jobId || '';

				if (response.queued || this.token) {
					this.poll();
					return;
				}

				this.applyPreview(response);
			} catch (error) {
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.setBusy(false);
				this.showError(error);
			}
		}

		startCustomEditMode(preservePrompt = false) {
			if (this.uploadRepair) {
				return;
			}

			this.clearError();
			this.hideVideoMode(false);
			this.hideManualBlurMode(true, false);
			if (!preservePrompt) {
				this.customPromptInput.value = '';
			}
			this.isCustomEditMode = true;
			this.customEditPanel.hidden = false;
			this.providerControls.hidden = !config.providerChoiceEnabled;
			this.setActionState('customEdit');
			this.setStatus('', false);
			this.customPromptInput.focus();
		}

		cancelCustomEditMode() {
			if (!this.isCustomEditMode) {
				return;
			}

			this.clearError();
			this.hideCustomEditMode(true);
			this.setActionState('idle');
			this.setStatus('', false);
		}

		hideCustomEditMode(clearPrompt = false) {
			this.isCustomEditMode = false;
			this.customEditPanel.hidden = true;
			if (clearPrompt) {
				this.customPromptInput.value = '';
			}
			this.providerControls.hidden = !config.providerChoiceEnabled;
		}

		async applyCustomEdit() {
			if (this.uploadRepair) {
				return;
			}

			const customPrompt = this.customPromptInput.value.trim();
			if (!customPrompt) {
				this.showError(new Error('Enter instructions for the custom edit.'));
				this.customPromptInput.focus();
				return;
			}

			const operationSequence = this.beginOperation();
			this.clearError();
			this.operation = 'customEnhance';
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.enhancedUrl = '';
			this.hideCustomEditMode(false);
			this.setPreviewMode(false);
			this.setBusy(true, 'Queued...');

			try {
				const payload = {
					assetId: this.assetId,
					customPrompt,
				};
				if (config.providerChoiceEnabled) {
					payload.imageEnhancementProvider = this.selectedProvider;
					payload.imageEnhancementModel = this.selectedModel;
					this.persistProviderPreference();
				}

				const response = await this.request('enhance', payload);
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.token = response.token || '';
				this.jobId = response.jobId || '';

				if (response.queued || this.token) {
					this.poll();
					return;
				}

				this.applyPreview({ ...response, operation: 'customEnhance' });
			} catch (error) {
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.setBusy(false);
				this.startCustomEditMode(true);
				this.showError(error);
			}
		}

		startVideoMode(preservePrompt = false) {
			if (this.uploadRepair) {
				return;
			}

			this.clearError();
			this.operation = 'createVideo';
			this.hideCustomEditMode(false);
			this.hideManualBlurMode(true, false);
			if (!preservePrompt) {
				this.videoPromptInput.value = '';
			}
			this.isVideoMode = true;
			this.videoPanel.hidden = false;
			this.providerControls.hidden = true;
			this.setActionState('video');
			this.setStatus('', false);
			this.videoPromptInput.focus();
		}

		async cancelVideoMode() {
			if (!this.isVideoMode) {
				return;
			}

			this.clearError();
			try {
				if (this.token) {
					await this.request('reset', {
						assetId: this.assetId,
						token: this.token,
					});
				}
				this.token = '';
				this.jobId = '';
				this.hideVideoMode(true);
				this.setActionState('idle');
				this.setStatus('', false);
			} catch (error) {
				this.showError(error);
			}
		}

		hideVideoMode(clearPrompt = false, restoreProviderControls = true) {
			this.isVideoMode = false;
			this.videoPanel.hidden = true;
			if (clearPrompt) {
				this.videoPromptInput.value = '';
			}
			if (restoreProviderControls) {
				this.providerControls.hidden = !config.providerChoiceEnabled;
			}
		}

		async createVideo() {
			if (this.uploadRepair) {
				return;
			}

			const videoPrompt = this.videoPromptInput.value.trim();
			const operationSequence = this.beginOperation();
			this.clearError();
			this.operation = 'createVideo';
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.videoDownloadUrl = '';
			this.hideVideoMode(false, false);
			this.setPreviewMode(false);
			this.setBusy(true, 'Queued...');

			try {
				this.persistVideoProviderPreference();
				const response = await this.request('createVideo', {
					assetId: this.assetId,
					videoPrompt,
					videoProvider: this.selectedVideoProvider,
					videoModel: this.selectedVideoModel,
				});
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.syncVideoSelection(response);
				this.token = response.token || '';
				this.jobId = response.jobId || '';

				if (response.queued || this.token) {
					this.poll();
					return;
				}

				this.applyVideoResult(response);
			} catch (error) {
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.setBusy(false);
				this.startVideoMode(true);
				this.showError(error);
			}
		}

		applyVideoResult(response) {
			if (!response.downloadUrl) {
				throw new Error('The video response did not include a download URL.');
			}
			const downloadUrl = toSafeHttpUrl(response.downloadUrl);
			if (!downloadUrl) {
				throw new Error('The video response included an invalid download URL.');
			}

			this.operation = 'createVideo';
			this.syncVideoSelection(response);
			this.token = response.token || this.token;
			this.videoDownloadUrl = downloadUrl;
			this.downloadVideoButton.href = downloadUrl;
			if (response.videoFilename) {
				this.downloadVideoButton.setAttribute('download', response.videoFilename);
			}
			this.single.hidden = false;
			this.compare.hidden = true;
			this.hideCustomEditMode(false);
			this.hideManualBlurMode(true, false);
			this.isVideoMode = true;
			this.videoPanel.hidden = false;
			this.providerControls.hidden = true;
			this.setBusy(false);
			this.setActionState('videoReady');
			this.setStatus('Video ready to download.', false);
		}

		async finishVideoMode() {
			this.clearError();
			try {
				await this.request('reset', {
					assetId: this.assetId,
					token: this.token,
				});
				this.token = '';
				this.jobId = '';
				this.videoDownloadUrl = '';
				this.downloadVideoButton.href = '#';
				this.hideVideoMode(true);
				this.setActionState('idle');
				this.setStatus('', false);
			} catch (error) {
				this.showError(error);
			}
		}

		async blurFaces() {
			if (this.uploadRepair) {
				return;
			}

			const operationSequence = this.beginOperation();
			this.clearError();
			this.hideCustomEditMode(false);
			this.hideVideoMode(false);
			this.operation = 'blurFaces';
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.enhancedUrl = '';
			this.setPreviewMode(false);
			this.setBusy(true, 'Queued...');

			try {
				const response = await this.request('blurFaces', {
					assetId: this.assetId,
				});
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.token = response.token || '';
				this.jobId = response.jobId || '';

				if (response.queued || this.token) {
					this.poll();
					return;
				}

				this.applyPreview({ ...response, operation: 'blurFaces' });
			} catch (error) {
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.setBusy(false);
				this.showError(error);
			}
		}

		startManualBlurMode(preserveRegions = false) {
			if (this.uploadRepair) {
				return;
			}

			this.clearError();
			this.hideCustomEditMode(false);
			this.hideVideoMode(false);
			if (!preserveRegions) {
				this.resetManualBlurDrawing();
			}
			this.isManualBlurMode = true;
			this.manualBlurLayer.hidden = false;
			this.providerControls.hidden = true;
			this.setActionState('manual');
			this.setStatus('Drag around each face or area to blur. You can select multiple areas.', false);
			this.renderManualBlurRegions();
		}

		cancelManualBlurMode() {
			if (!this.isManualBlurMode) {
				return;
			}

			this.clearError();
			this.hideManualBlurMode(true);
			this.setActionState('idle');
			this.setStatus('', false);
		}

		undoManualBlurRegion() {
			if (!this.isManualBlurMode || this.manualBlurRegions.length === 0) {
				return;
			}

			this.manualBlurRegions.pop();
			this.renderManualBlurRegions();
		}

		async applyManualBlur() {
			if (!this.isManualBlurMode || this.manualBlurRegions.length === 0) {
				return;
			}

			const manualFaces = this.manualBlurRegions.map(({ x, y, width, height }) => ({
				x,
				y,
				width,
				height,
				source: 'manual',
			}));

			const operationSequence = this.beginOperation();
			this.clearError();
			this.operation = 'manualBlurFaces';
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.enhancedUrl = '';
			this.hideManualBlurMode(false, false);
			this.setBusy(true, 'Queued...');

			try {
				const response = await this.request('blurFaces', {
					assetId: this.assetId,
					manualFaces: JSON.stringify(manualFaces),
				});
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.token = response.token || '';
				this.jobId = response.jobId || '';

				if (response.queued || this.token) {
					this.poll();
					return;
				}

				this.applyPreview({
					...response,
					operation: 'manualBlurFaces',
					blurMode: 'manual',
				});
			} catch (error) {
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.setBusy(false);
				this.startManualBlurMode(true);
				this.showError(error);
			}
		}

		hideManualBlurMode(resetRegions = false, restoreProviderControls = true) {
			this.isManualBlurMode = false;
			this.manualBlurLayer.hidden = true;
			if (restoreProviderControls) {
				this.providerControls.hidden = !config.providerChoiceEnabled;
			}
			this.currentManualBlurRegion = null;
			this.manualBlurDrawStart = null;
			if (resetRegions) {
				this.resetManualBlurDrawing();
			}
		}

		resumeManualBlurMode() {
			if (this.operation !== 'manualBlurFaces' || this.manualBlurRegions.length === 0) {
				return false;
			}

			this.startManualBlurMode(true);
			return true;
		}

		/**
		 * Returns true when a status response no longer belongs to the operation
		 * that dispatched it (a new operation started, it was canceled, the modal
		 * closed, or a newer status request superseded it).
		 */
		isStalePoll(dispatch, response = null) {
			if (!this.isCurrentOperation(dispatch.operationSequence)) {
				return true;
			}
			if (dispatch.requestSequence !== this.pollRequestSequence || dispatch.token !== this.token) {
				return true;
			}

			const responseToken = typeof response?.token === 'string' ? response.token : '';

			return Boolean(responseToken && dispatch.token && responseToken !== dispatch.token);
		}

		async poll() {
			window.clearTimeout(this.pollTimer);
			this.pollTimer = null;
			if (this.destroyed) {
				return;
			}

			const dispatch = {
				operationSequence: this.operationSequence,
				requestSequence: ++this.pollRequestSequence,
				token: this.token,
			};
			let shouldResumeManualBlur = false;
			let shouldResumeCustomEdit = false;
			let shouldResumeVideo = false;

			try {
				const response = await this.request('status', {
					assetId: this.assetId,
					token: dispatch.token,
					jobId: this.jobId,
					uploadRepairToken: this.repairToken,
				});
				if (this.isStalePoll(dispatch, response)) {
					return;
				}
				this.operation = this.resolveOperation(response);
				if (this.operation === 'createVideo') {
					this.syncVideoSelection(response);
				}

				if (response.status === 'complete') {
					if (this.operation === 'createVideo') {
						this.applyVideoResult(response);
					} else {
						this.applyPreview(response);
					}
					return;
				}

				if (response.status === 'failed') {
					shouldResumeManualBlur = this.operation === 'manualBlurFaces';
					shouldResumeCustomEdit = this.operation === 'customEnhance';
					shouldResumeVideo = this.operation === 'createVideo';
					const fallback = this.isVideoOperation()
						? 'Video generation failed.'
						: (this.isBlurOperation()
						? 'Face blur failed.'
						: (this.isCustomEnhancement() ? 'Custom edit failed.' : 'Enhancement failed.'));
					throw new Error(response.message || response.previousError || fallback);
				}

				if (response.status === 'canceled') {
					this.setBusy(false);
					if (this.operation === 'customEnhance') {
						this.startCustomEditMode(true);
					} else if (this.operation === 'createVideo') {
						this.startVideoMode(true);
					} else if (!this.resumeManualBlurMode()) {
						this.setStatus('Canceled', false);
					}
					return;
				}

				const label = response.progressLabel || (response.status === 'running'
					? (this.isVideoOperation() ? 'Generating video' : (this.isBlurOperation() ? 'Blurring faces' : 'Running'))
					: 'Queued');
				this.setBusy(true, label, response.status === 'running');
				this.pollTimer = window.setTimeout(() => this.poll(), 1500);
			} catch (error) {
				if (this.isStalePoll(dispatch)) {
					return;
				}
				this.setBusy(false);
				if (shouldResumeManualBlur) {
					this.resumeManualBlurMode();
				} else if (shouldResumeCustomEdit) {
					this.startCustomEditMode(true);
				} else if (shouldResumeVideo) {
					this.startVideoMode(true);
				}
				this.showError(error);
			}
		}

		async cancel() {
			if (!this.token) {
				this.setBusy(false);
				this.resumeManualBlurMode();
				return;
			}

			const shouldResumeManualBlur = this.operation === 'manualBlurFaces';
			const shouldResumeCustomEdit = this.operation === 'customEnhance';
			const shouldResumeVideo = this.operation === 'createVideo';
			// Stop polling while canceling so a late status response can't reopen the busy state.
			this.invalidateOperation();
			const operationSequence = this.operationSequence;
			this.clearError();
			this.setBusy(true, 'Canceling...');

			try {
				await this.request('cancel', {
					assetId: this.assetId,
					token: this.token,
					jobId: this.jobId,
					uploadRepairToken: this.repairToken,
				});
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.setBusy(false);
				if (shouldResumeCustomEdit) {
					this.startCustomEditMode(true);
				} else if (shouldResumeVideo) {
					this.startVideoMode(true);
				} else if (!shouldResumeManualBlur || !this.resumeManualBlurMode()) {
					this.setStatus('Canceled', false);
				}
			} catch (error) {
				if (!this.isCurrentOperation(operationSequence)) {
					return;
				}
				this.showError(error);
				// The job may still be running; keep tracking it so the editor can retry.
				if (this.token) {
					this.poll();
					return;
				}
				this.setBusy(false);
			}
		}

		async keep() {
			if (!this.previewId) {
				this.showError(new Error('Preview asset not found.'));
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
					const notice = this.isBlurOperation()
						? 'Blurred image saved.'
						: (this.isCustomEnhancement() ? 'Custom edit saved.' : 'Enhanced image saved.');
					Craft.cp.displayNotice(notice);
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
				throw new Error('The image-processing response did not include a preview URL.');
			}

			this.previewId = response.previewId || response.previewToken || response.enhancedAssetId || response.tempAssetId || '';
			this.token = response.token || this.token;
			this.operation = this.resolveOperation(response);
			this.enhancedUrl = enhancedUrl;
			this.enhancedImage.src = enhancedUrl;
			this.hideManualBlurMode(true);
			this.hideCustomEditMode(false);
			this.hideVideoMode(false);
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
			this.customEditButton.disabled = isBusy;
			this.applyCustomEditButton.disabled = isBusy;
			this.cancelCustomEditButton.disabled = isBusy;
			this.openVideoButton.disabled = isBusy;
			this.createVideoButton.disabled = isBusy;
			this.cancelVideoButton.disabled = isBusy;
			this.doneVideoButton.disabled = isBusy;
			this.blurFacesButton.disabled = isBusy;
			this.customBlurButton.disabled = isBusy;
			this.applyCustomBlurButton.disabled = isBusy || this.manualBlurRegions.length === 0;
			this.undoCustomBlurButton.disabled = isBusy || this.manualBlurRegions.length === 0;
			this.cancelCustomBlurButton.disabled = isBusy;
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
			this.customEditButton.disabled = true;
			this.applyCustomEditButton.disabled = true;
			this.cancelCustomEditButton.disabled = true;
			this.openVideoButton.disabled = true;
			this.createVideoButton.disabled = true;
			this.cancelVideoButton.disabled = true;
			this.doneVideoButton.disabled = true;
			this.blurFacesButton.disabled = true;
			this.customBlurButton.disabled = true;
			this.applyCustomBlurButton.disabled = true;
			this.undoCustomBlurButton.disabled = true;
			this.cancelCustomBlurButton.disabled = true;
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
			this.customEditButton.disabled = true;
			this.applyCustomEditButton.disabled = true;
			this.cancelCustomEditButton.disabled = true;
			this.openVideoButton.disabled = true;
			this.createVideoButton.disabled = true;
			this.cancelVideoButton.disabled = true;
			this.doneVideoButton.disabled = true;
			this.blurFacesButton.disabled = true;
			this.customBlurButton.disabled = true;
			this.applyCustomBlurButton.disabled = true;
			this.undoCustomBlurButton.disabled = true;
			this.cancelCustomBlurButton.disabled = true;
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
				this.toggleButton(this.customEditButton, true);
				this.toggleButton(this.applyCustomEditButton, true);
				this.toggleButton(this.cancelCustomEditButton, true);
				this.toggleButton(this.openVideoButton, true);
				this.toggleButton(this.createVideoButton, true);
				this.toggleButton(this.cancelVideoButton, true);
				this.toggleButton(this.downloadVideoButton, true);
				this.toggleButton(this.doneVideoButton, true);
				this.toggleButton(this.blurFacesButton, true);
				this.toggleButton(this.customBlurButton, true);
				this.toggleButton(this.applyCustomBlurButton, true);
				this.toggleButton(this.undoCustomBlurButton, true);
				this.toggleButton(this.cancelCustomBlurButton, true);
				this.toggleButton(this.cancelButton, state !== 'busy');
				this.toggleButton(this.keepButton, state !== 'preview');
				this.toggleButton(this.discardButton, state !== 'preview');
				this.toggleButton(this.discardUploadButton, state !== 'idle');
				return;
			}

			this.toggleButton(this.enhanceButton, state !== 'idle');
			this.toggleButton(this.customEditButton, state !== 'idle');
			this.toggleButton(this.applyCustomEditButton, state !== 'customEdit');
			this.toggleButton(this.cancelCustomEditButton, state !== 'customEdit');
			this.toggleButton(this.openVideoButton, state !== 'idle');
			this.toggleButton(this.createVideoButton, state !== 'video');
			this.toggleButton(this.cancelVideoButton, state !== 'video');
			this.toggleButton(this.downloadVideoButton, state !== 'videoReady');
			this.toggleButton(this.doneVideoButton, state !== 'videoReady');
			this.toggleButton(this.blurFacesButton, state !== 'idle');
			this.toggleButton(this.customBlurButton, state !== 'idle');
			this.toggleButton(this.applyCustomBlurButton, state !== 'manual');
			this.toggleButton(this.undoCustomBlurButton, state !== 'manual');
			this.toggleButton(this.cancelCustomBlurButton, state !== 'manual');
			this.toggleButton(this.cancelButton, state !== 'busy');
			this.toggleButton(this.keepButton, state !== 'preview');
			this.toggleButton(this.discardButton, state !== 'preview');
		}

		resolveOperation(response) {
			if (response.blurMode === 'manual') {
				return 'manualBlurFaces';
			}
			if (this.operation === 'manualBlurFaces' && response.operation === 'blurFaces') {
				return this.operation;
			}

			return response.operation || this.operation;
		}

		isBlurOperation() {
			return ['blurFaces', 'manualBlurFaces'].includes(this.operation);
		}

		isCustomEnhancement() {
			return this.operation === 'customEnhance';
		}

		isVideoOperation() {
			return this.operation === 'createVideo';
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

		startManualBlurDraw(event) {
			if (!this.isManualBlurMode || event.button !== 0) {
				return;
			}

			const point = this.getManualBlurPoint(event, false);
			if (!point) {
				return;
			}

			event.preventDefault();
			this.manualBlurDrawStart = point;
			this.currentManualBlurRegion = {
				id: `drawing-${++this.manualBlurRegionSequence}`,
				x: point.x,
				y: point.y,
				width: 0,
				height: 0,
				isDrawing: true,
			};
			this.manualBlurLayer.setPointerCapture?.(event.pointerId);
			this.renderManualBlurRegions();
		}

		moveManualBlurDraw(event) {
			if (!this.manualBlurDrawStart || !this.currentManualBlurRegion) {
				return;
			}

			event.preventDefault();
			const point = this.getManualBlurPoint(event, true);
			if (!point) {
				return;
			}

			this.currentManualBlurRegion = this.createManualBlurRegion(this.manualBlurDrawStart, point, true);
			this.renderManualBlurRegions();
		}

		finishManualBlurDraw(event) {
			if (!this.manualBlurDrawStart || !this.currentManualBlurRegion) {
				return;
			}

			event.preventDefault();
			const point = this.getManualBlurPoint(event, true);
			const region = point ? this.createManualBlurRegion(this.manualBlurDrawStart, point, false) : null;

			if (region && region.width >= 8 && region.height >= 8) {
				this.manualBlurRegions.push({
					...region,
					id: `manual-${++this.manualBlurRegionSequence}`,
				});
			}

			this.cancelManualBlurDraw(event);
		}

		cancelManualBlurDraw(event) {
			if (event?.pointerId !== undefined && this.manualBlurLayer.hasPointerCapture?.(event.pointerId)) {
				try {
					this.manualBlurLayer.releasePointerCapture?.(event.pointerId);
				} catch (error) {
					// The browser may already have released capture after a canceled gesture.
				}
			}

			this.manualBlurDrawStart = null;
			this.currentManualBlurRegion = null;
			this.renderManualBlurRegions();
		}

		handleManualBlurPointerLeave(event) {
			if (!this.manualBlurDrawStart || this.manualBlurLayer.hasPointerCapture?.(event.pointerId)) {
				return;
			}

			this.cancelManualBlurDraw(event);
		}

		createManualBlurRegion(start, end, isDrawing) {
			return {
				id: isDrawing ? 'drawing' : '',
				x: Math.min(start.x, end.x),
				y: Math.min(start.y, end.y),
				width: Math.abs(end.x - start.x),
				height: Math.abs(end.y - start.y),
				isDrawing,
			};
		}

		getManualBlurPoint(event, clampToImage) {
			const metrics = this.getManualBlurImageMetrics();
			if (!metrics) {
				return null;
			}

			const x = event.clientX - metrics.rect.left - metrics.offsetX;
			const y = event.clientY - metrics.rect.top - metrics.offsetY;
			if (!clampToImage && (x < 0 || y < 0 || x > metrics.displayWidth || y > metrics.displayHeight)) {
				return null;
			}

			return {
				x: this.clampManualBlurCoordinate((x / metrics.displayWidth) * 1000),
				y: this.clampManualBlurCoordinate((y / metrics.displayHeight) * 1000),
			};
		}

		getManualBlurImageMetrics() {
			const rect = this.originalImage?.getBoundingClientRect();
			const naturalWidth = this.originalImage?.naturalWidth || 0;
			const naturalHeight = this.originalImage?.naturalHeight || 0;
			if (!rect?.width || !rect.height || !naturalWidth || !naturalHeight) {
				return null;
			}

			const scale = Math.min(rect.width / naturalWidth, rect.height / naturalHeight);
			const displayWidth = naturalWidth * scale;
			const displayHeight = naturalHeight * scale;

			return {
				rect,
				displayWidth,
				displayHeight,
				offsetX: (rect.width - displayWidth) / 2,
				offsetY: (rect.height - displayHeight) / 2,
			};
		}

		renderManualBlurRegions() {
			if (!this.manualBlurLayer) {
				return;
			}

			this.manualBlurLayer.replaceChildren();
			const metrics = this.getManualBlurImageMetrics();
			const regions = this.currentManualBlurRegion
				? [...this.manualBlurRegions, this.currentManualBlurRegion]
				: this.manualBlurRegions;

			if (metrics) {
				regions.forEach((region) => {
					const marker = document.createElement('span');
					marker.className = 'image-enhancer-cp-manual-blur-region';
					marker.classList.toggle('is-drawing', Boolean(region.isDrawing));
					marker.setAttribute('aria-hidden', 'true');
					marker.style.left = `${metrics.offsetX + (region.x / 1000) * metrics.displayWidth}px`;
					marker.style.top = `${metrics.offsetY + (region.y / 1000) * metrics.displayHeight}px`;
					marker.style.width = `${(region.width / 1000) * metrics.displayWidth}px`;
					marker.style.height = `${(region.height / 1000) * metrics.displayHeight}px`;
					this.manualBlurLayer.appendChild(marker);
				});
			}

			this.updateManualBlurButtons();
		}

		resetManualBlurDrawing() {
			this.manualBlurRegions = [];
			this.currentManualBlurRegion = null;
			this.manualBlurDrawStart = null;
			this.manualBlurLayer?.replaceChildren();
			this.updateManualBlurButtons();
		}

		updateManualBlurButtons() {
			const hasRegions = this.manualBlurRegions.length > 0;
			if (this.applyCustomBlurButton) {
				this.applyCustomBlurButton.disabled = !hasRegions;
			}
			if (this.undoCustomBlurButton) {
				this.undoCustomBlurButton.disabled = !hasRegions;
			}
		}

		clampManualBlurCoordinate(value) {
			return Math.max(0, Math.min(1000, Math.round(value)));
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
			this.invalidateOperation();
			window.clearInterval(this.statusTickTimer);
			window.removeEventListener('resize', this.manualBlurResizeHandler);
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

		getInitialVideoProvider() {
			const preference = getVideoProviderPreference();
			const options = this.getVideoProviderOptions();
			if (options.some((option) => option.value === preference.provider)) {
				return preference.provider;
			}

			return options[0]?.value || 'google';
		}

		getInitialVideoModel(provider) {
			const preference = getVideoProviderPreference();
			const options = this.getVideoModelOptions(provider);
			if (
				preference.provider === provider &&
				options.some((option) => option.value === preference.model)
			) {
				return preference.model;
			}

			return options[0]?.value || '';
		}

		getVideoProviderOptions() {
			return normalizeOptions(config.videoProviderOptions || []);
		}

		getVideoModelOptions(provider) {
			return normalizeOptions(config.videoModelOptions[provider] || []);
		}

		syncVideoSelection(response) {
			const providerOptions = this.getVideoProviderOptions();
			const provider = typeof response.videoProvider === 'string' ? response.videoProvider : '';
			if (providerOptions.some((option) => option.value === provider)) {
				this.selectedVideoProvider = provider;
				this.videoProviderSelect.value = provider;
				this.selectedVideoModel = this.getInitialVideoModel(provider);
				this.populateVideoModelSelect();
			}

			const model = typeof response.videoModel === 'string' ? response.videoModel : '';
			const modelOptions = this.getVideoModelOptions(this.selectedVideoProvider);
			if (modelOptions.some((option) => option.value === model)) {
				this.selectedVideoModel = model;
				this.videoModelSelect.value = model;
			}
			this.persistVideoProviderPreference();
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

		persistVideoProviderPreference() {
			try {
				window.localStorage?.setItem(videoProviderStorageKey, JSON.stringify({
					provider: this.selectedVideoProvider,
					model: this.selectedVideoModel,
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
		// assetId is interpolated into attribute selectors below, so it must be numeric.
		if (!/^\d+$/.test(String(assetId)) || !imageUrl) {
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
				node.style.backgroundImage = `url("${escapeCssString(imageUrl)}")`;
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

	/**
	 * Returns an absolute http(s) URL, or '' for anything else (javascript:, data:, etc.).
	 * Use for server-provided URLs assigned to href attributes.
	 */
	function toSafeHttpUrl(url) {
		if (typeof url !== 'string' || url === '') {
			return '';
		}

		try {
			const parsed = new URL(url, window.location.href);
			return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '';
		} catch (error) {
			return '';
		}
	}

	function escapeCssString(value) {
		return String(value).replace(/[\\"]/g, '\\$&').replace(/[\n\r\f]/g, '');
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

	function getVideoProviderPreference() {
		try {
			return JSON.parse(window.localStorage?.getItem(videoProviderStorageKey) || '{}') || {};
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
			videoModelOptions: {
				...base.videoModelOptions,
				...(override.videoModelOptions || {}),
			},
		};
	}

	ready(init);
})();
