import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

const asset = process.env.ERD_ASSET || fileURLToPath(new URL('../public/js/cp-erd.js', import.meta.url));
const source = await readFile(asset, 'utf8');
const column = (name, pk = false, fk = false) => ({name, type: 'uuid', pk, fk, nullable: false});
const graph = {
  tables: [
    {name: 'courses', pk: ['id'], rows_estimate: 2, columns: [column('id', true)]},
    {name: 'lessons', pk: ['id'], rows_estimate: 5, columns: [column('id', true), column('course_id', false, true)]},
    {name: 'unrelated', pk: ['id'], rows_estimate: 0, columns: [column('id', true)]},
  ],
  edges: [{from_table: 'lessons', from_column: 'course_id', to_table: 'courses', to_column: 'id', constraint: 'lessons_course_id_fkey', on_update: 'NO ACTION', on_delete: 'CASCADE'}],
  enums: [], timings: {metadata_ms: 1},
};

class Element {
  dataset = {}; style = {}; attributes = {}; children = []; listeners = {}; innerHTML = ''; textContent = '';
  constructor() { this.buttons = {}; }
  appendChild(node) { this.children.push(node); return node; }
  setAttribute(key, value) { this.attributes[key] = String(value); }
  getAttribute(key) { return this.attributes[key] ?? null; }
  addEventListener(name, handler) { (this.listeners[name] ??= []).push(handler); }
  removeEventListener() {}
  getBoundingClientRect() { return {left: 0, top: 0, width: 1200, height: 700}; }
  querySelector(selector) {
    const match = selector.match(/^\[data-act="([^"]+)"\]$/);
    if (!match || !this.innerHTML.includes(`data-act="${match[1]}"`)) return null;
    return this.buttons[match[1]] ??= new Element();
  }
  contains() { return true; }
  fire(name, options = {}) {
    for (const listener of [...(this.listeners[name] ?? [])]) listener({target: this, stopPropagation() {}, ...options});
  }
}

async function mount() {
  const ids = ['schema', 'search', 'relfilter', 'meta', 'banner', 'canvas', 'svg', 'viewport', 'tip', 'detail', 'empty', 'fit', 'reset', 'auto', 'zoomin', 'zoomout', 'svg-btn', 'png-btn', 'isolated', 'related', 'collapse', 'clear-focus'];
  const nodes = Object.fromEntries(ids.map(id => ['#cp-erd-' + id, new Element()]));
  const root = new Element();
  root.dataset.config = JSON.stringify({api: '/graph', schema: 'public', schemaUrl: '/schema/__T__', recordsUrl: '/records/__T__',
    i18n: {help: 'اختَر جدولاً', columns: 'أعمدة (:count)', relationship: 'العلاقة', focus: 'تركيز: :name'}});
  root.querySelector = selector => nodes[selector] ?? null;
  const context = vm.createContext({
    document: {readyState: 'complete', getElementById: id => id === 'cp-erd' ? root : null,
      querySelector: () => null, createElement: () => new Element(), createElementNS: () => new Element(), addEventListener() {}},
    window: {location: {href: ''}}, performance: {now: () => 1}, console,
    setTimeout: () => 1, clearTimeout() {},
    fetch: async url => ({ok: true, json: async () => url.endsWith('/schemas') ? {schemas: ['public']} : {graph, layout: null}}),
  });
  vm.runInContext(source, context, {filename: asset});
  await new Promise(resolve => setImmediate(resolve));
  const node = id => nodes['#cp-erd-' + id];
  const table = name => node('viewport').children.at(-1).children.find(item => item.dataset.table === name);
  return {node, table};
}

test('loads a nonempty graph and localized help without an error overlay', async () => {
  const {node} = await mount();
  assert.equal(node('empty').style.display, 'none', node('empty').textContent);
  assert.match(node('detail').innerHTML, /اختَر جدولاً/);
  assert.match(node('meta').textContent, /3 tables · 1 FKs/);
});

test('table selection renders columns, foreign keys and localized headings', async () => {
  const {node, table} = await mount();
  table('lessons').fire('click');
  assert.match(node('detail').innerHTML, /أعمدة \(2\)/);
  assert.match(node('detail').innerHTML, /course_id/);
  assert.match(node('detail').innerHTML, /lessons.course_id/);
});

test('relationship selection renders localized constraint details', async () => {
  const {node} = await mount();
  node('viewport').children[0].children[0].fire('click');
  assert.match(node('detail').innerHTML, /العلاقة/);
  assert.match(node('detail').innerHTML, /lessons_course_id_fkey/);
});

test('focused graph and clearing focus keep rendering usable', async () => {
  const {node, table} = await mount();
  table('lessons').fire('click');
  node('detail').querySelector('[data-act="focus"]').fire('click');
  assert.equal(node('viewport').children.at(-1).children.length, 2);
  node('canvas').fire('click');
  assert.match(node('detail').innerHTML, /تركيز: lessons/);
  node('clear-focus').fire('click');
  assert.equal(node('viewport').children.at(-1).children.length, 3);
  assert.match(node('detail').innerHTML, /اختَر جدولاً/);
});
