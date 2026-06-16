<template>
	<div
		class="uk-relative article-image-container"
		:class="{ 'is-processing': isBusy, 'is-loaded': isDisplayLoaded, 'is-ui-hidden': !isUiVisible }"
		:style="containerStyle"
	>
		<div
			v-if="hasPendingPreview"
			class="article-image-compare uk-border-rounded"
			:style="comparisonStyle"
		>
			<img
				:key="`original-${imageKey}`"
				:src="originalSrc"
				:srcset="originalSrcset || undefined"
				:sizes="originalSrcset ? sizes : undefined"
				:width="width"
				:height="height"
				:alt="alt"
				:fetchpriority="fetchpriority"
				class="article-image-compare-image article-image-compare-original"
				@load="handleCompareOriginalLoad"
				@error="handleImageError"
			>
			<div class="article-image-compare-enhanced-wrap">
				<img
					:key="`enhanced-${imageKey}`"
					:src="preview.url"
					:width="width"
					:height="height"
					:alt="alt"
					:fetchpriority="fetchpriority"
					class="article-image-compare-image article-image-compare-enhanced"
					@load="handleCompareEnhancedLoad"
					@error="handleEnhancedImageError"
				>
			</div>
			<div class="article-image-compare-divider" aria-hidden="true"></div>
			<div class="article-image-compare-handle" aria-hidden="true"></div>
			<input
				v-model.number="comparisonPosition"
				class="article-image-compare-range"
				type="range"
				min="0"
				max="100"
				step="1"
				aria-label="Compare original and enhanced image"
			>
		</div>

		<img
			v-else
			:key="imageKey"
			:src="currentSrc"
			:srcset="currentSrcset || undefined"
			:sizes="currentSrcset ? sizes : undefined"
			:width="width"
			:height="height"
			:alt="alt"
			:fetchpriority="fetchpriority"
			class="article-image-single uk-border-rounded uk-width-1-1"
			@load="handleImageLoad"
			@error="handleImageError"
		>

		<div
			v-if="isUiVisible && canShowActions && (!isEnhancing || hasPendingPreview || canCancelEnhancement)"
			class="article-image-action-bar"
			:class="{ 'has-provider-controls': canChooseProvider && !hasPendingPreview && !isEnhancing }"
		>
			<div v-if="canChooseProvider && !hasPendingPreview && !isEnhancing" class="article-image-provider-controls">
				<label class="article-image-provider-field">
					<span>{{ providerLabel }}</span>
					<select v-model="selectedProvider" class="article-image-provider-select">
						<option
							v-for="provider in providerOptions"
							:key="provider.value"
							:value="provider.value"
						>
							{{ provider.label }}
						</option>
					</select>
				</label>
				<label class="article-image-provider-field">
					<span>{{ modelLabel }}</span>
					<select v-model="selectedModel" class="article-image-provider-select">
						<option
							v-for="model in currentProviderModelOptions"
							:key="model.value"
							:value="model.value"
						>
							{{ model.label }}
						</option>
					</select>
				</label>
			</div>

			<button
				v-if="canCancelEnhancement"
				type="button"
				class="uk-button uk-button-small uk-button-default article-image-button article-image-button-secondary"
				:disabled="isCanceling"
				@click.prevent="cancelEnhancement"
			>
				<span v-if="isCanceling" class="article-image-spinner" aria-hidden="true"></span>
				<span>{{ isCanceling ? cancelingLabel : cancelLabel }}</span>
			</button>

			<template v-else-if="errorMessage && !hasPendingPreview">
				<button
					type="button"
					class="uk-button uk-button-small uk-button-primary article-image-button article-image-button-primary"
					:disabled="isBusy"
					@click.prevent="retryEnhancement"
				>
					<span v-if="isEnhancing" class="article-image-spinner" aria-hidden="true"></span>
					<span>{{ isEnhancing ? retryingLabel : retryLabel }}</span>
				</button>
				<button
					type="button"
					class="uk-button uk-button-small uk-button-default article-image-button article-image-button-secondary"
					:disabled="isBusy"
					@click.prevent="resetEnhancementStatus"
				>
					<span v-if="isResetting" class="article-image-spinner" aria-hidden="true"></span>
					<span>{{ isResetting ? resettingLabel : resetLabel }}</span>
				</button>
			</template>

			<button
				v-else-if="!hasPendingPreview"
				type="button"
				class="uk-button uk-button-small uk-button-primary article-image-button article-image-button-primary"
				:disabled="isBusy"
				@click.prevent="enhanceImage()"
			>
				<span v-if="isEnhancing" class="article-image-spinner" aria-hidden="true"></span>
				<span>{{ isEnhancing ? enhancingLabel : enhanceLabel }}</span>
			</button>

			<template v-else>
				<button
					type="button"
					class="uk-button uk-button-small uk-button-primary article-image-button article-image-button-primary"
					:disabled="isBusy"
					@click.prevent="keepEnhancedImage"
				>
					<span v-if="isKeeping" class="article-image-spinner" aria-hidden="true"></span>
					<span>{{ isKeeping ? keepingLabel : keepLabel }}</span>
				</button>
				<button
					type="button"
					class="uk-button uk-button-small uk-button-default article-image-button article-image-button-secondary"
					:disabled="isBusy"
					@click.prevent="discardEnhancedImage"
				>
					<span v-if="isDiscarding" class="article-image-spinner" aria-hidden="true"></span>
					<span>{{ isDiscarding ? discardingLabel : discardLabel }}</span>
				</button>
			</template>
		</div>

		<div v-if="isUiVisible && isBusy" class="article-image-status uk-label">
			<span class="article-image-spinner" aria-hidden="true"></span>
			<span>{{ statusLabel }}</span>
		</div>

		<div v-if="isUiVisible && errorMessage" class="article-image-error uk-alert-danger" role="alert">
			<span>{{ errorMessage }}</span>
			<button
				v-if="canShowActions && !hasPendingPreview"
				type="button"
				class="article-image-error-reset"
				:disabled="isBusy"
				@click.prevent="resetEnhancementStatus"
			>
				{{ isResetting ? resettingLabel : resetLabel }}
			</button>
		</div>

		<button
			v-if="canToggleUi"
			type="button"
			class="article-image-ui-toggle"
			:aria-pressed="!isUiVisible"
			:aria-label="isUiVisible ? hideUiLabel : showUiLabel"
			@click.prevent="toggleUi"
		>
			<span>{{ isUiVisible ? hideUiLabel : showUiLabel }}</span>
		</button>

		<span
			v-if="category"
			class="uk-label category uk-padding-xsmall"
			style="position: absolute; left: 12px; bottom: 12px"
		>
			{{ category }}
		</span>

		<span v-if="credits" class="credits uk-label" v-html="credits"></span>
	</div>
