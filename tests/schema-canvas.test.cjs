const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const html = fs.readFileSync(path.join(__dirname, '..', 'database-schema.html'), 'utf8');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
const dataScript = scripts.find(s => s.includes('const schema ='));
const schema = vm.runInNewContext(dataScript.slice(0, dataScript.indexOf('const escapeHtml')) + '; schema;');
const graphScript = scripts.find(s => s.includes('function createGraphSimulation'));
const createSimulation = vm.runInNewContext(graphScript.slice(0, graphScript.indexOf('(() => {')) + '; createGraphSimulation;');
const size = { a: { w: 284, h: 260 }, b: { w: 284, h: 260 } };
const linked = [{ child: 'a', parent: 'b' }];

function settle(simulation) {
  for (let i = 0; i < 750; i++) if (!simulation.step(null, .55)) return i;
  assert.fail('Graph never went to sleep');
}

test('All embedded scripts parse, and the schema uses matching MySQL foreign-key types', () => {
  scripts.forEach(script => new vm.Script(script));
  assert.equal(schema.length, 22);
  assert.doesNotMatch(html, /citext|timestamptz|jsonb|btree_gist|daterange|PostgreSQL|\buuid\b/i);
  let foreignKeys = 0;
  for (const [name, , , fields] of schema) {
    assert.ok(fields.some(f => f[2].includes('PK')), `${name} needs a primary key`);
    for (const [column, type, keys, , reference] of fields) {
      if (reference) {
        const [parent, parentColumn] = reference.split('.');
        const target = schema.find(t => t[0] === parent)?.[3].find(f => f[0] === parentColumn);
        assert.ok(target, `${name}.${column}: missing referenced field`);
        assert.equal(type, target[1], `${name}.${column}: mismatched MySQL type/signedness`);
        foreignKeys++;
      }
      assert.ok(!(keys.includes('UQ') && type === 'text'), 'Full TEXT cannot have this unique index');
    }
  }
  assert.equal(foreignKeys, 33);
  assert.match(html, /START TRANSACTION/);
  assert.match(html, /FOR UPDATE/);
});

test('Linked tables gently attract while a pinned table stays fixed', () => {
  const positions = { a: { x: 0, y: 0 }, b: { x: 1400, y: 0 } };
  const simulation = createSimulation(positions, linked, size, new Set(['a']));
  simulation.wake();
  for (let i = 0; i < 120; i++) simulation.step();
  assert.ok(positions.b.x < 1300, 'The connected table should move toward its parent');
  assert.deepEqual(positions.a, { x: 0, y: 0 });
});

test('The dragged table is held exactly; neighbours respond to its new position', () => {
  const positions = { a: { x: 0, y: 0 }, b: { x: 1400, y: 0 } };
  const simulation = createSimulation(positions, linked, size, new Set());
  simulation.wake();
  for (let i = 0; i < 80; i++) simulation.step('b');
  assert.deepEqual(positions.b, { x: 1400, y: 0 });
  assert.ok(positions.a.x > 0);
});

test('Coincident cards separate, remain finite, and stop without perpetual jitter', () => {
  const positions = { a: { x: 0, y: 0 }, b: { x: 0, y: 0 } };
  const simulation = createSimulation(positions, [], size, new Set());
  simulation.wake();
  settle(simulation);
  assert.ok(Math.abs(positions.a.x - positions.b.x) >= 284 || Math.abs(positions.a.y - positions.b.y) >= 260);
  const snapshot = JSON.stringify(positions);
  for (let i = 0; i < 100; i++) assert.equal(simulation.step(), false);
  assert.equal(JSON.stringify(positions), snapshot);
});

test('Pause immediately freezes motion, and unpin plus wake allows motion again', () => {
  const positions = { a: { x: 0, y: 0 }, b: { x: 1400, y: 0 } }, pinned = new Set(['b']);
  const simulation = createSimulation(positions, linked, size, pinned);
  simulation.wake();
  for (let i = 0; i < 20; i++) simulation.step();
  simulation.stop();
  const snapshot = JSON.stringify(positions);
  for (let i = 0; i < 20; i++) simulation.step();
  assert.equal(JSON.stringify(positions), snapshot);
  pinned.delete('b');
  simulation.wake();
  for (let i = 0; i < 20; i++) simulation.step();
  assert.ok(positions.b.x < 1400);
});

test('The full 22-table graph settles with no card overlap', () => {
  const names = schema.map(t => t[0]);
  const positions = Object.fromEntries(names.map((n, i) => [n, { x: (i % 5) * 390, y: Math.floor(i / 5) * 350 }]));
  const sizes = Object.fromEntries(schema.map(t => [t[0], { w: 284, h: 110 + Math.min(t[3].length, 7) * 24 }]));
  const links = schema.flatMap(t => t[3].filter(f => f[4]).map(f => ({ child: t[0], parent: f[4].split('.')[0] })));
  const simulation = createSimulation(positions, links, sizes, new Set());
  simulation.wake();
  settle(simulation);
  for (const p of Object.values(positions)) assert.ok(Number.isFinite(p.x) && Number.isFinite(p.y));
  for (let i = 0; i < names.length; i++) for (let j = i + 1; j < names.length; j++) {
    const a = names[i], b = names[j], p = positions[a], q = positions[b];
    const overlaps = p.x < q.x + sizes[b].w && p.x + sizes[a].w > q.x && p.y < q.y + sizes[b].h && p.y + sizes[a].h > q.y;
    assert.ok(!overlaps, `${a} and ${b} should not overlap after settling`);
  }
});
