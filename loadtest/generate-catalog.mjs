#!/usr/bin/env node
// Deterministic catalog for load tests, in the product CSV format of `imports:csv`
// (Modules\Imports\Support\CsvImport). Same seed, same catalog — results stay comparable.
//
//   node loadtest/generate-catalog.mjs --products 5000 --categories home,bedding --out catalog.csv
//   php artisan imports:csv <tenant id> catalog.csv

import { writeFileSync } from 'node:fs';
import { parseArgs } from 'node:util';

const { values: args } = parseArgs({
  options: {
    products: { type: 'string', default: '5000' },
    categories: { type: 'string', default: '' },
    seed: { type: 'string', default: '7' },
    prefix: { type: 'string', default: 'LT' },
    out: { type: 'string', default: 'loadtest-catalog.csv' },
  },
});

const count = Number.parseInt(args.products, 10);
const categories = args.categories.split(',').map((s) => s.trim()).filter(Boolean);

// mulberry32: small, fast, seeded.
let state = Number.parseInt(args.seed, 10) >>> 0;
const random = () => {
  state = (state + 0x6d2b79f5) >>> 0;
  let t = state;
  t = Math.imul(t ^ (t >>> 15), t | 1);
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
};
const pick = (list) => list[Math.floor(random() * list.length)];

const materials = ['Cotton', 'Linen', 'Velvet', 'Jacquard', 'Silk', 'Khaddar', 'Lawn', 'Chenille', 'Satin', 'Muslin', 'Wool', 'Bamboo'];
const styles = ['Embroidered', 'Printed', 'Quilted', 'Block-print', 'Tasselled', 'Woven', 'Pleated', 'Ruffled', 'Plain', 'Textured'];
const colours = ['Ivory', 'Sage', 'Rust', 'Teal', 'Mustard', 'Blush', 'Charcoal', 'Indigo', 'Olive', 'Sand', 'Maroon', 'Pearl'];
const things = ['Cushion Cover', 'Bedsheet', 'Duvet Cover', 'Table Runner', 'Throw', 'Curtain', 'Placemat Set', 'Napkin Set', 'Pillowcase', 'Quilt', 'Tablecloth', 'Bath Towel'];
const sizes = ['Single', 'Double', 'King', 'Super King'];
const words = ['soft', 'breathable', 'durable', 'handmade', 'premium', 'washable', 'fade-resistant', 'elegant', 'everyday', 'festive', 'classic', 'modern'];

const csv = (value) => (/[",\n]/.test(value) ? `"${value.replaceAll('"', '""')}"` : value);
const price = () => (Math.round((800 + random() * 14200) / 50) * 50).toFixed(2);

const rows = ['handle,title,description,category,status,option1_name,option1_value,sku,price,compare_at_price,weight_grams,stock'];

for (let i = 1; i <= count; i++) {
  const title = `${pick(styles)} ${pick(materials)} ${pick(things)}`;
  const handle = `${args.prefix.toLowerCase()}-${i}`;
  const description = Array.from({ length: 3 }, () => `A ${pick(words)}, ${pick(words)} ${title.toLowerCase()} in ${pick(colours).toLowerCase()}.`).join(' ');
  const category = categories.length > 0 ? pick(categories) : '';
  const variants = random() < 0.35 ? [''] : Array.from(new Set(Array.from({ length: 2 + Math.floor(random() * 3) }, () => pick(colours))));
  const base = price();
  const onSale = random() < 0.2;

  variants.forEach((colour, v) => {
    rows.push([
      handle,
      v === 0 ? csv(title) : '',
      v === 0 ? csv(description) : '',
      category,
      'active',
      colour === '' ? '' : 'Colour',
      colour,
      `${args.prefix}-${i}-${v}`,
      base,
      onSale ? (Number(base) * 1.25).toFixed(2) : '',
      String(200 + Math.floor(random() * 1800)),
      String(random() < 0.05 ? 0 : 5 + Math.floor(random() * 200)),
    ].join(','));
  });
}

writeFileSync(args.out, `${rows.join('\n')}\n`);
console.log(`${count} products, ${rows.length - 1} variants → ${args.out}`);