</template>

<script setup>
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const actionRoutes = {
	enhance: '_image-quality-checker/article-image/enhance',
	status: '_image-quality-checker/article-image/status',
	cancel: '_image-quality-checker/article-image/cancel',
	reset: '_image-quality-checker/article-image/reset',
	keep: '_image-quality-checker/article-image/keep',
	discard: '_image-quality-checker/article-image/discard',
};
const persistedStatusTtlMs = 6 * 60 * 60 * 1000;
const providerPreferenceStorageKey = 'image-quality-checker:image-enhancer:provider-preference';
const providerOptions = [
	{ label: 'OpenAI', value: 'openai' },
	{ label: 'Grok Imagine', value: 'xai' },
	{ label: 'Google Nano Banana', value: 'google' },
];
const defaultModelOptions = {
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
};

const props = defineProps({
	assetId: {
		type: [Number, String],
		default: null,
	},
	showEnhancementOptions: {
		type: Boolean,
		default: false,
	},
	enhanceable: {
		type: Boolean,
		default: true,
	},
	uiToggleEnabled: {
		type: Boolean,
		default: true,
	},
	uiInitiallyVisible: {
		type: Boolean,
		default: true,
	},
	providerChoiceEnabled: {
		type: Boolean,
		default: false,
	},
	imageEnhancementProvider: {
		type: String,
		default: 'openai',
	},
	imageEnhancementModel: {
		type: String,
		default: '',
	},
	openAiImageEnhancementModels: {
		type: Array,
		default: () => [
			{ label: 'GPT Image 2', value: 'gpt-image-2' },
			{ label: 'GPT Image 1', value: 'gpt-image-1' },
		],
	},
	xAiImageEnhancementModels: {
		type: Array,
		default: () => [
			{ label: 'Grok Imagine Image Quality', value: 'grok-imagine-image-quality' },
		],
	},
	googleImageEnhancementModels: {
		type: Array,
		default: () => [
			{ label: 'Gemini 3.1 Flash Image (Nano Banana 2)', value: 'gemini-3.1-flash-image' },
			{ label: 'Gemini 3 Pro Image (Nano Banana Pro)', value: 'gemini-3-pro-image' },
			{ label: 'Gemini 2.5 Flash Image (Nano Banana)', value: 'gemini-2.5-flash-image' },
		],
	},
	src: {
		type: String,
		default: '',
	},
	srcset: {
		type: String,
		default: '',
	},
	sizes: {
		type: String,
		default: '100vw',
	},
	width: {
		type: [Number, String],
		default: 1200,
	},
	height: {
		type: [Number, String],
		default: 675,
	},
	alt: {
		type: String,
		default: '',
	},
	fetchpriority: {
		type: String,
		default: 'high',
	},
	fallbackSrc: {
		type: String,
		default: 'https://placeholder.pics/svg/600x375/DEDEDE/555555/%F0%9F%93%B8',
	},
	category: {
		type: String,
		default: '',
	},
	credits: {
		type: String,
		default: '',
	},
	actionTrigger: {
		type: String,
		default: 'actions',
	},
	apiTransport: {
		type: String,
		default: 'actions',
		validator: (value) => ['actions', 'graphql'].includes(value),
	},
	apiCredentials: {
		type: String,
		default: 'same-origin',
	},
	graphqlEndpoint: {
		type: String,
		default: '/api',
	},
	graphqlToken: {
		type: String,
		default: '',
	},
	graphqlHeaders: {
		type: Object,
		default: () => ({}),
	},
	graphqlOperations: {
		type: Object,
		default: () => ({}),
	},
	csrfTokenName: {
		type: String,
		default: '',
	},
	csrfTokenValue: {
		type: String,
		default: '',
	},
	enhanceLabel: {
		type: String,
		default: 'Enhance',
	},
	enhancingLabel: {
		type: String,
		default: 'Enhancing...',
	},
	queuedLabel: {
		type: String,
		default: 'Queued...',
	},
	runningLabel: {
		type: String,
		default: 'Running...',
	},
	keepLabel: {
		type: String,
		default: 'Keep',
	},
	keepingLabel: {
		type: String,
		default: 'Keeping...',
	},
	discardLabel: {
		type: String,
		default: 'Discard',
	},
	discardingLabel: {
		type: String,
		default: 'Discarding...',
	},
	cancelLabel: {
		type: String,
		default: 'Cancel',
	},
	cancelingLabel: {
		type: String,
		default: 'Canceling...',
	},
	retryLabel: {
		type: String,
		default: 'Retry',
	},
	retryingLabel: {
		type: String,
		default: 'Retrying...',
	},
	resetLabel: {
		type: String,
		default: 'Reset',
	},
	resettingLabel: {
		type: String,
		default: 'Resetting...',
	},
	hideUiLabel: {
		type: String,
		default: 'Hide UI',
	},
	showUiLabel: {
		type: String,
		default: 'Show UI',
	},
	cancelConfirmMessage: {
		type: String,
		default: 'Are you sure you want to cancel this enhancement?',
	},
	providerLabel: {
		type: String,
		default: 'Provider',
	},
	modelLabel: {
		type: String,
		default: 'Model',
	},
	pollIntervalMs: {
		type: Number,
		default: 1500,
	},
	pollTimeoutMs: {
		type: Number,
		default: 180000,
	},
});

