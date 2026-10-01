(function () {
	'use strict';
	const paths = {
		move: 'M5 3l14 10-7 1-3 7z', hand: 'M8 12V6a2 2 0 014 0v5-1V4a2 2 0 014 0v7-3a2 2 0 014 0v7c0 5-3 7-7 7-3 0-5-2-7-5l-3-5a2 2 0 013-2z',
		rect: 'M4 4h16v16H4z', ellipse: 'M20 12a8 8 0 11-16 0 8 8 0 0116 0', text: 'M4 5V3h16v2M12 3v18M8 21h8',
		image: 'M3 3h18v18H3zM3 17l6-6 4 4 3-3 5 5M15 7h.01', blur: 'M12 3c-3 4-7 8-7 12a7 7 0 0014 0c0-4-4-8-7-12zM8 15h8M9 18h6',
		undo: 'M9 4L4 9l5 5M4 9h10a6 6 0 010 12', redo: 'M15 4l5 5-5 5M20 9H10a6 6 0 000 12',
		fit: 'M8 3H3v5M16 3h5v5M3 16v5h5M21 16v5h-5', download: 'M12 3v12m-5-5l5 5 5-5M4 17v4h16v-4',
		eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7zM15 12a3 3 0 11-6 0 3 3 0 016 0',
		lock: 'M5 10h14v11H5zM8 10V6a4 4 0 018 0v4', trash: 'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7',
		copy: 'M8 8h13v13H8zM16 8V3H3v13h5', up: 'M5 15l7-7 7 7', down: 'M5 9l7 7 7-7', spark: 'M12 2l3 7 7 3-7 3-3 7-3-7-7-3 7-3z',
	};
	const icon = (name) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${paths[name] || paths.rect}"/></svg>`;
	const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
	const el = (tag, className, text) => { const node = document.createElement(tag); node.className = className || ''; if (text !== undefined) node.textContent = text; return node; };
	function button(label, name, callback, className = '') {
		const node = el('button', 'ie-button ' + className);
		node.type = 'button'; node.title = label; node.setAttribute('aria-label', label);
		node.innerHTML = icon(name); node.addEventListener('click', callback); return node;
	}
	async function loadImage(src) {
		const image = new Image(); image.src = src;
		await image.decode();
		if (!image.naturalWidth) throw new Error('The image could not be decoded.');
		return image;
	}
	class ImageEditor {
		constructor(host) {
			this.host = host; this.root = host.root; this.images = new Map(); this.urls = new Set();
			this.tool = 'move'; this.selectedId = null; this.zoom = 1; this.fitted = true; this.state = 'loading';
			this.pendingAi = false; this.saving = false; this.disposed = false; this.importing = false;
			this.events = new AbortController(); this.controls = {};
			this.mount();
			this.initialize().catch((error) => { this.host.showError(error); this.message.textContent = 'Unable to open image'; });
		}
		get selected() { return this.doc?.layers.find((layer) => layer.id === this.selectedId); }
		get dirty() { return Boolean(this.doc?.dirty || this.pendingAi); }
		get editable() { return this.state === 'idle' && !this.saving && !this.importing && Boolean(this.doc); }
		mount() {
			this.root.classList.add('ie-modal'); this.root.setAttribute('role', 'dialog'); this.root.setAttribute('aria-modal', 'true'); this.root.setAttribute('aria-label', 'Image editor');
			const shell = this.root.querySelector('.image-enhancer-cp-shell');
			const body = this.root.querySelector('.image-enhancer-cp-body');
			const header = this.root.querySelector('.image-enhancer-cp-header');
			const intro = header.firstElementChild;
			intro.innerHTML = '<span class="ie-brand">' + icon('spark') + '</span><div><h2>Image editor</h2><p></p></div>';
			intro.className = 'ie-heading'; intro.querySelector('p').textContent = this.host.assetInfo?.filename || 'Image Enhancer';
			const headActions = el('div', 'ie-header-actions');
			this.downloadButton = button('Download image', 'download', () => this.export(false));
			this.saveButton = el('button', 'ie-save', 'Save image'); this.saveButton.type = 'button'; this.saveButton.addEventListener('click', () => this.export(true));
			const maximize = button('Maximize editor', 'fit', () => { this.root.classList.toggle('ie-maximized'); maximize.setAttribute('aria-pressed', String(this.root.classList.contains('ie-maximized'))); });
			headActions.append(this.downloadButton, maximize, this.saveButton, this.host.closeButton); header.append(headActions); shell.prepend(header);
			const workspace = el('div', 'ie-workspace');
			const rail = el('div', 'ie-tools'); rail.setAttribute('role', 'toolbar'); rail.setAttribute('aria-label', 'Image tools');
			this.toolButtons = {};
			for (const [tool, label] of Object.entries({ move: 'Move (V)', hand: 'Pan (H)', rect: 'Rectangle (R)', ellipse: 'Ellipse (O)', text: 'Text (T)', image: 'Add image layer', blur: 'Blur area (B)' })) {
				const control = button(label, tool, () => tool === 'image' ? this.fileInput.click() : this.setTool(tool), 'ie-tool');
				control.append(el('span', '', label.split(' (')[0])); this.toolButtons[tool] = control; rail.append(control);
			}
			const center = el('div', 'ie-center'); const toolbar = el('div', 'ie-toolbar');
			this.undoButton = button('Undo (⌘/Ctrl Z)', 'undo', () => this.history(-1));
			this.redoButton = button('Redo (⌘/Ctrl Shift Z)', 'redo', () => this.history(1));
			const minus = el('button', 'ie-button', '−'); minus.type = 'button'; minus.title = 'Zoom out'; minus.addEventListener('click', () => this.setZoom(this.zoom / 1.25));
			const plus = el('button', 'ie-button', '+'); plus.type = 'button'; plus.title = 'Zoom in'; plus.addEventListener('click', () => this.setZoom(this.zoom * 1.25));
			this.zoomLabel = el('button', 'ie-zoom', '100%'); this.zoomLabel.type = 'button'; this.zoomLabel.title = 'Actual size'; this.zoomLabel.addEventListener('click', () => this.setZoom(1));
			const fit = el('button', 'ie-button ie-fit', 'Fit'); fit.type = 'button'; fit.addEventListener('click', () => { this.fitted = true; this.fit(); });
			this.message = el('span', 'ie-document-info', 'Loading image…');
			toolbar.append(this.undoButton, this.redoButton, el('span', 'ie-separator'), minus, this.zoomLabel, plus, fit, this.message);
			this.viewport = el('div', 'ie-viewport'); this.board = el('div', 'ie-board'); this.artboard = el('div', 'ie-artboard');
			this.canvas = el('canvas', 'ie-canvas'); this.canvas.setAttribute('aria-label', 'Image composition');
			this.selection = el('div', 'ie-selection'); this.selection.hidden = true;
			this.resizeHandle = el('span', 'ie-layer-handle'); this.resizeHandle.title = 'Drag to resize; Shift preserves proportions'; this.selection.append(this.resizeHandle);
			this.artboard.append(this.canvas, this.selection); this.board.append(this.artboard); this.viewport.append(this.board);
			this.legacyStage = this.root.querySelector('.image-enhancer-cp-stage'); this.legacyStage.hidden = true;
			center.append(toolbar, this.viewport, this.legacyStage);
			const sidebar = el('aside', 'ie-sidebar'); const tabs = el('div', 'ie-tabs');
			this.layersPanel = el('section', 'ie-layers-panel'); this.aiPanel = el('section', 'ie-ai-panel');
			this.layersTab = el('button', 'is-active', 'Layers'); this.aiTab = el('button', '', 'AI tools');
			for (const tab of [this.layersTab, this.aiTab]) tab.type = 'button';
			this.layersTab.addEventListener('click', () => this.tab('layers')); this.aiTab.addEventListener('click', () => this.tab('ai')); tabs.append(this.layersTab, this.aiTab);
			const layerHeading = el('div', 'ie-panel-heading'); layerHeading.append(el('h3', '', 'Layers'), el('span', '', 'Top → bottom'));
			this.layerList = el('div', 'ie-layer-list');
			const layerActions = el('div', 'ie-layer-actions');
			this.layerButtons = [
				button('Duplicate layer (⌘/Ctrl D)', 'copy', () => this.duplicate()),
				button('Move layer forward', 'up', () => this.reorder(1)),
				button('Move layer backward', 'down', () => this.reorder(-1)),
				button('Delete layer', 'trash', () => this.remove()),
			];
			layerActions.append(...this.layerButtons); this.layersPanel.append(layerHeading, this.layerList, layerActions);
			this.inspector = el('div', 'ie-inspector'); this.buildInspector(); this.layersPanel.append(this.inspector);
			this.aiPanel.append(el('h3', '', 'Enhance your base image'));
			this.aiNote = el('p', 'ie-help', 'AI works on the base image. Your layers stay editable above it.'); this.aiPanel.append(this.aiNote);
			for (const node of [this.host.providerControls, this.host.customEditPanel, this.host.videoPanel]) this.aiPanel.append(node);
			this.aiPanel.append(this.root.querySelector('.image-enhancer-cp-actions'));
			sidebar.append(tabs, this.layersPanel, this.aiPanel); this.aiPanel.hidden = true;
			workspace.append(rail, center, sidebar); body.replaceChildren(workspace);
			this.fileInput = el('input'); this.fileInput.type = 'file'; this.fileInput.accept = 'image/png,image/jpeg,image/webp'; this.fileInput.multiple = true; this.fileInput.hidden = true;
			this.fileInput.addEventListener('change', () => { this.importFiles([...this.fileInput.files]); this.fileInput.value = ''; }); this.root.append(this.fileInput);
			const footer = this.root.querySelector('.image-enhancer-cp-footer');
			this.hint = el('span', 'ie-hint', 'Drag to move · Shift to resize proportionally · Scroll to navigate'); footer.append(this.hint);
			const grip = button('Resize editor', 'fit', () => {}, 'ie-resize-grip'); footer.append(grip);
			grip.addEventListener('pointerdown', (event) => this.startModalResize(event, grip));
			this.root.addEventListener('keydown', (event) => this.keydown(event), { signal: this.events.signal });
			this.artboard.addEventListener('pointerdown', (event) => this.pointerDown(event));
			this.artboard.addEventListener('pointermove', (event) => this.pointerMove(event));
			this.artboard.addEventListener('pointerup', () => this.pointerEnd());
			this.artboard.addEventListener('pointercancel', () => this.pointerEnd(true));
			this.viewport.addEventListener('wheel', (event) => { if (event.ctrlKey || event.metaKey) { event.preventDefault(); this.setZoom(this.zoom * (event.deltaY < 0 ? 1.1 : 0.9)); } }, { passive: false });
			this.viewport.addEventListener('dragover', (event) => event.preventDefault());
			this.viewport.addEventListener('drop', (event) => { event.preventDefault(); this.importFiles([...event.dataTransfer.files]); });
			this.observer = new ResizeObserver(() => { if (this.fitted) this.fit(); this.host.renderManualBlurRegions(); }); this.observer.observe(this.viewport);
			this.beforeUnload = (event) => { if (this.dirty || this.saving) { event.preventDefault(); event.returnValue = ''; } };
			window.addEventListener('beforeunload', this.beforeUnload, { signal: this.events.signal });
			this.refresh();
		}
		async initialize() {
			const source = this.host.assetInfo?.editorSourceUrl;
			if (!source) throw new Error('Reopen the editor to load the protected image source.');
			const image = await loadImage(source);
			if (this.disposed) return;
			const width = image.naturalWidth, height = image.naturalHeight;
			if (width * height > 24000000 || Math.max(width, height) > 8192) throw new Error('The editor supports images up to 24 megapixels and 8192 pixels per edge.');
			this.images.set('original', image); this.doc = new window.ImageEnhancerDocument(width, height);
			this.canvas.width = width; this.canvas.height = height;
			this.blurCanvas = document.createElement('canvas');
			this.fit(); this.setState(this.host.actionState || 'idle'); this.refresh();
		}
		buildInspector() {
			this.inspector.append(el('h3', '', 'Transform & style'));
			this.emptyInspector = el('p', 'ie-help', 'Select a layer to adjust its position, size and appearance.'); this.inspector.append(this.emptyInspector);
			this.propertyGrid = el('div', 'ie-properties'); this.inspector.append(this.propertyGrid);
			const definitions = [ ['name', 'Name', 'text'], ['x', 'X', 'number'], ['y', 'Y', 'number'], ['width', 'Width', 'number'], ['height', 'Height', 'number'], ['rotation', 'Rotation °', 'number'], ['opacity', 'Opacity %', 'number'], ['color', 'Color', 'color'], ['text', 'Text', 'text'], ['fontSize', 'Font size', 'number'], ['strength', 'Blur strength', 'number'] ];
			for (const [key, label, type] of definitions) {
				const field = el('label', 'ie-property'); field.append(el('span', '', label));
				const input = el('input'); input.type = type; input.setAttribute('aria-label', label); if (type === 'text') input.maxLength = key === 'text' ? 500 : 80;
				if (type === 'number') { input.step = 1; input.min = ['x', 'y', 'rotation'].includes(key) ? -8192 : key === 'opacity' ? 0 : 1; input.max = key === 'opacity' ? 100 : key === 'strength' ? 64 : 8192; }
				input.addEventListener('input', () => this.updateProperty(key, input.value, false));
				input.addEventListener('change', () => this.updateProperty(key, input.value));
				input.addEventListener('blur', () => { if (this.doc) { this.doc.commit(); this.refresh(); } }); field.append(input); this.propertyGrid.append(field); this.controls[key] = input;
			}
		}
		tab(name) {
			this.layersPanel.hidden = name !== 'layers'; this.aiPanel.hidden = name !== 'ai';
			this.layersTab.classList.toggle('is-active', name === 'layers'); this.aiTab.classList.toggle('is-active', name === 'ai');
			this.layersTab.setAttribute('aria-pressed', String(name === 'layers')); this.aiTab.setAttribute('aria-pressed', String(name === 'ai'));
		}
		setState(state) {
			this.state = state;
			const legacy = state !== 'idle'; this.viewport.hidden = legacy; this.legacyStage.hidden = !legacy;
			if (legacy) this.tab('ai');
			this.host.keepButton.textContent = 'Use result in editor'; this.host.discardButton.textContent = 'Discard result';
			this.refresh();
		}
		setTool(tool) {
			if (!this.editable) return;
			this.tool = tool; this.tab('layers');
			if (tool === 'text') { this.addLayer('text', { width: this.doc.width * 0.5, height: this.doc.height * 0.15, x: this.doc.width * 0.25, y: this.doc.height * 0.425 }); this.tool = 'move'; }
			this.refresh();
		}
		addLayer(type, values, commit = true) {
			try { const layer = this.doc.add(type, values, commit); this.selectedId = layer.id; return layer; }
			catch (error) { this.host.showError(error); return null; }
		}
		updateProperty(key, value, commit = true) {
			const layer = this.selected; if (!layer || layer.locked || !this.editable) return;
			if (['x', 'y', 'width', 'height', 'rotation', 'opacity', 'fontSize', 'strength'].includes(key)) {
				if (value === '' || value === '-') return;
				value = Number(value); if (!Number.isFinite(value)) return;
				value = clamp(value, ['x', 'y', 'rotation'].includes(key) ? -8192 : key === 'opacity' ? 0 : 1, key === 'opacity' ? 100 : key === 'strength' ? 64 : 8192);
				if (key === 'opacity') value /= 100;
			}
			layer[key] = value;
			if (commit) { this.doc.commit(); this.refresh(); }
			else { this.scheduleRender(); this.renderSelection(); }
		}
		history(direction) { if (!this.editable) return; direction < 0 ? this.doc.undo() : this.doc.redo(); this.refresh(); }
		duplicate() { if (!this.editable || !this.selected || this.selected.locked) return; try { this.selectedId = this.doc.duplicate(this.selectedId).id; this.refresh(); } catch (error) { this.host.showError(error); } }
		reorder(direction) { if (!this.editable || !this.selected || this.selected.locked) return; this.doc.reorder(this.selectedId, direction); this.refresh(); }
		remove() { if (!this.editable || !this.selected || this.selected.locked) return; this.doc.remove(this.selectedId); this.selectedId = null; this.refresh(); }
		fit() {
			if (!this.doc || this.viewport.hidden) return;
			const rect = this.viewport.getBoundingClientRect(); if (!rect.width || !rect.height) return;
			this.zoom = Math.min((rect.width - 80) / this.doc.width, (rect.height - 80) / this.doc.height, 1); this.zoom = Math.max(0.01, this.zoom); this.layoutCanvas();
		}
		setZoom(zoom) {
			if (!this.doc) return;
			const old = this.zoom; const x = (this.viewport.scrollLeft + this.viewport.clientWidth / 2) / old; const y = (this.viewport.scrollTop + this.viewport.clientHeight / 2) / old;
			this.zoom = clamp(zoom, 0.02, 4); this.fitted = false; this.layoutCanvas();
			this.viewport.scrollLeft = x * this.zoom - this.viewport.clientWidth / 2; this.viewport.scrollTop = y * this.zoom - this.viewport.clientHeight / 2;
		}
		layoutCanvas() {
			this.artboard.style.width = this.doc.width * this.zoom + 'px'; this.artboard.style.height = this.doc.height * this.zoom + 'px';
			this.zoomLabel.textContent = Math.round(this.zoom * 100) + '%'; this.renderSelection();
		}
		point(event) { const rect = this.canvas.getBoundingClientRect(); return { x: (event.clientX - rect.left) / this.zoom, y: (event.clientY - rect.top) / this.zoom }; }
		pointerDown(event) {
			if (!this.editable || event.button !== 0) return; event.preventDefault(); this.artboard.setPointerCapture(event.pointerId);
			const point = this.point(event); this.gesture = { point, before: this.doc.snapshot(), clientX: event.clientX, clientY: event.clientY, left: this.viewport.scrollLeft, top: this.viewport.scrollTop };
			if (this.tool === 'hand' || event.altKey) { this.gesture.kind = 'pan'; return; }
			if (event.target === this.resizeHandle && this.selected && !this.selected.locked) { this.gesture.kind = 'resize'; this.gesture.layer = { ...this.selected }; return; }
			if (['rect', 'ellipse', 'blur'].includes(this.tool)) {
				const layer = this.addLayer(this.tool, { x: clamp(point.x, 0, this.doc.width), y: clamp(point.y, 0, this.doc.height), width: 1, height: 1 }, false);
				if (!layer) { this.gesture = null; return; }
				// Drawing is one undo step, not separate creation and sizing steps.
				this.gesture.kind = 'draw';
			} else {
				const hit = this.doc.hit(point); this.selectedId = hit?.id ?? null; this.gesture.kind = 'move'; this.gesture.layer = hit ? { ...hit } : null;
			}
			this.refresh();
		}
		pointerMove(event) {
			const gesture = this.gesture; if (!gesture) return;
			if (gesture.kind === 'pan') { this.viewport.scrollLeft = gesture.left - (event.clientX - gesture.clientX); this.viewport.scrollTop = gesture.top - (event.clientY - gesture.clientY); return; }
			const layer = this.selected; if (!layer) return; const point = this.point(event);
			if (gesture.kind === 'draw') {
				const x = clamp(point.x, 0, this.doc.width), y = clamp(point.y, 0, this.doc.height);
				layer.x = Math.min(gesture.point.x, x); layer.y = Math.min(gesture.point.y, y);
				layer.width = Math.max(1, Math.abs(x - gesture.point.x)); layer.height = Math.max(1, Math.abs(y - gesture.point.y));
			} else if (gesture.kind === 'resize') {
				const start = gesture.layer; const local = window.ImageEnhancerDocument.localPoint(start, point);
				layer.width = clamp(local.x, 1, 8192); layer.height = clamp(local.y, 1, 8192);
				if (event.shiftKey) layer.height = clamp(layer.width * start.height / start.width, 1, 8192);
				// Keep the rotated top-left corner anchored as the dimensions change.
				const a = start.rotation * Math.PI / 180, dx = (layer.width - start.width) / 2, dy = (layer.height - start.height) / 2;
				layer.x = start.x + dx * Math.cos(a) - dy * Math.sin(a) - dx; layer.y = start.y + dx * Math.sin(a) + dy * Math.cos(a) - dy;
			} else if (gesture.layer) {
				layer.x = clamp(gesture.layer.x + point.x - gesture.point.x, -layer.width + 1, this.doc.width - 1);
				layer.y = clamp(gesture.layer.y + point.y - gesture.point.y, -layer.height + 1, this.doc.height - 1);
			}
			this.scheduleRender(); this.renderSelection();
		}
		pointerEnd(cancel = false) {
			if (!this.gesture) return;
			if (cancel) Object.assign(this.doc, JSON.parse(this.gesture.before));
			else { if (this.gesture.kind === 'draw') { this.tool = 'move'; if (this.selected?.width < 3 || this.selected?.height < 3) Object.assign(this.doc, JSON.parse(this.gesture.before)); } this.doc.commit(); }
			this.gesture = null; this.refresh();
		}
		scheduleRender() { if (this.frame) return; this.frame = requestAnimationFrame(() => { this.frame = null; this.render(); }); }
		render() {
			if (!this.doc || this.disposed) return;
			const ctx = this.canvas.getContext('2d'); ctx.clearRect(0, 0, this.doc.width, this.doc.height);
			ctx.drawImage(this.images.get(this.doc.baseId), 0, 0, this.doc.width, this.doc.height);
			for (const layer of this.doc.layers) {
				if (!layer.visible) continue;
				if (layer.type === 'blur') {
					// Pixelation is deterministic across browsers and applies to every layer below it.
					const small = this.blurCanvas; const scale = Math.max(4, layer.strength);
					small.width = Math.max(1, Math.ceil(this.doc.width / scale)); small.height = Math.max(1, Math.ceil(this.doc.height / scale));
					const smallCtx = small.getContext('2d'); smallCtx.drawImage(this.canvas, 0, 0, small.width, small.height);
					ctx.save(); this.transform(ctx, layer); ctx.beginPath(); ctx.rect(-layer.width / 2, -layer.height / 2, layer.width, layer.height); ctx.clip();
					ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalAlpha = layer.opacity; ctx.imageSmoothingEnabled = false;
					ctx.drawImage(small, 0, 0, this.doc.width, this.doc.height); ctx.restore(); continue;
				}
				ctx.save(); this.transform(ctx, layer); ctx.globalAlpha = layer.opacity; ctx.fillStyle = layer.color;
				const x = -layer.width / 2, y = -layer.height / 2;
				if (layer.type === 'image') { const image = this.images.get(layer.imageId); if (image) ctx.drawImage(image, x, y, layer.width, layer.height); }
				if (layer.type === 'rect') ctx.fillRect(x, y, layer.width, layer.height);
				if (layer.type === 'ellipse') { ctx.beginPath(); ctx.ellipse(0, 0, layer.width / 2, layer.height / 2, 0, 0, Math.PI * 2); ctx.fill(); }
				if (layer.type === 'text') { ctx.beginPath(); ctx.rect(x, y, layer.width, layer.height); ctx.clip(); ctx.font = `600 ${layer.fontSize}px system-ui, sans-serif`; ctx.textBaseline = 'top'; ctx.fillText(layer.text, x, y, layer.width); }
				ctx.restore();
			}
		}
		transform(ctx, layer) { ctx.translate(layer.x + layer.width / 2, layer.y + layer.height / 2); ctx.rotate(layer.rotation * Math.PI / 180); }
		renderSelection() {
			const layer = this.selected; this.selection.hidden = !layer || !layer.visible || !this.editable;
			if (!layer) return; const style = this.selection.style;
			style.left = layer.x * this.zoom + 'px'; style.top = layer.y * this.zoom + 'px'; style.width = layer.width * this.zoom + 'px'; style.height = layer.height * this.zoom + 'px'; style.transform = `rotate(${layer.rotation}deg)`;
			this.resizeHandle.hidden = layer.locked;
		}
		refresh() {
			if (this.disposed) return;
			this.saveButton.disabled = !this.editable || !this.dirty || this.host.assetInfo?.canReplace === false;
			this.saveButton.textContent = this.saving ? 'Saving…' : 'Save image'; this.saveButton.title = this.host.assetInfo?.canReplace === false ? 'You do not have permission to replace this asset.' : 'Save the flattened image to this asset';
			this.downloadButton.disabled = !this.editable;
			this.undoButton.disabled = !this.editable || this.doc.cursor === 0; this.redoButton.disabled = !this.editable || this.doc.cursor === this.doc.history.length - 1;
			for (const [tool, control] of Object.entries(this.toolButtons)) { control.disabled = !this.editable; control.classList.toggle('is-active', tool === this.tool); control.setAttribute('aria-pressed', String(tool === this.tool)); }
			this.artboard.dataset.tool = this.tool;
			this.aiNote.textContent = this.pendingAi ? 'AI result applied. Save this image before starting another AI operation.' : 'AI works on the base image. Your layers stay editable above it.';
			if (this.state === 'idle') for (const control of [this.host.enhanceButton, this.host.customEditButton, this.host.blurFacesButton, this.host.customBlurButton, this.host.openVideoButton]) control.disabled = !this.doc || this.pendingAi || this.saving || this.importing;
			if (!this.doc) {
				this.propertyGrid.hidden = true;
				for (const control of this.layerButtons) control.disabled = true;
				return;
			}
			this.message.textContent = `${this.doc.width} × ${this.doc.height} px${this.dirty ? ' · Unsaved' : ''}`;
			this.renderLayers(); this.renderProperties(); this.scheduleRender(); this.renderSelection();
		}
		renderLayers() {
			this.layerList.replaceChildren();
			for (const layer of [...this.doc.layers].reverse()) {
				const row = el('div', 'ie-layer' + (this.selectedId === layer.id ? ' is-selected' : ''));
				const pick = button(layer.name, layer.type, () => { this.selectedId = layer.id; this.tool = 'move'; this.refresh(); }, 'ie-layer-pick'); pick.append(el('span', '', layer.name)); pick.disabled = !this.editable;
				const eye = button(layer.visible ? 'Hide ' + layer.name : 'Show ' + layer.name, 'eye', () => { layer.visible = !layer.visible; this.doc.commit(); this.refresh(); }, layer.visible ? '' : 'is-muted');
				const lock = button(layer.locked ? 'Unlock ' + layer.name : 'Lock ' + layer.name, 'lock', () => { layer.locked = !layer.locked; this.doc.commit(); this.refresh(); }, layer.locked ? 'is-locked' : 'is-muted');
				eye.disabled = lock.disabled = !this.editable; eye.setAttribute('aria-pressed', String(layer.visible)); lock.setAttribute('aria-pressed', String(layer.locked)); row.append(pick, eye, lock); this.layerList.append(row);
			}
			const base = el('div', 'ie-layer ie-base'); base.innerHTML = icon('image'); base.append(el('span', '', this.doc.baseId === 'original' ? 'Original image' : 'AI result'), el('span', 'ie-base-lock', 'Base')); this.layerList.append(base);
			for (const control of this.layerButtons) control.disabled = !this.editable || !this.selected || this.selected.locked;
		}
		renderProperties() {
			const layer = this.selected; this.emptyInspector.hidden = Boolean(layer); this.propertyGrid.hidden = !layer;
			if (!layer) return;
			for (const [key, input] of Object.entries(this.controls)) {
				input.value = key === 'opacity' ? Math.round(layer.opacity * 100) : typeof layer[key] === 'number' ? Math.round(layer[key]) : layer[key]; input.disabled = !this.editable || layer.locked;
				input.parentElement.hidden = (['text', 'fontSize'].includes(key) && layer.type !== 'text') || (key === 'strength' && layer.type !== 'blur') || (key === 'color' && !['rect', 'ellipse', 'text'].includes(layer.type));
			}
		}
		async importFiles(files) {
			if (!this.editable || !files.length) return;
			this.importing = true; this.refresh();
			try {
				for (const file of files) {
					if (this.disposed) break;
					if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) throw new Error('Use PNG, JPEG or WebP layers smaller than 10 MB.');
					if (this.images.size >= 50) throw new Error('Close and reopen the editor before importing more images.');
					const url = URL.createObjectURL(file); this.urls.add(url); const image = await loadImage(url);
					if (this.disposed) return;
					if (image.naturalWidth * image.naturalHeight > 24000000) throw new Error('Image layers must be at most 24 megapixels.');
					const imageId = 'image-' + crypto.randomUUID(); this.images.set(imageId, image);
					const scale = Math.min(this.doc.width * 0.6 / image.naturalWidth, this.doc.height * 0.6 / image.naturalHeight, 1);
					const width = image.naturalWidth * scale, height = image.naturalHeight * scale;
					this.addLayer('image', { imageId, name: file.name.slice(0, 80), width, height, x: (this.doc.width - width) / 2, y: (this.doc.height - height) / 2 });
				}
				this.tool = 'move'; this.tab('layers');
			} catch (error) { this.host.showError(error); }
			finally { this.importing = false; this.refresh(); }
		}
		async acceptPreview() {
			if (this.saving || !this.doc) return;
			this.saving = true; this.host.setPreviewProcessing(true, 'Loading result into editor…'); this.refresh();
			try {
				const image = await loadImage(this.host.enhancedUrl); if (this.disposed) return;
				this.images.set('ai-result', image); this.doc.baseId = 'ai-result'; this.doc.commit(); this.pendingAi = true;
				this.host.setPreviewProcessing(false); this.host.setPreviewMode(false); this.host.setStatus('AI result applied. Continue editing or save the image.', false); this.tab('layers');
			} catch (error) { this.host.setPreviewProcessing(false); this.host.showError(error); }
			finally { this.saving = false; this.host.closeButton.disabled = false; this.refresh(); }
		}
		async export(save) {
			if (!this.editable || (save && this.host.assetInfo?.canReplace === false)) return;
			this.saving = true; this.host.closeButton.disabled = true; this.refresh(); this.host.clearError();
			try {
				this.render();
				const mime = this.host.assetInfo?.mimeType === 'image/png' ? 'image/png' : 'image/jpeg';
				const blob = await new Promise((resolve, reject) => this.canvas.toBlob((value) => value ? resolve(value) : reject(new Error('Could not export the image.')), mime, 0.95));
				if (this.disposed) return;
				if (blob.size > 25 * 1024 * 1024) throw new Error('The edited image exceeds the 25 MB save limit.');
				if (save) {
					const form = new FormData(); form.append('assetId', this.host.assetId); form.append('version', this.host.assetInfo.editorVersion || ''); form.append('image', blob, mime === 'image/png' ? 'edited.png' : 'edited.jpg');
					if (this.pendingAi) { form.append('token', this.host.token); form.append('previewId', this.host.previewId); }
					const response = await this.host.request('saveEditor', form);
					this.doc.saved = this.doc.snapshot(); this.pendingAi = false; this.host.editorSaved(response);
				} else {
					if (this.exportLink) { URL.revokeObjectURL(this.exportLink.href); this.urls.delete(this.exportLink.href); this.exportLink.remove(); }
					const url = URL.createObjectURL(blob); this.urls.add(url);
					this.exportLink = el('a', 'ie-export-link', 'Download file'); this.exportLink.href = url;
					this.exportLink.download = 'edited-' + (this.host.assetInfo?.filename || (mime === 'image/png' ? 'image.png' : 'image.jpg'));
					this.root.querySelector('.image-enhancer-cp-feedback').append(this.exportLink);
					this.host.setStatus('Export ready.', false); this.exportLink.click();
				}
			} catch (error) { this.host.showError(error); }
			finally { this.saving = false; this.host.closeButton.disabled = false; this.refresh(); }
		}
		keydown(event) {
			if (event.key === 'Tab') {
				const controls = [...this.root.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href]')].filter((node) => node.getClientRects().length);
				const first = controls[0], last = controls.at(-1);
				if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
			}
			if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (!this.saving && !this.importing && this.state !== 'busy') this.host.requestClose(); return; }
			if (event.target.closest('input, textarea, select, [contenteditable="true"]') || !this.editable) return;
			const command = event.metaKey || event.ctrlKey; const key = event.key.toLowerCase();
			if (command && key === 'z') { event.preventDefault(); this.history(event.shiftKey ? 1 : -1); }
			else if (command && key === 'd') { event.preventDefault(); this.duplicate(); }
			else if (command && key === 's') { event.preventDefault(); this.export(true); }
			else if (['Delete', 'Backspace'].includes(event.key)) { event.preventDefault(); this.remove(); }
			else if (event.key.startsWith('Arrow') && this.selected && !this.selected.locked) { event.preventDefault(); const delta = event.shiftKey ? 10 : 1; this.selected.x += event.key === 'ArrowLeft' ? -delta : event.key === 'ArrowRight' ? delta : 0; this.selected.y += event.key === 'ArrowUp' ? -delta : event.key === 'ArrowDown' ? delta : 0; this.doc.commit(); this.refresh(); }
			else if (!command && { v: 'move', h: 'hand', r: 'rect', o: 'ellipse', t: 'text', b: 'blur' }[key]) this.setTool({ v: 'move', h: 'hand', r: 'rect', o: 'ellipse', t: 'text', b: 'blur' }[key]);
		}
		startModalResize(event, grip) {
			event.preventDefault(); this.root.classList.remove('ie-maximized'); grip.setPointerCapture(event.pointerId);
			const rect = this.root.getBoundingClientRect(), start = { x: event.clientX, y: event.clientY };
			const move = (next) => { this.root.style.width = clamp(rect.width + (next.clientX - start.x) * 2, Math.min(720, innerWidth - 24), innerWidth - 24) + 'px'; this.root.style.height = clamp(rect.height + (next.clientY - start.y) * 2, Math.min(480, innerHeight - 24), innerHeight - 24) + 'px'; };
			const end = () => { grip.removeEventListener('pointermove', move); grip.removeEventListener('pointerup', end); grip.removeEventListener('pointercancel', end); };
			grip.addEventListener('pointermove', move); grip.addEventListener('pointerup', end); grip.addEventListener('pointercancel', end);
		}
		canClose() {
			if (this.saving || this.importing) return false;
			if (!this.dirty) return true;
			if (!this.closePrompt) {
				this.closePrompt = el('div', 'ie-close-prompt'); this.closePrompt.setAttribute('role', 'alert');
				this.closePrompt.append(el('span', '', 'Discard your unsaved image edits?'));
				const keep = el('button', 'ie-save', 'Keep editing'); keep.type = 'button';
				keep.addEventListener('click', () => { this.closePrompt.hidden = true; this.host.closeButton.focus(); });
				const discard = el('button', 'ie-button ie-discard-edits', 'Discard changes'); discard.type = 'button';
				discard.addEventListener('click', () => { this.doc.saved = this.doc.snapshot(); this.host.close(); });
				this.closePrompt.append(keep, discard); this.root.querySelector('.image-enhancer-cp-shell').append(this.closePrompt);
			}
			this.closePrompt.hidden = false; this.closePrompt.querySelector('button').focus();
			return false;
		}
		destroy() {
			this.disposed = true; this.events.abort(); this.observer.disconnect(); cancelAnimationFrame(this.frame);
			for (const url of this.urls) URL.revokeObjectURL(url); this.images.clear();
			this.canvas.width = this.canvas.height = 1; if (this.blurCanvas) this.blurCanvas.width = this.blurCanvas.height = 1;
		}
	}
	window.ImageEnhancerEditor = ImageEditor;
})();
