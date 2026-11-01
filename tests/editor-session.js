'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const EditorDocument = require('../src/web/assets/imageenhancer/dist/js/editor-document.js');
const context = { window: {}, Image: class { async decode() { this.naturalWidth = this.src === '/crop.png' ? 400 : 800; this.naturalHeight = 600; } } };
vm.runInNewContext(fs.readFileSync(require.resolve('../src/web/assets/imageenhancer/dist/js/image-editor.js'), 'utf8'), context);
const Editor = context.window.ImageEnhancerEditor;
(async () => {
    const editor = Object.create(Editor.prototype);
    const busyStates = [];
    const node = () => ({ hidden: false, style: {}, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, classList: { toggle() {} } });
    const storage = new Map(); context.window.localStorage = { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) };
    Object.assign(editor, {
        images: new Map(), previews: new Map(), doc: new EditorDocument(800, 600),
        refresh() {}, tab() {}, renderSelection() {}, state: 'idle',
        comparisonBar: node(), comparisonImage: node(), comparisonDivider: node(), compareButton: node(), comparisonRange: { ...node(), value: '50' },
        themeButton: node(), root: { dataset: {} }, host: {
            closeButton: {}, setBusy(value) { busyStates.push(value); editor.state = value ? 'busy' : 'idle'; }, setActionState(value) { editor.state = value; }, setPreviewProcessing() {}, setPreviewMode() {}, setStatus() {},
            showError(error) { throw error; },
        },
    });
    const shape = editor.doc.add('rect');
    Object.assign(editor.host, { token: 'first', previewId: 11, enhancedUrl: '/first.png' });
    await editor.acceptPreview();
    assert.equal(editor.comparing, true, 'Accepted AI result keeps comparison open');
    assert.equal(editor.comparisonBar.hidden, false, 'Comparison stays visible after image decoding');
    editor.comparisonRange.value = 25; editor.updateComparison();
    assert.equal(editor.comparisonImage.style.clipPath, 'inset(0 75% 0 0)');
    editor.setComparing(false); assert.equal(editor.comparisonBar.hidden, true);
    editor.setComparing(true); assert.equal(editor.comparisonBar.hidden, false, 'Comparison can be reopened without accepting again');
    editor.restoreTheme(); assert.equal(editor.root.dataset.theme, 'dark');
    const snapshot = editor.doc.snapshot(); editor.setTheme('light');
    assert.equal(editor.root.dataset.theme, 'light'); assert.equal(storage.get('imageEnhancerTheme'), 'light');
    assert.equal(editor.themeButton.attrs['aria-label'], 'Use dark theme');
    editor.theme = 'dark'; editor.restoreTheme(); assert.equal(editor.theme, 'light', 'Theme choice survives a new editor session');
    context.window.localStorage = { getItem() { throw Error('Unavailable'); }, setItem() { throw Error('Unavailable'); } };
    editor.restoreTheme(); editor.setTheme('light'); assert.equal(editor.theme, 'light', 'Theme works without browser storage');
    assert.equal(editor.doc.snapshot(), snapshot, 'Theme changes never modify the image document');
    assert.equal(editor.sourcePayload().sourceToken, 'first');
    assert.equal(editor.doc.layers[0].id, shape.id);
    editor.doc.add('blur');
    Object.assign(editor.host, { token: 'second', previewId: 12, enhancedUrl: '/second.png' });
    await editor.acceptPreview();
    assert.equal(editor.sourcePayload().sourceToken, 'second');
    assert.equal(editor.doc.layers.length, 2);
    assert.equal(editor.images.get('preview-first').src, '/first.png', 'Earlier results remain distinct for undo');
    editor.doc.undo();
    assert.equal(editor.sourcePayload().sourceToken, 'first', 'Undo chooses the actual earlier AI input');
    assert.equal(editor.doc.layers.length, 2, 'Undoing AI preserves the preceding layers');
    editor.doc.undo();
    assert.equal(editor.doc.layers.length, 1, 'Layer changes remain independently undoable');
    editor.doc.undo();
    assert.equal(editor.sourcePayload().sourceToken, '', 'Undo to original uses the original input');
    editor.doc.redo();
    assert.equal(editor.sourcePayload().sourceToken, 'first');
    assert.equal(editor.previews.size, 2, 'All accepted results are tracked for cleanup');
    assert.deepEqual(busyStates, [true, false, true, false], 'Accepting each result restores all action buttons');
    assert.equal(editor.dirty, true);
    editor.doc.saved = editor.doc.snapshot();
    assert.equal(editor.dirty, false);
    Object.assign(editor.host, { token: 'crop', previewId: 13, enhancedUrl: '/crop.png', operation: 'reformat', previewResponse: {editorTransform: {scaleX: 1, scaleY: 1, offsetX: -200, offsetY: 0}} });
    await editor.acceptPreview();
    assert.equal(editor.doc.width, 400); assert.equal(editor.doc.height, 600);
    assert.equal(editor.sourcePayload().sourceToken, 'crop');
    const cropped = editor.doc.snapshot();
    editor.doc.undo(); assert.equal(editor.doc.width, 800); assert.equal(editor.sourcePayload().sourceToken, 'first');
    editor.doc.redo(); assert.equal(editor.doc.snapshot(), cropped);
    console.log('Editor session regression checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