const emit = defineEmits(['enhanced', 'kept', 'discarded', 'canceled', 'error']);

const originalSrc = ref(props.src || props.fallbackSrc);
const originalSrcset = ref(props.srcset);
const currentSrc = ref(originalSrc.value);
const currentSrcset = ref(originalSrcset.value);
const imageKey = ref(0);
const preview = ref(null);
const activeJob = ref(null);
const errorMessage = ref('');
const remoteStatusLabel = ref('');
const enhancementJobStatus = ref('idle');
const enhancementStatusStartedAt = ref(0);
const statusTick = ref(Date.now());
const statusTimer = ref(null);
const isImageLoaded = ref(false);
const isCompareOriginalLoaded = ref(false);
const isCompareEnhancedLoaded = ref(false);
const isEnhancing = ref(false);
const isKeeping = ref(false);
const isDiscarding = ref(false);
const isCanceling = ref(false);
const isResetting = ref(false);
const isUiVisible = ref(props.uiInitiallyVisible);
const comparisonPosition = ref(50);
const pollTimer = ref(null);
const pollStartedAt = ref(0);
const component = getCurrentInstance()?.proxy;
const selectedProvider = ref(getInitialProvider());
const selectedModel = ref(getInitialModel(selectedProvider.value));

const apiTransport = computed(() => (props.apiTransport === 'graphql' ? 'graphql' : 'actions'));
const isBusy = computed(() => isEnhancing.value || isKeeping.value || isDiscarding.value || isCanceling.value || isResetting.value);
const hasPendingPreview = computed(() => preview.value !== null);
const isDisplayLoaded = computed(() => {
	if (hasPendingPreview.value) {
		return isCompareOriginalLoaded.value && isCompareEnhancedLoaded.value;
	}

	return isImageLoaded.value;
});
const canShowActions = computed(() => Boolean(props.showEnhancementOptions && props.enhanceable && props.assetId));
const canChooseProvider = computed(() => Boolean(canShowActions.value && props.providerChoiceEnabled));
const canToggleUi = computed(() => Boolean(canShowActions.value && props.uiToggleEnabled));
const canCancelEnhancement = computed(() => Boolean(isEnhancing.value && activeJob.value && !hasPendingPreview.value));
const modelOptionsByProvider = computed(() => ({
	openai: normalizeModelOptions(props.openAiImageEnhancementModels, defaultModelOptions.openai),
	xai: normalizeModelOptions(props.xAiImageEnhancementModels, defaultModelOptions.xai),
	google: normalizeModelOptions(props.googleImageEnhancementModels, defaultModelOptions.google),
}));
const currentProviderModelOptions = computed(() => modelOptionsByProvider.value[selectedProvider.value] || modelOptionsByProvider.value.openai);
const imageAspectRatio = computed(() => {
	const width = Number.parseFloat(props.width) || 1200;
	const height = Number.parseFloat(props.height) || 675;

	return `${width} / ${height}`;
});
const containerStyle = computed(() => ({
	'--article-image-aspect-ratio': imageAspectRatio.value,
}));
const comparisonStyle = computed(() => ({
	'--article-image-compare-position': `${comparisonPosition.value}%`,
}));
const runningSeconds = computed(() => {
	const now = statusTick.value;

	if (!enhancementStatusStartedAt.value) {
		return 0;
	}

	return Math.max(0, Math.floor((now - enhancementStatusStartedAt.value) / 1000));
});
const statusLabel = computed(() => {
	if (isCanceling.value) {
		return props.cancelingLabel;
	}
	if (isEnhancing.value) {
		if (enhancementJobStatus.value === 'running') {
			return `${props.runningLabel} (${runningSeconds.value}s)`;
		}
		if (enhancementJobStatus.value === 'queued' || enhancementJobStatus.value === 'pending') {
			return props.queuedLabel;
		}

		if (remoteStatusLabel.value) {
			return remoteStatusLabel.value;
		}

		return props.enhancingLabel;
	}
	if (isKeeping.value) {
		return props.keepingLabel;
	}
	if (isDiscarding.value) {
		return props.discardingLabel;
	}

	return '';
});

watch(
	() => [props.src, props.srcset],
	([src, srcset]) => {
		if (hasPendingPreview.value) {
			return;
		}

		originalSrc.value = src || props.fallbackSrc;
		originalSrcset.value = srcset || '';
		currentSrc.value = originalSrc.value;
		currentSrcset.value = originalSrcset.value;
		isImageLoaded.value = false;
		isCompareOriginalLoaded.value = false;
		isCompareEnhancedLoaded.value = false;
		imageKey.value += 1;
	}
);

watch(
	selectedProvider,
	(provider) => {
		const options = modelOptionsByProvider.value[provider] || [];
		const hasSelectedModel = options.some((option) => option.value === selectedModel.value);

		if (!hasSelectedModel) {
			selectedModel.value = options[0]?.value || '';
		}

		persistProviderPreference();
	}
);

watch(selectedModel, () => {
	persistProviderPreference();
});

onMounted(() => {
	restoreExistingEnhancementStatus();
});

onBeforeUnmount(() => {
	clearPolling();
	clearStatusTimer();
});

