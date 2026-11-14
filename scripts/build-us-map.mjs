// Builds public/maps/us-states.json: one SVG path per state (Albers USA, 975×610, Alaska and Hawaii inset), from us-atlas.
// Run after upgrading us-atlas: node scripts/build-us-map.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { geoPath } from 'd3-geo';
import { feature, mesh } from 'topojson-client';

const require = createRequire(import.meta.url);
const topo = JSON.parse(readFileSync(require.resolve('us-atlas/states-albers-10m.json'), 'utf8'));
const path = geoPath(); // the file is already projected
const round = (d) => d.replace(/(\d+)\.\d+/g, '$1'); // whole pixels are plenty at this size
const states = feature(topo, topo.objects.states).features.map((f) => ({ id: f.id, name: f.properties.name, d: round(path(f)) }));
const borders = round(path(mesh(topo, topo.objects.states, (a, b) => a !== b)));
writeFileSync('public/maps/us-states.json', JSON.stringify({ width: 975, height: 610, states, borders }));
console.log(states.length, 'states');
