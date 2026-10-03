// Storefront API load test (Phase 7.5) — k6 (https://k6.io).
//
// Three journeys, as real traffic reaches the API through the storefront server (ADR-0003):
//   browse  — page rendering (ISR misses): config, category listing, product detail. Signed with
//             the render header, so not rate-limited per server IP — like the real storefront.
//   search  — a shopper searching (typos included) and the search-as-you-type suggestions.
//   buy     — a shopper's cart: create, add items, read, shipping methods, checkout quote.
//             No orders are placed: placement is a write we do not want on a shared database.
// Shoppers are signed like the BFF does (X-Storefront-Signature), each virtual user with its
// own address from 198.18.0.0/15 (RFC 2544 benchmarking range), so per-IP limits apply per
// shopper, exactly as in production.
//
//   k6 run loadtest/storefront.js                                # smoke: 1 user per journey
//   k6 run -e PROFILE=load loadtest/storefront.js                # sustained, see PROFILES
//   k6 run -e BASE_URL=https://api.brand.example -e API_HOST= -e PROFILE=load loadtest/storefront.js
//
// Environment: BASE_URL (default http://127.0.0.1:8000), API_HOST (Host header for local
// `artisan serve`; empty when BASE_URL is the real API host), SERVICE_AUTH_SECRET (the API's
// secret; without it the run is ordinary anonymous traffic and will be rate-limited),
// PROFILE (smoke|load|stress), SCALE (multiplies the load profile's rates), JOURNEY (subset).

import http from 'k6/http';
import { check, fail, group } from 'k6';
import { hmac } from 'k6/crypto';
import { SharedArray } from 'k6/data';

const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '') + '/api/v1';
const HOST = __ENV.API_HOST === undefined ? 'api.charlie.localhost' : __ENV.API_HOST;
const SECRET = __ENV.SERVICE_AUTH_SECRET || '';
const PROFILE = __ENV.PROFILE || 'smoke';
const SCALE = Number(__ENV.SCALE || 1);

// Iterations per second per journey. browse:search:buy ≈ 7:2:1, a typical storefront mix.
const PROFILES = {
  smoke: null,
  load: { browse: 14, search: 4, buy: 2, duration: '5m' },
  stress: { browse: 42, search: 12, buy: 6, duration: '5m' },
};

function scenario(exec, rate, duration) {
  if (rate === null) {
    return { executor: 'per-vu-iterations', exec, vus: 1, iterations: 30, maxDuration: '5m' };
  }

  return {
    executor: 'ramping-arrival-rate',
    exec,
    startRate: 0,
    timeUnit: '1s',
    preAllocatedVUs: Math.ceil(rate * SCALE * 2),
    maxVUs: Math.ceil(rate * SCALE * 10),
    stages: [
      { target: Math.ceil(rate * SCALE), duration: '1m' },
      { target: Math.ceil(rate * SCALE), duration },
      { target: 0, duration: '30s' },
    ],
  };
}

const profile = PROFILES[PROFILE];
if (profile === undefined) {
  fail(`Unknown PROFILE ${PROFILE}`);
}

// JOURNEY=browse (or search, buy) runs one journey alone — e.g. to measure its service time.
const journeys = (__ENV.JOURNEY || 'browse,search,buy').split(',');

