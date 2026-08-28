const fs = require('fs');
const path = 'app/Http/Controllers/TimeCardController.php';
let text = fs.readFileSync(path, 'utf8');
const marker = 'private function resolveRosterAndShift(employee $employee, string $date): array';
const idx = text.indexOf(marker);
if (idx < 0) {
  console.log('not found');
  process.exit(1);
}
const endMarker = 'private function resolveInStatus';
const end = text.indexOf(endMarker, idx);
const fn = text.slice(idx, end);
if (fn.includes("orWhere('status', 'Active')")) {
  console.log('already patched');
  process.exit(0);
}
const updated = fn.replace(
  /->whereNull\('deleted_at'\)/g,
  "->whereNull('deleted_at')\n            ->where(function ($q) {\n                $q->whereNull('status')->orWhere('status', 'Active');\n            })"
);
text = text.slice(0, idx) + updated + text.slice(end);
fs.writeFileSync(path, text);
console.log('patched active resolveRosterAndShift');
