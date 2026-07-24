<template>
  <section
    class="image-creator"
    :class="{
      'image-creator--field': mode === 'field',
      pane: mode === 'library',
    }"
  >
    <header class="image-creator__header">
      <div>
        <h2>{{ mode === 'field' ? 'Create image for this field' : 'Generate image' }}</h2>
        <p v-if="mode === 'library'">Create an image from one of the configured templates.</p>
      </div>
      <button
        v-if="mode === 'field'"
        type="button"
        class="image-creator__close"
        :disabled="busy"
        aria-label="Close"
        title="Close"
        @click="close"
      >
        &times;
      </button>
    </header>

    <div v-if="!creatorConfig.available" class="image-creator__notice error">
      {{ creatorConfig.message || 'Image Creator is not configured.' }}
    </div>

    <form v-else @submit.prevent="generate">
      <div class="image-creator__selectors">
        <label class="image-creator__field">
          <span>Template</span>
          <select v-model="form.template" class="select fullwidth">
            <option v-for="template in templates" :key="template.id" :value="template.id">
              {{ template.name }}
            </option>
          </select>
        </label>

        <label v-if="formats.length" class="image-creator__field">
          <span>Format</span>
          <select v-model="form.templateFormat" class="select fullwidth">
            <option v-for="format in formats" :key="format.id" :value="format.id">
              {{ format.name }}
            </option>
          </select>
        </label>

        <label v-if="mode === 'library'" class="image-creator__field">
          <span>Save to volume</span>
          <select v-model.number="selectedVolumeId" class="select fullwidth">
            <option :value="0" disabled>Choose a volume</option>
            <option v-for="volume in volumes" :key="volume.id" :value="volume.id">
              {{ volume.name }}
            </option>
          </select>
        </label>
      </div>

      <div v-if="loadingTemplate" class="image-creator__inline-status">
        <span class="spinner" aria-hidden="true"></span>
        Loading template...
      </div>

      <div v-else-if="textElements.length" class="image-creator__text-fields">
        <label v-for="element in textElements" :key="element" class="image-creator__field">
          <span>{{ element }}</span>
          <textarea
            v-model="form.elements[element]"
            class="text fullwidth"
            rows="2"
          ></textarea>
        </label>
      </div>

      <div class="image-creator__uploads">
        <div
          v-for="source in sourceFields"
          :key="source.key"
          class="image-creator__upload"
          :class="{'is-dragging': uploads[source.key].dragging}"
          @dragover.prevent="uploads[source.key].dragging = true"
          @dragleave.prevent="uploads[source.key].dragging = false"
          @drop.prevent="dropFile(source.key, $event)"
        >
          <div class="image-creator__upload-heading">
            <strong>{{ source.label }}</strong>
            <button
              v-if="uploads[source.key].preview"
              type="button"
              class="btn small"
              @click="clearUpload(source.key)"
            >
              Remove
            </button>
          </div>

          <label class="image-creator__dropzone">
            <input
              type="file"
              accept="image/jpeg,image/png"
              @change="selectFile(source.key, $event)"
            >
            <img
              v-if="uploads[source.key].preview"
              :src="uploads[source.key].preview"
              :alt="source.label"
            >
            <span v-else>{{ uploads[source.key].uploading ? 'Uploading...' : 'Choose or drop an image' }}</span>
          </label>
        </div>
      </div>

      <div v-if="resultUrl" class="image-creator__result">
        <div class="image-creator__result-heading">
          <h3>Generated image</h3>
          <a :href="resultUrl" target="_blank" rel="noopener">Open original</a>
        </div>
        <img :src="resultUrl" alt="Generated image preview">
      </div>

      <div v-if="errorMessage" class="image-creator__notice error" role="alert">
        {{ errorMessage }}
      </div>

      <div v-if="savedAsset" class="image-creator__notice success">
        Saved as
        <a :href="savedAsset.cpEditUrl" target="_blank" rel="noopener">{{ savedAsset.filename }}</a>.
      </div>

      <footer class="image-creator__footer">
        <div v-if="busy" class="image-creator__inline-status">
          <span class="spinner" aria-hidden="true"></span>
          {{ busyLabel }}
        </div>
        <div class="image-creator__buttons">
          <button type="button" class="btn" :disabled="busy" @click="reset">
            Reset
          </button>
          <button type="submit" class="btn submit" :disabled="busy || loadingTemplate">
            Generate
          </button>
          <button
            v-if="resultUrl && generationToken"
            type="button"
            class="btn submit"
            :disabled="busy || (mode === 'library' && !selectedVolumeId)"
            @click="save"
          >
            {{ mode === 'field' ? 'Add to field' : 'Save to Assets' }}
          </button>
        </div>
      </footer>
    </form>
  </section>