export const options = {
  scenarios: Object.fromEntries(journeys.map((j) => [j, scenario(j, profile && profile[j], profile && profile.duration)])),
  // Budgets (docs/performance/load-test.md). Measured server-side wall time as seen by k6.
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{kind:read}': ['p(95)<300'],
    'http_req_duration{kind:search}': ['p(95)<400'],
    'http_req_duration{kind:cart}': ['p(95)<600'],
    checks: ['rate>0.99'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  discardResponseBodies: false,
};

const words = new SharedArray('words', () => [
  'cushion', 'cushon', 'bedsheet', 'bedshet', 'velvet', 'velvt', 'linen', 'table runner', 'quilt',
  'embroidered', 'embroiderd', 'cotton throw', 'jacquard', 'curtain', 'napkin', 'block print',
  'silk pillowcase', 'towel', 'khaddar', 'tablecloth', 'muslin', 'chenille', 'duvet', 'placemat',
]);

const RENDER = SECRET === '' ? '' : hmac('sha256', SECRET, 'storefront-render:v1', 'hex');

function headers(kind, extra) {
  const h = Object.assign({ Accept: 'application/json' }, extra || {});
  if (HOST) {
    h.Host = HOST;
  }
  return { headers: h, tags: { kind } };
}

function renderHeaders(kind) {
  return headers(kind, RENDER === '' ? {} : { 'X-Storefront-Render': RENDER });
}

function shopperIp() {
  const n = __VU;
  return `198.${18 + ((n >> 16) & 1)}.${(n >> 8) & 255}.${n & 255}`;
}

function shopperHeaders(kind, extra) {
  if (SECRET === '') {
    return headers(kind, extra);
  }
  const ts = Math.floor(Date.now() / 1000);
  const ip = shopperIp();
  return headers(kind, Object.assign({
    'X-Storefront-Signature': `v1,${ts},${hmac('sha256', SECRET, `${ts}\n${ip}`, 'hex')}`,
    'X-Storefront-Client-Ip': ip,
  }, extra || {}));
}

const pick = (list) => list[Math.floor(Math.random() * list.length)];

// Product ids and category slugs, read once from the API itself.
export function setup() {
  const categories = [];
  const walk = (nodes) => nodes.forEach((n) => { categories.push(n.slug); walk(n.children || []); });
  const tree = http.get(`${BASE}/categories`, renderHeaders('setup'));
  if (tree.status !== 200) {
    fail(`GET /categories → ${tree.status}. Is the API running and API_HOST right?`);
  }
  walk(tree.json('data'));

  const products = [];
  let url = `${BASE}/products?per_page=100`;
  while (url && products.length < 1000) {
    const page = http.get(url, renderHeaders('setup'));
    page.json('data').forEach((p) => products.push({ id: p.id, slug: p.slug, inStock: p.in_stock }));
    const next = page.json('links.next');
    // Follow the cursor on our own base URL (the link carries the API's public host).
    url = next ? `${BASE}/products?${next.split('?')[1]}` : null;
  }
  if (products.length === 0) {
    fail('The brand has no products. Load a catalog first (loadtest/README.md).');
  }

  return { categories, products, inStock: products.filter((p) => p.inStock) };
}

export function browse(data) {
  group('browse', () => {
    check(http.get(`${BASE}/storefront/config`, renderHeaders('read')), { 'config 200': (r) => r.status === 200 });
    const listing = http.get(`${BASE}/products?per_page=24&filter[category]=${pick(data.categories)}`, renderHeaders('read'));
    check(listing, { 'listing 200': (r) => r.status === 200 });
    const product = pick(data.products);
    check(http.get(`${BASE}/products/by-slug/${product.slug}`, renderHeaders('read')), { 'product 200': (r) => r.status === 200 });
  });
}

export function search() {
  group('search', () => {
    const q = pick(words);
    check(http.get(`${BASE}/search/suggest?q=${encodeURIComponent(q.slice(0, 4))}`, shopperHeaders('search')), { 'suggest 200': (r) => r.status === 200 });
    const res = http.get(`${BASE}/search?q=${encodeURIComponent(q)}&per_page=24`, shopperHeaders('search'));
    check(res, { 'search 200': (r) => r.status === 200, 'search has hits': (r) => r.status === 200 && r.json('data').length > 0 });
  });
}

export function buy(data) {
  group('buy', () => {
    const created = http.post(`${BASE}/carts`, null, shopperHeaders('cart'));
    if (!check(created, { 'cart 201': (r) => r.status === 201 })) {
      return;
    }
    const cart = created.json('data.id');
    const token = { 'X-Cart-Token': created.json('data.token'), 'Content-Type': 'application/json' };

    const lines = 1 + Math.floor(Math.random() * 3);
    for (let i = 0; i < lines; i++) {
      const product = http.get(`${BASE}/products/${pick(data.inStock).id}`, renderHeaders('read'));
      const variant = (product.json('data.variants') || []).find((v) => v.in_stock);
      if (!variant) {
        continue;
      }
      check(http.post(`${BASE}/carts/${cart}/items`, JSON.stringify({ variant_id: variant.id, quantity: 1 }), shopperHeaders('cart', token)),
        { 'add item 200': (r) => r.status === 200 });
    }

    check(http.get(`${BASE}/carts/${cart}`, shopperHeaders('cart', token)), { 'cart read 200': (r) => r.status === 200 });
    check(http.get(`${BASE}/checkout/${cart}/shipping-methods?country_code=PK&province=Punjab&city=Lahore`, shopperHeaders('cart', token)),
      { 'shipping methods 200': (r) => r.status === 200 });
    const quote = http.post(`${BASE}/checkout/quote`, JSON.stringify({
      cart_id: cart,
      shipping_address: { first_name: 'Load', last_name: 'Test', phone: '+92 300 0000000', line1: 'House 1, Street 1', city: 'Lahore', province: 'Punjab', country_code: 'PK' },
      shipping_method: 'standard',
    }), shopperHeaders('cart', token));
    check(quote, { 'quote 200': (r) => r.status === 200 });
  });
}