async function enhanceImage(statusText = props.queuedLabel) {
	if (typeof statusText !== 'string') {
		statusText = props.queuedLabel;
	}

	log('function/enhanceImage/start');
	errorMessage.value = '';
	activeJob.value = null;
	preview.value = null;
	clearPersistedEnhancementStatus();
	isEnhancing.value = true;
	remoteStatusLabel.value = statusText;
	setEnhancementStatus('queued');

	try {
		persistProviderPreference();
		const response = await requestApi('enhance', {
			assetId: props.assetId,
			...getProviderRequestPayload(),
		});

		if (response.queued || response.token || response.jobId) {
			startPolling(response);
			log('function/enhanceImage/queued');
			return;
		}

		applyEnhancedPreview(response);
		clearStatusTimer();
		isEnhancing.value = false;
		remoteStatusLabel.value = '';
		log('function/enhanceImage/direct-complete');
	} catch (error) {
		handleError(error);
		clearStatusTimer();
		isEnhancing.value = false;
		remoteStatusLabel.value = '';
	} finally {
		if (!activeJob.value) {
			isEnhancing.value = false;
		}
	}
}

async function retryEnhancement() {
	log('function/retryEnhancement/start');
	await enhanceImage(props.retryingLabel);
}

function toggleUi() {
	isUiVisible.value = !isUiVisible.value;
	log(`function/toggleUi/${isUiVisible.value ? 'visible' : 'hidden'}`);
}

async function resetEnhancementStatus() {
	log('function/resetEnhancementStatus/start');
	isResetting.value = true;

	try {
		await requestApi('reset', {
			assetId: props.assetId,
			token: getCurrentEnhancementToken(),
		});
		resetToOriginalImage();
		errorMessage.value = '';
		remoteStatusLabel.value = '';
		log('function/resetEnhancementStatus/complete');
	} catch (error) {
		resetToOriginalImage();
		errorMessage.value = '';
		remoteStatusLabel.value = '';
		log(`function/resetEnhancementStatus/local-reset/${error instanceof Error ? error.message : 'unknown'}`);
	} finally {
		isResetting.value = false;
		isEnhancing.value = false;
	}
}

async function keepEnhancedImage() {
	if (!preview.value) {
		return;
	}

	log('function/keepEnhancedImage/start');
	errorMessage.value = '';
	isKeeping.value = true;
	remoteStatusLabel.value = props.keepingLabel;

	try {
		const response = await requestApi('keep', previewPayload());
		const keptUrl = response.imageUrl || response.assetUrl || response.url || preview.value.url;

		originalSrc.value = keptUrl;
		originalSrcset.value = '';
		currentSrc.value = keptUrl;
		currentSrcset.value = '';
		preview.value = null;
		activeJob.value = null;
		clearPersistedEnhancementStatus();
		clearStatusTimer();
		isImageLoaded.value = false;
		isCompareOriginalLoaded.value = false;
		isCompareEnhancedLoaded.value = false;
		comparisonPosition.value = 50;
		imageKey.value += 1;
		emit('kept', response);
		log('function/keepEnhancedImage/complete');
	} catch (error) {
		handleError(error);
	} finally {
		isKeeping.value = false;
		remoteStatusLabel.value = '';
	}
}

async function cancelEnhancement() {
	if (!activeJob.value) {
		return;
	}

	if (props.cancelConfirmMessage && !window.confirm(props.cancelConfirmMessage)) {
		log('function/cancelEnhancement/declined');
		return;
	}

	log('function/cancelEnhancement/start');
	errorMessage.value = '';
	isCanceling.value = true;
	remoteStatusLabel.value = props.cancelingLabel;

	try {
		const response = await requestApi('cancel', {
			assetId: props.assetId,
			token: activeJob.value.token,
			jobId: activeJob.value.jobId,
		});

		resetToOriginalImage();
		clearStatusTimer();
		emit('canceled', response);
		log('function/cancelEnhancement/complete');
	} catch (error) {
		handleError(error);
	} finally {
		isCanceling.value = false;
		isEnhancing.value = false;
		remoteStatusLabel.value = '';
	}
}

async function discardEnhancedImage() {
	if (!preview.value) {
		return;
	}

	log('function/discardEnhancedImage/start');
	errorMessage.value = '';
	isDiscarding.value = true;
	remoteStatusLabel.value = props.discardingLabel;

	try {
		const response = await requestApi('discard', previewPayload());

		currentSrc.value = originalSrc.value;
		currentSrcset.value = originalSrcset.value;
		preview.value = null;
		activeJob.value = null;
		clearPersistedEnhancementStatus();
		clearStatusTimer();
		isImageLoaded.value = false;
		isCompareOriginalLoaded.value = false;
		isCompareEnhancedLoaded.value = false;
		comparisonPosition.value = 50;
		imageKey.value += 1;
		emit('discarded', response);
		log('function/discardEnhancedImage/complete');
	} catch (error) {
		handleError(error);
	} finally {
		isDiscarding.value = false;
		remoteStatusLabel.value = '';
	}
}

function getInitialProvider() {
	const storedPreference = getProviderPreference();
	const provider = props.imageEnhancementProvider;
	const preferredProvider = storedPreference?.provider || provider;
	const hasProvider = providerOptions.some((option) => option.value === preferredProvider);

	return hasProvider ? preferredProvider : 'openai';
}

function getInitialModel(provider) {
	const models = {
		openai: normalizeModelOptions(props.openAiImageEnhancementModels, defaultModelOptions.openai),
		xai: normalizeModelOptions(props.xAiImageEnhancementModels, defaultModelOptions.xai),
		google: normalizeModelOptions(props.googleImageEnhancementModels, defaultModelOptions.google),
	};
	const providerModels = models[provider] || models.openai;
	const storedPreference = getProviderPreference();
	const selected = storedPreference?.provider === provider
		? storedPreference.model
		: props.imageEnhancementModel;

	return providerModels.some((option) => option.value === selected)
		? selected
		: providerModels[0]?.value || '';
}

