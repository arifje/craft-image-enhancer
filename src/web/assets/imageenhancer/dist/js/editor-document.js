(function (root) {
	'use strict';
	const clone = (value) => JSON.parse(JSON.stringify(value));
	class EditorDocument {
		constructor(width, height) {
			this.width = width;
			this.height = height;
			this.layers = [];
			this.baseId = 'original';
			this.sequence = 0;
			this.history = [this.snapshot()];
			this.cursor = 0;
			this.saved = this.history[0];
		}
		snapshot() { return JSON.stringify({ layers: this.layers, baseId: this.baseId }); }
		get dirty() { return this.snapshot() !== this.saved; }
		commit() {
			const state = this.snapshot();
			if (state === this.history[this.cursor]) return;
			this.history = this.history.slice(0, this.cursor + 1);
			this.history.push(state);
			if (this.history.length > 60) this.history.shift();
			this.cursor = this.history.length - 1;
		}
		restore(index) {
			if (index < 0 || index >= this.history.length) return;
			this.cursor = index;
			Object.assign(this, JSON.parse(this.history[index]));
		}
		undo() { this.restore(this.cursor - 1); }
		redo() { this.restore(this.cursor + 1); }
		add(type, values = {}, commit = true) {
			if (this.layers.length >= 40) throw new Error('A document can contain up to 40 layers.');
			const size = Math.max(12, Math.round(Math.min(this.width, this.height) / 3));
			const layer = {
				id: ++this.sequence, type, name: { rect: 'Rectangle', ellipse: 'Ellipse', text: 'Text', image: 'Image', blur: 'Blur' }[type],
				x: (this.width - size) / 2, y: (this.height - size) / 2, width: size, height: size,
				rotation: 0, opacity: 1, visible: true, locked: false, color: '#f4a340',
				text: 'Your text', fontSize: Math.max(16, Math.round(size / 4)), strength: 16,
				...values,
			};
			this.layers.push(layer);
			if (commit) this.commit();
			return layer;
		}
		duplicate(id) {
			const source = this.layers.find((layer) => layer.id === id);
			if (!source) return null;
			const values = clone(source);
			delete values.id;
			return this.add(source.type, { ...values, name: source.name + ' copy', x: source.x + 20, y: source.y + 20 });
		}
		remove(id) { this.layers = this.layers.filter((layer) => layer.id !== id); this.commit(); }
		reorder(id, direction) {
			const index = this.layers.findIndex((layer) => layer.id === id);
			const next = index + direction;
			if (index < 0 || next < 0 || next >= this.layers.length) return;
			[this.layers[index], this.layers[next]] = [this.layers[next], this.layers[index]];
			this.commit();
		}
		static localPoint(layer, point) {
			const angle = -layer.rotation * Math.PI / 180;
			const x = point.x - layer.x - layer.width / 2;
			const y = point.y - layer.y - layer.height / 2;
			return { x: x * Math.cos(angle) - y * Math.sin(angle) + layer.width / 2, y: x * Math.sin(angle) + y * Math.cos(angle) + layer.height / 2 };
		}
		hit(point) {
			return [...this.layers].reverse().find((layer) => {
				if (!layer.visible || layer.locked) return false;
				const p = EditorDocument.localPoint(layer, point);
				return p.x >= 0 && p.y >= 0 && p.x <= layer.width && p.y <= layer.height;
			});
		}
	}
	if (typeof module !== 'undefined' && module.exports) module.exports = EditorDocument;
	else root.ImageEnhancerDocument = EditorDocument;
})(typeof window !== 'undefined' ? window : globalThis);
