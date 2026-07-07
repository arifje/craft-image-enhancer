(function () {
	'use strict';

	const defaults = {
		providerChoiceEnabled: false,
		imageEnhancementProvider: 'openai',
		imageEnhancementModel: 'gpt-image-2',
		xAiImageEnhancementModel: 'grok-imagine-image-quality',
		googleImageEnhancementModel: 'gemini-3.1-flash-image',
		allowedFieldHandles: [],
		routes: {
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
		'.field .element[data-id]',
		'.field .element-card[data-id]',
		'.field [data-asset-id]',
		'.field [data-type*="Asset"][data-id]',
	].join(',');
	let observer = null;
	let scanBurstTimer = null;

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
		document.addEventListener('click', onDocumentClick, true);
		document.addEventListener('click', onPotentialTabChange, true);
		document.addEventListener('keydown', onPotentialTabChange, true);
		window.addEventListener('hashchange', scheduleScanBurst);
		observer = new MutationObserver((mutations) => {
			mutations.forEach((mutation) => {
				mutation.addedNodes.forEach((node) => {
					if (node.nodeType === Node.ELEMENT_NODE) {
						scanAssetFields(node);
					}
				});
			});
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
			window.setTimeout(() => scanAssetFields(document), 250);
			window.setTimeout(() => scanAssetFields(document), 750);
		}, 50);
	}

	function scanAssetFields(root) {
		const candidates = new Set();
		if (root.matches && root.matches(scanSelector)) {
			candidates.add(root);
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
			return type.includes('Asset') && Boolean(card.querySelector('img') || getBackgroundImageUrl(card));
		}

		const kind = card.dataset.kind || card.getAttribute('data-kind') || '';
		const hasAssetHint = card.classList.contains('asset') ||
			kind === 'image' ||
			Boolean(card.closest('[data-type*="Asset"]'));

		return hasAssetHint && Boolean(card.querySelector('img') || getBackgroundImageUrl(card));
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
		constructor({ assetId, originalUrl, card }) {
			this.assetId = assetId;
			this.originalUrl = originalUrl;
			this.card = card;
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
			await this.resolveAssetInfo();
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
			const root = document.createElement('div');
			root.className = 'modal image-enhancer-cp-modal';
			root.innerHTML = [
				'<div class="image-enhancer-cp-shell">',
				'  <div class="image-enhancer-cp-header">',
				'    <div>',
				'      <h2>Enhance image</h2>',
				'      <p>Queue an enhancement, compare the result, then save it over the current asset.</p>',
				'    </div>',
				'    <button type="button" class="image-enhancer-cp-close" aria-label="Close">&times;</button>',
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
				'    <button type="button" class="btn submit" data-enhance>Enhance</button>',
				'    <button type="button" class="btn" data-cancel hidden>Cancel</button>',
				'    <button type="button" class="btn submit" data-keep hidden>Save replacement</button>',
				'    <button type="button" class="btn" data-discard hidden>Discard</button>',
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
			this.cancelButton = root.querySelector('[data-cancel]');
			this.keepButton = root.querySelector('[data-keep]');
			this.discardButton = root.querySelector('[data-discard]');
			this.providerControls = root.querySelector('[data-provider-controls]');
			this.providerSelect = root.querySelector('[data-provider]');
			this.modelSelect = root.querySelector('[data-model]');

			this.originalImage.src = this.originalUrl;
			this.compareOriginalImage.src = this.originalUrl;
			this.range.addEventListener('input', () => this.updateComparison());
			root.querySelector('[data-enhance]').addEventListener('click', () => this.enhance());
			root.querySelector('[data-cancel]').addEventListener('click', () => this.cancel());
			root.querySelector('[data-keep]').addEventListener('click', () => this.keep());
			root.querySelector('[data-discard]').addEventListener('click', () => this.discard());
			root.querySelector('.image-enhancer-cp-close').addEventListener('click', () => this.close());

			this.setupProviderControls();
			this.updateComparison();
			this.restoreStatus();
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
			this.setBusy(true, 'Queued...');
			this.token = '';
			this.jobId = '';
			this.previewId = '';
			this.enhancedUrl = '';
			this.setPreviewMode(false);

			try {
				const payload = {
					assetId: this.assetId,
				};
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
			this.setBusy(true, 'Saving replacement...');

			try {
				const response = await this.request('keep', {
					assetId: this.assetId,
					previewId: this.previewId,
					token: this.token,
					previewUrl: this.enhancedUrl,
				});
				const imageUrl = response.imageUrl || this.enhancedUrl;
				refreshAssetFieldImage(this.assetId, withCacheBuster(imageUrl), this.card);
				if (window.Craft?.cp) {
					Craft.cp.displayNotice('Enhanced image saved.');
				}
				this.close();
			} catch (error) {
				this.setBusy(false);
				this.showError(error);
			}
		}

		async discard() {
			if (!this.previewId) {
				this.close();
				return;
			}

			this.clearError();
			this.setBusy(true, 'Discarding...');

			try {
				await this.request('discard', {
					assetId: this.assetId,
					previewId: this.previewId,
					token: this.token,
				});
				this.close();
			} catch (error) {
				this.setBusy(false);
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
			this.keepButton.hidden = !enabled;
			this.discardButton.hidden = !enabled;
			this.enhanceButton.hidden = enabled;
			this.cancelButton.hidden = true;
		}

		setBusy(isBusy, label = '', includeCounter = false) {
			this.enhanceButton.disabled = isBusy;
			this.keepButton.disabled = isBusy;
			this.discardButton.disabled = isBusy;
			this.cancelButton.hidden = !isBusy || Boolean(this.previewId);
			this.setStatus(label, isBusy, includeCounter);
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
			window.clearTimeout(this.pollTimer);
			window.clearInterval(this.statusTickTimer);
			this.root?.remove();
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