function getProviderPreference() {
	if (typeof window === 'undefined' || !window.localStorage) {
		return null;
	}

	try {
		const preference = JSON.parse(window.localStorage.getItem(providerPreferenceStorageKey) || 'null');

		if (!preference?.provider || !preference?.model) {
			return null;
		}

		return preference;
	} catch (error) {
		log(`function/getProviderPreference/error/${error instanceof Error ? error.message : 'unknown'}`);
		return null;
	}
}

function persistProviderPreference() {
	if (!canChooseProvider.value || typeof window === 'undefined' || !window.localStorage) {
		return;
	}

	try {
		window.localStorage.setItem(providerPreferenceStorageKey, JSON.stringify({
			provider: selectedProvider.value,
			model: selectedModel.value,
		}));
	} catch (error) {
		log(`function/persistProviderPreference/error/${error instanceof Error ? error.message : 'unknown'}`);
	}
}

function normalizeModelOptions(options, fallbackOptions) {
	if (!Array.isArray(options) || options.length === 0) {
		return fallbackOptions;
	}

	return options
		.map((option) => {
			if (typeof option === 'string') {
				return { label: option, value: option };
			}

			const value = option?.value ?? option?.id ?? '';
			const label = option?.label ?? value;

			return value ? { label, value } : null;
		})
		.filter(Boolean);
}

function previewPayload() {
	return {
		assetId: props.assetId,
		previewId: preview.value?.id,
		token: preview.value?.token || activeJob.value?.token,
		previewUrl: preview.value?.url,
	};
}

function getProviderRequestPayload() {
	if (!canChooseProvider.value) {
		return {};
	}

	return {
		imageEnhancementProvider: selectedProvider.value,
		imageEnhancementModel: selectedModel.value,
	};
}

function getCurrentEnhancementToken() {
	return activeJob.value?.token || preview.value?.token || getPersistedEnhancementStatus()?.token || '';
}

function startPolling(response) {
	if (!response.token) {
		throw new Error('The enhancement response did not include a token.');
	}

	clearPolling();
	activeJob.value = {
		token: response.token,
		jobId: response.jobId || null,
		statusUrl: response.statusUrl || null,
	};
	persistEnhancementStatus(response);
	pollStartedAt.value = Date.now();
	remoteStatusLabel.value = '';
	setEnhancementStatus(response.status === 'running' ? 'running' : 'queued');
	pollEnhancementStatus();
}

async function pollEnhancementStatus() {
	if (!activeJob.value) {
		return;
	}

	try {
		const response = await requestApi('status', {
			assetId: props.assetId,
			token: activeJob.value.token,
			jobId: activeJob.value.jobId,
		});
		persistEnhancementStatus(response);

		if (response.status === 'running') {
			setEnhancementStatus('running');
			remoteStatusLabel.value = '';
		} else if (response.status === 'queued' || response.status === 'pending') {
			setEnhancementStatus(response.status);
			remoteStatusLabel.value = '';
		}

		if (response.status === 'complete') {
			applyEnhancedPreview({
				...response,
				token: activeJob.value.token,
			});
			clearPolling();
			clearStatusTimer();
			isEnhancing.value = false;
			remoteStatusLabel.value = '';
			log('function/pollEnhancementStatus/complete');
			return;
		}

		if (response.status === 'canceled') {
			resetToOriginalImage();
			isEnhancing.value = false;
			remoteStatusLabel.value = '';
			emit('canceled', response);
			log('function/pollEnhancementStatus/canceled');
			return;
		}

		if (response.status === 'failed') {
			persistEnhancementStatus(response);
			activeJob.value = null;
			clearStatusTimer();
			throw new Error(response.message || 'Enhancement failed.');
		}

		if (Date.now() - pollStartedAt.value > props.pollTimeoutMs) {
			throw new Error('Enhancement is taking longer than expected. Please try again.');
		}

		pollTimer.value = window.setTimeout(pollEnhancementStatus, props.pollIntervalMs);
	} catch (error) {
		clearPolling();
		clearStatusTimer();
		isEnhancing.value = false;
		remoteStatusLabel.value = '';
		handleError(error);
	}
}

async function restoreExistingEnhancementStatus() {
	if (!canShowActions.value) {
		return;
	}

	log('function/restoreExistingEnhancementStatus/start');

	try {
		let response = await requestApi('status', {
			assetId: props.assetId,
		});

		log(`function/restoreExistingEnhancementStatus/status/${response.status || 'unknown'}/asset/${props.assetId}`);

		if (response.status === 'idle') {
			const persistedStatus = getPersistedEnhancementStatus();

			if (persistedStatus?.token) {
				log(`function/restoreExistingEnhancementStatus/fallback-token/asset/${props.assetId}`);
				response = await requestApi('status', {
					assetId: props.assetId,
					token: persistedStatus.token,
					jobId: persistedStatus.jobId,
				});
				log(`function/restoreExistingEnhancementStatus/fallback-status/${response.status || 'unknown'}/asset/${props.assetId}`);
			}
		}

		if (handleRestoredEnhancementStatus(response)) {
			return;
		}
	} catch (error) {
		log(`function/restoreExistingEnhancementStatus/error/${error instanceof Error ? error.message : 'unknown'}`);
	}
}

