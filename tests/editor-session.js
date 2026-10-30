'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const EditorDocument = require('../src/web/assets/imageenhancer/dist/js/editor-document.js');
const context = { window: {}, Image: class { async decode() { this.naturalWidth = 800; } } };
vm.runInNewContext(fs.readFileSync(require.resolve('../src/web/assets/imageenhancer/dist/js/image-editor.js'), 'utf8'), context);
const Editor = context.window.ImageEnhancerEditor;
(async () => {
    const editor = Object.create(Editor.prototype);
    const busyStates = [];
    Object.assign(editor, {
        images: new Map(), previews: new Map(), doc: new EditorDocument(800, 600),
        refresh() {}, tab() {}, host: {
            closeButton: {}, setBusy(value) { busyStates.push(value); }, setPreviewProcessing() {}, setPreviewMode() {}, setStatus() {},
            showError(error) { throw error; },
        },
    });
    const shape = editor.doc.add('rect');
    Object.assign(editor.host, { token: 'first', previewId: 11, enhancedUrl: '/first.png' });
    await editor.acceptPreview();
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
    assert.deepEqual(busyStates, [false, false], 'Accepting each result restores all action buttons');
    assert.equal(editor.dirty, true);
    editor.doc.saved = editor.doc.snapshot();
    assert.equal(editor.dirty, false);
    console.log('Editor session regression checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
