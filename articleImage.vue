<template>
	<div class="uk-relative article-image-container" :class="{ 'is-processing': isBusy }">
		<img
			:key="imageKey"
			:src="currentSrc"
			:srcset="currentSrcset || undefined"
			:sizes="currentSrcset ? sizes : undefined"
			:width="width"
			:height="height"
			:alt="alt"
			:fetchpriority="fetchpriority"
			class="uk-border-rounded uk-width-1-1"
			@error="handleImageError"
		>

		<div v-if="canShowActions" class="article-image-action-bar">
			<button
				v-if="!hasPendingPreview"
				type="button"
				class="uk-button uk-button-small uk-button-primary"
				:disabled="isBusy"
				@click.prevent="enhanceImage"
			>
				{{ isEnhancing ? enhancingLabel : enhanceLabel }}
			</button>

			<template v-else>
				<button
					type="button"
					class="uk-button uk-button-small uk-button-primary"
					:disabled="isBusy"
					@click.prevent="keepEnhancedImage"
				>
					{{ isKeeping ? keepingLabel : keepLabel }}
				</button>
				<button
					type="button"
					class="uk-button uk-button-small uk-button-default"
					:disabled="isBusy"
					@click.prevent="discardEnhancedImage"
				>
					{{ isDiscarding ? discardingLabel : discardLabel }}
				</button>
			</template>
		</div>

		<div v-if="isBusy" class="article-image-status uk-label">
			{{ statusLabel }}
		</div>

		<div v-if="errorMessage" class="article-image-error uk-alert-danger" role="alert">
			{{ errorMessage }}
		</div>

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
import { computed, ref, watch } from 'vue';

// Expected endpoint contract:
// enhanceUrl returns { success: true, enhancedUrl: "...", previewId: "..." }.
// keepUrl returns { success: true, imageUrl: "..." } after replacing the original asset.
// discardUrl returns { success: true } after removing the temporary enhanced preview.
const props = defineProps({
	assetId: {
		type: [Number, String],
		default: null,
	},
	enhanceable: {
		type: Boolean,
		default: true,
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
	enhanceUrl: {
		type: String,
		required: true,
	},
	keepUrl: {
		type: String,
		required: true,
	},
	discardUrl: {
		type: String,
		required: true,
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
		default: 'Enhance thumbnail',
	},
	enhancingLabel: {
		type: String,
		default: 'Enhancing...',
	},
	keepLabel: {
		type: String,
		default: 'Keep enhanced image',
	},
	keepingLabel: {
		type: String,
		default: 'Keeping...',
	},
	discardLabel: {
		type: String,
		default: 'Discard enhanced image',
	},
	discardingLabel: {
		type: String,
		default: 'Discarding...',
	},
});

const emit = defineEmits(['enhanced', 'kept', 'discarded', 'error']);

const originalSrc = ref(props.src || props.fallbackSrc);
const originalSrcset = ref(props.srcset);
const currentSrc = ref(originalSrc.value);
const currentSrcset = ref(originalSrcset.value);
const imageKey = ref(0);
const preview = ref(null);
const errorMessage = ref('');
const isEnhancing = ref(false);
const isKeeping = ref(false);
const isDiscarding = ref(false);

const isBusy = computed(() => isEnhancing.value || isKeeping.value || isDiscarding.value);
const hasPendingPreview = computed(() => preview.value !== null);
const canShowActions = computed(() => Boolean(props.enhanceable && props.assetId && props.enhanceUrl && props.keepUrl && props.discardUrl));
const statusLabel = computed(() => {
	if (isEnhancing.value) {
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
		imageKey.value += 1;
	}
);

async function enhanceImage() {
	errorMessage.value = '';
	isEnhancing.value = true;

	try {
		const response = await postAction(props.enhanceUrl, {
			assetId: props.assetId,
		});
		const enhancedUrl = response.enhancedUrl || response.imageUrl || response.assetUrl || response.url;
		const previewId = response.previewId || response.previewToken || response.enhancedAssetId || response.tempAssetId || null;

		if (!enhancedUrl) {
			throw new Error('The enhancement response did not include an enhanced image URL.');
		}

		preview.value = {
			id: previewId,
			url: enhancedUrl,
			response,
		};
		currentSrc.value = enhancedUrl;
		currentSrcset.value = '';
		imageKey.value += 1;
		emit('enhanced', response);
	} catch (error) {
		handleError(error);
	} finally {
		isEnhancing.value = false;
	}
}

async function keepEnhancedImage() {
	if (!preview.value) {
		return;
	}

	errorMessage.value = '';
	isKeeping.value = true;

	try {
		const response = await postAction(props.keepUrl, previewPayload());
		const keptUrl = response.imageUrl || response.assetUrl || response.url || preview.value.url;

		originalSrc.value = keptUrl;
		originalSrcset.value = '';
		currentSrc.value = keptUrl;
		currentSrcset.value = '';
		preview.value = null;
		imageKey.value += 1;
		emit('kept', response);
	} catch (error) {
		handleError(error);
	} finally {
		isKeeping.value = false;
	}
}

async function discardEnhancedImage() {
	if (!preview.value) {
		return;
	}

	errorMessage.value = '';
	isDiscarding.value = true;

	try {
		const response = await postAction(props.discardUrl, previewPayload());

		currentSrc.value = originalSrc.value;
		currentSrcset.value = originalSrcset.value;
		preview.value = null;
		imageKey.value += 1;
		emit('discarded', response);
	} catch (error) {
		handleError(error);
	} finally {
		isDiscarding.value = false;
	}
}

function previewPayload() {
	return {
		assetId: props.assetId,
		previewId: preview.value?.id,
		previewUrl: preview.value?.url,
	};
}

async function postAction(url, payload) {
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
		credentials: 'same-origin',
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

function handleImageError(event) {
	if (!props.fallbackSrc || event.target.src === props.fallbackSrc) {
		return;
	}

	event.target.onerror = null;
	event.target.src = props.fallbackSrc;
}

function handleError(error) {
	const message = error instanceof Error ? error.message : 'Something went wrong while enhancing this image.';

	errorMessage.value = message;
	emit('error', error);
}
</script>

<style scoped>
.article-image-action-bar {
	position: absolute;
	top: 12px;
	right: 12px;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: flex-end;
	z-index: 3;
}

.article-image-status {
	position: absolute;
	left: 12px;
	top: 12px;
	z-index: 3;
}

.article-image-error {
	position: absolute;
	left: 12px;
	right: 12px;
	top: 56px;
	z-index: 3;
	margin: 0;
	padding: 8px 10px;
	border-radius: 4px;
	font-size: 14px;
}

.article-image-container.is-processing img {
	filter: brightness(0.82);
}
</style>