</template>

<script setup>
import {computed, onBeforeUnmount, reactive, ref, watch} from 'vue';

const props = defineProps({
  config: {
    type: Object,
    default: () => ({}),
  },
  mode: {
    type: String,
    default: 'library',
  },
  fieldContext: {
    type: Object,
    default: () => ({}),
  },
  onSaved: {
    type: Function,
    default: null,
  },
  onClose: {
    type: Function,
    default: null,
  },
});

const creatorConfig = computed(() => props.config || {});
const templates = computed(() => Array.isArray(creatorConfig.value.templates) ? creatorConfig.value.templates : []);
const volumes = computed(() => Array.isArray(creatorConfig.value.volumes) ? creatorConfig.value.volumes : []);
const sourceFields = [
  {key: 'imageUrl', label: 'Image A'},
  {key: 'imageTeaserUrl', label: 'Image B'},
];
const form = reactive({
  template: creatorConfig.value.defaultTemplate || templates.value[0]?.id || '',
  templateFormat: '',
  elements: {},
});
const uploads = reactive({
  imageUrl: createUploadState(),
  imageTeaserUrl: createUploadState(),
});
const formats = ref([]);
const textElements = ref([]);
const loadingTemplate = ref(false);
const generating = ref(false);
const saving = ref(false);
const generationToken = ref('');
const resultUrl = ref('');
const errorMessage = ref('');
const savedAsset = ref(null);
const selectedVolumeId = ref(volumes.value[0]?.id || 0);
let templateRequest = 0;

const sourceUploading = computed(
  () => uploads.imageUrl.uploading || uploads.imageTeaserUrl.uploading,
);
const busy = computed(() => generating.value || saving.value || sourceUploading.value);
const busyLabel = computed(() => {
  if (saving.value) {
    return 'Saving image...';
  }
  if (generating.value) {
    return 'Generating image...';
  }

  return 'Uploading source image...';
});

watch(
  () => form.template,
  () => loadTemplate(),
  {immediate: true},
);

watch(
  volumes,
  (items) => {
    if (!selectedVolumeId.value && items.length) {
      selectedVolumeId.value = items[0].id;
    }
  },
);

onBeforeUnmount(() => {
  Object.keys(uploads).forEach(clearObjectUrl);
});

async function loadTemplate() {
  const requestId = ++templateRequest;
  invalidateResult();
  formats.value = [];
  textElements.value = [];
  form.templateFormat = '';
  form.elements = {};
  errorMessage.value = '';
  if (!form.template || !creatorConfig.value.available) {
    return;
  }

  loadingTemplate.value = true;
  try {
    const response = await sendAction('templateData', {
      template: form.template,
    });
    if (requestId !== templateRequest) {
      return;
    }

    formats.value = Array.isArray(response.formats) ? response.formats : [];
    form.templateFormat = formats.value[0]?.id || '';
    textElements.value = Array.isArray(response.textElements) ? response.textElements : [];
    textElements.value.forEach((name) => {
      form.elements[name] = '';
    });
  } catch (error) {
    if (requestId === templateRequest) {
      showError(error);
    }
  } finally {
    if (requestId === templateRequest) {
      loadingTemplate.value = false;
    }
  }
}

async function selectFile(key, event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (file) {
    await uploadFile(key, file);
  }
}