function handleRestoredEnhancementStatus(response) {
	if (response.status === 'queued' || response.status === 'running' || response.status === 'pending') {
		if (!response.token) {
			clearPersistedEnhancementStatus();
			return true;
		}

		isEnhancing.value = true;
		remoteStatusLabel.value = response.progressLabel || props.enhancingLabel;
		startPolling({
			...response,
		});
		log('function/restoreExistingEnhancementStatus/resumed');
		return true;
	}

	if (response.status === 'complete' && (response.enhancedUrl || response.imageUrl || response.assetUrl || response.url)) {
		applyEnhancedPreview(response);
		isEnhancing.value = false;
		remoteStatusLabel.value = '';
		log('function/restoreExistingEnhancementStatus/preview-restored');
		return true;
	}

	if (response.status === 'failed') {
		persistEnhancementStatus(response);
		errorMessage.value = response.message || response.previousError || 'Enhancement failed.';
		activeJob.value = null;
		isEnhancing.value = false;
		remoteStatusLabel.value = '';
		log('function/restoreExistingEnhancementStatus/failed');
		return true;
	}

	if (response.status === 'canceled') {
		resetToOriginalImage();
		log('function/restoreExistingEnhancementStatus/canceled');
		return true;
	}

	return false;
}

function applyEnhancedPreview(response) {
	const enhancedUrl = response.enhancedUrl || response.imageUrl || response.assetUrl || response.url;
	const previewId = response.previewId || response.previewToken || response.enhancedAssetId || response.tempAssetId || null;

	if (!enhancedUrl) {
		throw new Error('The enhancement response did not include an enhanced image URL.');
	}

	preview.value = {
		id: previewId,
		token: response.token || activeJob.value?.token,
		url: enhancedUrl,
		response,
	};
	persistEnhancementStatus(response);
	currentSrc.value = enhancedUrl;
	currentSrcset.value = '';
	isImageLoaded.value = false;
	isCompareOriginalLoaded.value = false;
	isCompareEnhancedLoaded.value = false;
	comparisonPosition.value = 50;
	imageKey.value += 1;
	emit('enhanced', response);
}

function clearPolling() {
	if (pollTimer.value) {
		window.clearTimeout(pollTimer.value);
		pollTimer.value = null;
	}
}

function setEnhancementStatus(status) {
	if (enhancementJobStatus.value !== status) {
		enhancementStatusStartedAt.value = Date.now();
	}

	enhancementJobStatus.value = status;
	statusTick.value = Date.now();

	if ((status === 'queued' || status === 'pending' || status === 'running') && !statusTimer.value) {
		statusTimer.value = window.setInterval(() => {
			statusTick.value = Date.now();
		}, 1000);
	}
}

function clearStatusTimer() {
	if (statusTimer.value) {
		window.clearInterval(statusTimer.value);
		statusTimer.value = null;
	}

	enhancementJobStatus.value = 'idle';
	enhancementStatusStartedAt.value = 0;
	statusTick.value = Date.now();
}

function resetToOriginalImage() {
	clearPolling();
	clearStatusTimer();
	currentSrc.value = originalSrc.value;
	currentSrcset.value = originalSrcset.value;
	preview.value = null;
	activeJob.value = null;
	clearPersistedEnhancementStatus();
	isImageLoaded.value = false;
	isCompareOriginalLoaded.value = false;
	isCompareEnhancedLoaded.value = false;
	comparisonPosition.value = 50;
	imageKey.value += 1;
}

function getActionUrl(action) {
	const route = actionRoutes[action];
	const trigger = props.actionTrigger.replace(/^\/+|\/+$/g, '') || 'actions';

	return `/${trigger}/${route}`;
}

function getPersistedStatusKey() {
	if (!props.assetId || typeof window === 'undefined' || !window.localStorage) {
		return '';
	}

	return `image-quality-checker:image-enhancer:${props.assetId}`;
}

function getPersistedEnhancementStatus() {
	const key = getPersistedStatusKey();

	if (!key) {
		return null;
	}

	try {
		const rawStatus = window.localStorage.getItem(key);

		if (!rawStatus) {
			return null;
		}

		const status = JSON.parse(rawStatus);

		if (String(status.assetId) !== String(props.assetId)) {
			clearPersistedEnhancementStatus();
			return null;
		}

		if (!status.savedAt || Date.now() - status.savedAt > persistedStatusTtlMs) {
			clearPersistedEnhancementStatus();
			return null;
		}

		return status;
	} catch (error) {
		clearPersistedEnhancementStatus();
		log(`function/getPersistedEnhancementStatus/error/${error instanceof Error ? error.message : 'unknown'}`);
		return null;
	}
}

function persistEnhancementStatus(response = {}) {
	const key = getPersistedStatusKey();
	const token = response.token || activeJob.value?.token || preview.value?.token;

	if (!key || !token) {
		return;
	}

	const status = {
		assetId: String(props.assetId),
		token,
		jobId: response.jobId || activeJob.value?.jobId || null,
		statusUrl: response.statusUrl || activeJob.value?.statusUrl || (apiTransport.value === 'actions' ? getActionUrl('status') : ''),
		savedAt: Date.now(),
	};

	try {
		window.localStorage.setItem(key, JSON.stringify(status));
		log(`function/persistEnhancementStatus/asset/${props.assetId}`);
	} catch (error) {
		log(`function/persistEnhancementStatus/error/${error instanceof Error ? error.message : 'unknown'}`);
	}
}

function clearPersistedEnhancementStatus() {
	const key = getPersistedStatusKey();

	if (!key) {
		return;
	}

	try {
		window.localStorage.removeItem(key);
	} catch (error) {
		log(`function/clearPersistedEnhancementStatus/error/${error instanceof Error ? error.message : 'unknown'}`);
	}
}

async function postAction(url, payload) {
	log(`function/postAction/${url}`);
	const formData = new FormData();

	Object.entries(payload).forEach(([key, value]) => {
		if (value !== null && value !== undefined && value !== '') {
			formData.append(key, String(value));
		}
	});

	if (props.csrfTokenName && props.csrfTokenValue) {
		formData.append(props.csrfTokenName, props.csrfTokenValue);
	}

	const response = await fetch(url, {
		method: 'POST',
		body: formData,
		credentials: props.apiCredentials,
		headers: {
			Accept: 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
		},
	});
	const data = await response.json().catch(() => null);

	if (!response.ok || data?.success === false) {
		throw new Error(data?.message || data?.error || `Request failed with status ${response.status}.`);
	}

	return data || {};
}

