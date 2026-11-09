const fs = require('fs');
const cities = require('./package/index.js'); // GeoNames, population included
const topo = JSON.parse(fs.readFileSync('atlas/package/counties-10m.json'));
const { feature } = require('./package/node_modules/topojson-client');
const { geoCentroid } = require('./package/node_modules/d3-geo');

const norm = (s) => s.toLowerCase().replace(/\b(county|parish|borough|census area|city and borough|municipality|municipio|city)\b/g, '').replace(/[^a-z]/g, '');
const csv = fs.readFileSync('us_cities.csv', 'utf8').trim().split(/\r?\n/).slice(1).map((l) => l.match(/("([^"]*)"|[^,]*)(,|$)/g).map((x) => x.replace(/,$/, '').replace(/^"|"$/g, '')));

// States: FIPS from the atlas, code and name from the city list.
const stateNames = {};
for (const r of csv) stateNames[r[1]] = r[2];
const stateTopo = feature(topo, topo.objects.states).features;
const fipsByName = Object.fromEntries(stateTopo.map((f) => [f.properties.name, f.id]));
const states = Object.entries(stateNames).filter(([c]) => fipsByName[stateNames[c]]).map(([code, name]) => ({ code, name, fips: fipsByName[name] }));
const codeByFips = Object.fromEntries(states.map((s) => [s.fips, s.code]));

// Counties with centroids.
const counties = feature(topo, topo.objects.counties).features
  .map((f) => { const [lon, lat] = geoCentroid(f); return { fips: f.id, state: codeByFips[f.id.slice(0, 2)], name: f.properties.name, lat: +lat.toFixed(5), lon: +lon.toFixed(5) }; })
  .filter((c) => c.state);
const countyKey = Object.fromEntries(counties.map((c) => [c.state + '|' + norm(c.name), c.fips]));

// Population from GeoNames (US, populated places).
const pop = {};
for (const c of cities) {
  if (c.country !== 'US') continue;
  const k = c.adminCode + '|' + c.name.toLowerCase();
  pop[k] = Math.max(pop[k] || 0, c.population || 0);
}

const seen = new Set();
const out = [];
let matchedCounty = 0, withPop = 0;
for (const [, st, , name, county, lat, lon] of csv) {
  if (!codeByFips[fipsByName[stateNames[st]]]) continue;
  const key = st + '|' + name.toLowerCase();
  if (seen.has(key)) continue; seen.add(key);
  const fips = countyKey[st + '|' + norm(county)] || '';
  if (fips) matchedCounty++;
  const p = pop[key] || 0; if (p) withPop++;
  out.push([st, name, fips, (+lat).toFixed(5), (+lon).toFixed(5), p]);
}
const q = (s) => (/[",]/.test(String(s)) ? '"' + String(s).replace(/"/g, '""') + '"' : s);
fs.writeFileSync('states.csv', 'code,name,fips\n' + states.map((s) => [s.code, s.name, s.fips].map(q).join(',')).join('\n') + '\n');
fs.writeFileSync('counties.csv', 'fips,state,name,lat,lon\n' + counties.map((c) => [c.fips, c.state, c.name, c.lat, c.lon].map(q).join(',')).join('\n') + '\n');
fs.writeFileSync('cities.csv', 'state,name,county_fips,lat,lon,population\n' + out.map((r) => r.map(q).join(',')).join('\n') + '\n');
console.log({ states: states.length, counties: counties.length, cities: out.length, matchedCounty, withPop });