async function dropFile(key, event) {
  uploads[key].dragging = false;
  const file = event.dataTransfer?.files?.[0];
  if (file) {
    await uploadFile(key, file);
  }
}

async function uploadFile(key, file) {
  errorMessage.value = '';
  if (!['image/jpeg', 'image/png'].includes(file.type)) {
    errorMessage.value = 'Only JPEG and PNG source images are supported.';
    return;
  }

  invalidateResult();
  clearObjectUrl(key);
  uploads[key].preview = URL.createObjectURL(file);
  uploads[key].objectUrl = uploads[key].preview;
  uploads[key].uploading = true;
  try {
    const data = new FormData();
    data.append('file', file);
    const response = await sendAction('upload', data, true);
    uploads[key].url = response.url;
  } catch (error) {
    clearUpload(key);
    showError(error);
  } finally {
    uploads[key].uploading = false;
  }
}

function clearUpload(key) {
  invalidateResult();
  clearObjectUrl(key);
  uploads[key].preview = '';
  uploads[key].url = '';
  uploads[key].uploading = false;
  uploads[key].dragging = false;
}

function clearObjectUrl(key) {
  if (uploads[key]?.objectUrl) {
    URL.revokeObjectURL(uploads[key].objectUrl);
    uploads[key].objectUrl = '';
  }
}

async function generate() {
  errorMessage.value = '';
  savedAsset.value = null;
  resultUrl.value = '';
  generationToken.value = '';
  generating.value = true;
  try {
    const response = await sendAction('generate', {
      template: form.template,
      templateFormat: form.templateFormat,
      imageUrl: uploads.imageUrl.url,
      imageTeaserUrl: uploads.imageTeaserUrl.url,
      elements: form.elements,
    });
    resultUrl.value = response.url;
    generationToken.value = response.generationToken;
  } catch (error) {
    showError(error);
  } finally {
    generating.value = false;
  }
}

async function save() {
  if (!generationToken.value) {
    return;
  }

  errorMessage.value = '';
  saving.value = true;
  try {
    const payload = {
      generationToken: generationToken.value,
    };
    if (props.mode === 'field') {
      Object.assign(payload, {
        fieldId: props.fieldContext.fieldId || 0,
        elementId: props.fieldContext.elementId || 0,
        siteId: props.fieldContext.siteId || 0,
      });
    } else {
      payload.volumeId = selectedVolumeId.value;
    }

    const response = await sendAction('save', payload);
    savedAsset.value = response;
    generationToken.value = '';
    if (typeof props.onSaved === 'function') {
      await props.onSaved(response);
    }
    window.Craft?.cp?.displayNotice?.(
      props.mode === 'field' ? 'Generated image added to the field.' : 'Generated image saved to Assets.',
    );
    if (props.mode === 'field') {
      close(true);
    }
  } catch (error) {
    showError(error);
  } finally {
    saving.value = false;
  }
}

function reset() {
  clearUpload('imageUrl');
  clearUpload('imageTeaserUrl');
  textElements.value.forEach((name) => {
    form.elements[name] = '';
  });
  resultUrl.value = '';
  generationToken.value = '';
  savedAsset.value = null;
  errorMessage.value = '';
}

function invalidateResult() {
  resultUrl.value = '';
  generationToken.value = '';
  savedAsset.value = null;
}

function close(force = false) {
  if ((force || !busy.value) && typeof props.onClose === 'function') {
    props.onClose();
  }
}

async function sendAction(routeName, data, multipart = false) {
  const route = creatorConfig.value.routes?.[routeName];
  if (!route || !window.Craft?.sendActionRequest) {
    throw new Error('Image Creator actions are unavailable.');
  }

  try {
    const options = {data};
    if (multipart) {
      options.headers = {'Content-Type': 'multipart/form-data'};
    }
    const response = await window.Craft.sendActionRequest('POST', route, options);
    if (!response.data?.success) {
      throw new Error(response.data?.message || 'The Image Creator request failed.');
    }

    return response.data;
  } catch (error) {
    throw new Error(
      error?.response?.data?.message ||
      error?.message ||
      'The Image Creator request failed.',
    );
  }
}