async function requestApi(action, payload = {}) {
	if (apiTransport.value === 'graphql') {
		return postGraphqlAction(action, payload);
	}

	return postAction(getActionUrl(action), payload);
}

async function postGraphqlAction(action, payload) {
	const operation = getGraphqlOperation(action);

	if (!props.graphqlEndpoint) {
		throw new Error('GraphQL endpoint is missing.');
	}
	if (!operation.query) {
		throw new Error(`GraphQL operation for "${action}" is not configured.`);
	}

	log(`function/postGraphqlAction/${action}`);

	const headers = {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		...props.graphqlHeaders,
	};

	if (props.graphqlToken && !headers.Authorization) {
		headers.Authorization = `Bearer ${props.graphqlToken}`;
	}
	if (props.csrfTokenValue && !headers['X-CSRF-Token']) {
		headers['X-CSRF-Token'] = props.csrfTokenValue;
	}

	const response = await fetch(props.graphqlEndpoint, {
		method: 'POST',
		credentials: props.apiCredentials,
		headers,
		body: JSON.stringify({
			query: operation.query,
			operationName: operation.operationName || undefined,
			variables: getGraphqlVariables(operation, payload),
		}),
	});
	const data = await response.json().catch(() => null);

	if (!response.ok) {
		throw new Error(getGraphqlErrorMessage(data) || `GraphQL request failed with status ${response.status}.`);
	}
	if (data?.errors?.length) {
		throw new Error(getGraphqlErrorMessage(data));
	}

	const result = getGraphqlResult(action, data, operation);
	if (result?.success === false) {
		throw new Error(result.message || result.error || `GraphQL ${action} operation failed.`);
	}

	return result || {};
}

function getGraphqlOperation(action) {
	const operation = props.graphqlOperations?.[action];

	if (typeof operation === 'string') {
		return { query: operation };
	}

	return operation && typeof operation === 'object' ? operation : {};
}

function getGraphqlVariables(operation, payload) {
	if (typeof operation.variables === 'function') {
		return operation.variables(payload);
	}

	if (operation.variables && typeof operation.variables === 'object') {
		return operation.variables;
	}

	return {
		input: payload,
		...payload,
	};
}

function getGraphqlResult(action, data, operation) {
	const candidates = [
		operation.dataPath,
		`imageEnhancer${capitalize(action)}`,
		`articleImage${capitalize(action)}`,
		action,
	].filter(Boolean);

	for (const path of candidates) {
		const result = getPath(data?.data, path);
		if (result !== undefined && result !== null) {
			return result;
		}
	}

	const values = data?.data && typeof data.data === 'object' ? Object.values(data.data) : [];
	if (values.length === 1) {
		return values[0];
	}

	throw new Error(`GraphQL response did not include data for "${action}".`);
}

function getGraphqlErrorMessage(data) {
	return (data?.errors || [])
		.map((error) => error?.message)
		.filter(Boolean)
		.join(' ');
}

function getPath(source, path) {
	if (!source || !path) {
		return undefined;
	}

	const segments = Array.isArray(path) ? path : String(path).split('.');

	return segments.reduce((value, segment) => {
		if (value === undefined || value === null) {
			return undefined;
		}

		return value[segment];
	}, source);
}

function capitalize(value) {
	const stringValue = String(value || '');

	return stringValue.charAt(0).toUpperCase() + stringValue.slice(1);
}

function handleImageError(event) {
	if (!props.fallbackSrc || event.target.src === props.fallbackSrc) {
		return;
	}

	event.target.onerror = null;
	isImageLoaded.value = false;
	event.target.src = props.fallbackSrc;
}

function handleImageLoad() {
	isImageLoaded.value = true;
}

function handleCompareOriginalLoad() {
	isCompareOriginalLoaded.value = true;
}

function handleCompareEnhancedLoad() {
	isCompareEnhancedLoaded.value = true;
}

function handleEnhancedImageError() {
	handleError(new Error('The enhanced preview image could not be loaded.'));
}

function handleError(error) {
	const message = error instanceof Error ? error.message : 'Something went wrong while enhancing this image.';

	errorMessage.value = message;
	log(`function/error/${message}`);
	emit('error', error);
}

function log(message) {
	if (component?.$logger) {
		component.$logger(`Vue/components/imageEnhancer/${message}`);
	}
}
</script>

<style scoped>
.article-image-container {
	aspect-ratio: var(--article-image-aspect-ratio, 16 / 9);
	overflow: hidden;
	background: #f1f1f1;
}

.article-image-single,
.article-image-compare {
	display: block;
	width: 100%;
	height: 100%;
	opacity: 0;
	transition: opacity 160ms ease;
}

.article-image-single {
	object-fit: cover;
}

.article-image-container.is-loaded .article-image-single,
.article-image-container.is-loaded .article-image-compare {
	opacity: 1;
}

.article-image-compare {
	position: relative;
	overflow: hidden;
	background: #f1f1f1;
}

.article-image-compare-image {
	position: absolute;
	inset: 0;
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.article-image-compare-original {
	z-index: 1;
}

.article-image-compare-enhanced-wrap {
	position: absolute;
	inset: 0;
	z-index: 2;
	overflow: hidden;
	clip-path: inset(0 calc(100% - var(--article-image-compare-position, 50%)) 0 0);
}

.article-image-compare-enhanced {
	z-index: 2;
}

.article-image-compare-divider {
	position: absolute;
	top: 0;
	bottom: 0;
	left: var(--article-image-compare-position, 50%);
	z-index: 4;
	width: 2px;
	background: rgba(255, 255, 255, 0.88);
	box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.16);
	transform: translateX(-50%);
	pointer-events: none;
}

