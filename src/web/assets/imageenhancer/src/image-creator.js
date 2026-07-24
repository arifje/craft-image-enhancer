import {createApp} from 'vue';
import ImageCreator from './ImageCreator.vue';

function mount(target, options = {}) {
  const element = typeof target === 'string' ? document.querySelector(target) : target;
  if (!element) {
    throw new Error('Image Creator mount element was not found.');
  }

  const app = createApp(ImageCreator, options);
  const instance = app.mount(element);

  return {
    app,
    instance,
    unmount() {
      app.unmount();
    },
  };
}

function mountPageTools() {
  document.querySelectorAll('[data-image-creator-root]').forEach((element) => {
    if (element.dataset.imageCreatorMounted === '1') {
      return;
    }

    element.dataset.imageCreatorMounted = '1';
    mount(element, {
      mode: 'library',
      config: window.ImageEnhancerCp?.imageCreator || {},
    });
  });
}

window.ImageEnhancerImageCreator = {
  mount,
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountPageTools, {once: true});
} else {
  mountPageTools();
}