function showError(error) {
  errorMessage.value = error instanceof Error ? error.message : 'The Image Creator request failed.';
}

function createUploadState() {
  return {
    url: '',
    preview: '',
    objectUrl: '',
    uploading: false,
    dragging: false,
  };
}
</script>

<style>
.image-creator {
  box-sizing: border-box;
  width: 100%;
}

.image-creator--field {
  max-height: min(820px, calc(100vh - 64px));
  padding: 24px;
  overflow: auto;
}

.image-creator__header,
.image-creator__footer,
.image-creator__result-heading,
.image-creator__upload-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.image-creator__header {
  align-items: flex-start;
  margin-bottom: 20px;
}

.image-creator__header h2,
.image-creator__header p,
.image-creator__result-heading h3 {
  margin: 0;
}

.image-creator__header p {
  margin-top: 4px;
  color: #596673;
}

.image-creator__close {
  appearance: none;
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  padding: 0;
  border: 0;
  border-radius: 4px;
  background: #eef1f4;
  color: #33475b;
  cursor: pointer;
  font-size: 25px;
  line-height: 32px;
}

.image-creator__close:disabled {
  opacity: 0.5;
  cursor: wait;
}

.image-creator__selectors {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.image-creator__field {
  display: block;
  min-width: 0;
}

.image-creator__field > span {
  display: block;
  margin-bottom: 5px;
  font-weight: 700;
}

.image-creator__text-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  margin-top: 18px;
}

.image-creator__uploads {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  margin-top: 18px;
}

.image-creator__upload {
  min-width: 0;
}

.image-creator__upload-heading {
  min-height: 30px;
  margin-bottom: 6px;
}

.image-creator__dropzone {
  position: relative;
  display: grid;
  width: 100%;
  height: 180px;
  place-items: center;
  overflow: hidden;
  border: 1px dashed #9aa5b1;
  border-radius: 6px;
  background: #f3f5f7;
  color: #596673;
  cursor: pointer;
  text-align: center;
}

.image-creator__upload.is-dragging .image-creator__dropzone {
  border-color: #0b69a3;
  background: #e9f4fb;
}

.image-creator__dropzone input {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}

.image-creator__dropzone img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.image-creator__result {
  margin-top: 20px;
}

.image-creator__result-heading {
  margin-bottom: 8px;
}

.image-creator__result img {
  display: block;
  width: 100%;
  max-height: min(52vh, 560px);
  object-fit: contain;
  border-radius: 6px;
  background: #202a33;
}

.image-creator__notice {
  margin-top: 16px;
  padding: 12px 14px;
  border-radius: 4px;
}

.image-creator__notice.error {
  border: 1px solid #f1c8c8;
  background: #fdf2f2;
  color: #b42318;
}

.image-creator__notice.success {
  border: 1px solid #b9ddc3;
  background: #eff8f1;
  color: #246b36;
}

.image-creator__footer {
  min-height: 40px;
  margin-top: 20px;
}

.image-creator__buttons {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-left: auto;
}

.image-creator__inline-status {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  color: #596673;
}

.image-creator__footer .image-creator__inline-status {
  margin-top: 0;
}

.modal.image-creator-cp-modal,
.image-creator-cp-modal {
  box-sizing: border-box;
  width: min(980px, calc(100vw - 64px));
  max-height: calc(100vh - 48px);
  overflow: hidden;
}

@media (max-width: 760px) {
  .image-creator--field {
    padding: 16px;
  }

  .image-creator__selectors {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .image-creator__text-fields {
    grid-template-columns: 1fr;
  }

  .image-creator__dropzone {
    height: 140px;
  }

  .image-creator__footer {
    align-items: flex-end;
    flex-direction: column;
  }
}

@media (max-width: 480px) {
  .modal.image-creator-cp-modal,
  .image-creator-cp-modal {
    width: calc(100vw - 24px);
  }

  .image-creator__selectors,
  .image-creator__uploads {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  .image-creator__dropzone {
    height: 112px;
    padding: 8px;
    font-size: 12px;
  }
}
</style>