.article-image-compare-handle {
	position: absolute;
	top: 50%;
	left: var(--article-image-compare-position, 50%);
	z-index: 4;
	width: 34px;
	height: 34px;
	border: 2px solid rgba(255, 255, 255, 0.95);
	border-radius: 50%;
	background: rgba(0, 0, 0, 0.38);
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.24);
	transform: translate(-50%, -50%);
	pointer-events: none;
}

.article-image-compare-handle::before,
.article-image-compare-handle::after {
	content: '';
	position: absolute;
	top: 50%;
	width: 7px;
	height: 7px;
	border-top: 2px solid #fff;
	border-left: 2px solid #fff;
}

.article-image-compare-handle::before {
	left: 8px;
	transform: translateY(-50%) rotate(-45deg);
}

.article-image-compare-handle::after {
	right: 8px;
	transform: translateY(-50%) rotate(135deg);
}

.article-image-compare-range {
	position: absolute;
	inset: 0;
	z-index: 5;
	width: 100%;
	height: 100%;
	margin: 0;
	cursor: ew-resize;
	opacity: 0;
}

.article-image-action-bar {
	position: absolute;
	top: 12px;
	right: 12px;
	left: auto;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: flex-end;
	width: max-content;
	max-width: calc(100% - 24px);
	padding: 5px;
	border-radius: 6px;
	background: rgba(0, 0, 0, 0.38);
	backdrop-filter: blur(4px);
	z-index: 8;
}

.article-image-action-bar.has-provider-controls {
	flex-direction: column;
	align-items: flex-end;
	width: min(286px, calc(100% - 24px));
}

.article-image-provider-controls {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 6px;
	width: 100%;
}

.article-image-provider-field {
	display: flex;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
	margin: 0;
	color: #fff;
	font-size: 10px;
	font-weight: 700;
	line-height: 1.2;
	text-shadow: none;
}

.article-image-provider-select {
	width: 100%;
	min-height: 30px;
	border: 1px solid rgba(255, 255, 255, 0.7);
	border-radius: 4px;
	background: rgba(255, 255, 255, 0.92);
	color: #222;
	font-size: 12px;
	line-height: 1.2;
}

.article-image-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	min-height: 30px;
	border-radius: 4px;
	font-weight: 700;
	line-height: 28px;
	white-space: nowrap;
	text-shadow: none;
}

.article-image-action-bar.has-provider-controls .article-image-button {
	min-width: 96px;
}

.article-image-button-primary,
.article-image-button-primary:disabled {
	border-color: #ff5a00;
	background: #ff5a00;
	color: #fff;
}

.article-image-button-secondary,
.article-image-button-secondary:disabled {
	border-color: rgba(255, 255, 255, 0.7);
	background: rgba(0, 0, 0, 0.35);
	color: #fff;
}

.article-image-button:disabled {
	opacity: 0.65;
}

.article-image-status {
	position: absolute;
	left: 12px;
	top: 12px;
	display: inline-flex;
	align-items: center;
	gap: 7px;
	max-width: calc(100% - 24px);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	border-radius: 4px;
	background: #ff5a00;
	color: #fff;
	font-weight: 700;
	z-index: 8;
}

.article-image-ui-toggle {
	position: absolute;
	right: 12px;
	bottom: 12px;
	z-index: 9;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-height: 28px;
	padding: 0 10px;
	border: 1px solid rgba(255, 255, 255, 0.64);
	border-radius: 4px;
	background: rgba(0, 0, 0, 0.42);
	color: #fff;
	font-size: 11px;
	font-weight: 700;
	line-height: 26px;
	text-shadow: none;
	white-space: nowrap;
	cursor: pointer;
}

.article-image-ui-toggle:hover,
.article-image-ui-toggle:focus {
	background: rgba(0, 0, 0, 0.58);
	color: #fff;
}

.article-image-container.is-ui-hidden .article-image-ui-toggle {
	border-color: #ff5a00;
	background: #ff5a00;
}

.article-image-spinner {
	display: inline-block;
	flex: 0 0 auto;
	width: 14px;
	height: 14px;
	border: 2px solid rgba(255, 255, 255, 0.45);
	border-top-color: #fff;
	border-radius: 50%;
	animation: article-image-spin 0.8s linear infinite;
}

.article-image-button-secondary .article-image-spinner {
	border-color: rgba(255, 255, 255, 0.35);
	border-top-color: #fff;
}

@keyframes article-image-spin {
	to {
		transform: rotate(360deg);
	}
}

.article-image-error {
	position: absolute;
	left: 12px;
	right: 12px;
	top: 56px;
	z-index: 8;
	margin: 0;
	padding: 8px 10px;
	border-radius: 4px;
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 14px;
}

.article-image-error span {
	flex: 1 1 auto;
	min-width: 0;
}

.article-image-error-reset {
	flex: 0 0 auto;
	min-height: 28px;
	border: 1px solid rgba(255, 255, 255, 0.72);
	border-radius: 4px;
	background: rgba(255, 255, 255, 0.18);
	color: inherit;
	font-size: 12px;
	font-weight: 700;
	line-height: 1;
	cursor: pointer;
}

.article-image-error-reset:disabled {
	cursor: default;
	opacity: 0.65;
}

.article-image-container.is-processing img {
	filter: brightness(0.82);
}

@media (max-width: 640px) {
	.article-image-action-bar {
		left: auto;
		right: 12px;
	}

	.article-image-button {
		flex: 1 1 auto;
		white-space: normal;
	}

	.article-image-action-bar.has-provider-controls,
	.article-image-provider-controls,
	.article-image-provider-field {
		width: 100%;
	}
}
</style>
