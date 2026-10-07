const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { transformSync } = require('esbuild');
const source = fs.readFileSync(path.join(__dirname, '../../resources/ts/pages/dashboard/products/ns-print-label.vue'), 'utf8');
const script = source.match(/<script lang="ts">([\s\S]*?)<\/script>/)[1]
    .replace(/^import .*;$/mg, '')
    .replace('export default defineComponent(', 'result = defineComponent(');
const errors = [];
const sandbox = { defineComponent: x => x, __: x => x, nsCurrency: x => x, nsSnackBar: { error: x => errors.push(x) } };
vm.createContext(sandbox);
vm.runInContext(transformSync(script, { loader: 'ts' }).code, sandbox);
const component = sandbox.result;
function context(settings = {}, count = 6) {
    const ctx = { form: { paper: 'roll', label_width: 32, label_height: 25, columns: 3, gap_x: 2, gap_y: 3, page_margin: 5, barcode_height: 8, font_size: 6, paper_width: 210, paper_height: 297, ...settings }, itemsToPrint: Array.from({ length: count }, (_, id) => ({ id })) };
    for (const [key, fn] of Object.entries(component.computed)) {
        if (!['form', 'visibility'].includes(key)) Object.defineProperty(ctx, key, { get: () => fn.call(ctx) });
    }
    for (const [key, fn] of Object.entries(component.methods)) ctx[key] = fn.bind(ctx);
    return ctx;
}
test('three-across roll keeps all six labels in two row pages at 100 by 25 mm', () => {
    const ctx = context();
    assert.equal(ctx.paperWidth, 100);
    assert.equal(ctx.printPages.length, 2);
    assert.deepEqual(Array.from(ctx.printPages, row => Array.from(row, x => x.id)), [[0, 1, 2], [3, 4, 5]]);
    assert.match(ctx.pageRule(), /size: 100mm 25mm; margin: 0/);
    assert.equal(ctx.gridStyle.rowGap, '0mm');
    assert.equal(ctx.gridStyle.height, '25mm');
    assert.equal(ctx.paperStyle.padding, '0mm');
});
test('partial last row does not duplicate labels', () => {
    const ctx = context({}, 7);
    assert.deepEqual(Array.from(ctx.printPages, r => r.length), [3, 3, 1]);
});
test('single column and decimal dimensions remain supported', () => {
    const ctx = context({ columns: 1, label_width: 38.5, label_height: 30.5 }, 2);
    assert.equal(ctx.printPages.length, 2);
    assert.match(ctx.pageRule(), /38.5mm 30.5mm/);
});
test('numeric input strings do not concatenate dimensions', () => {
    const ctx = context({ columns: '3', label_width: '32', gap_x: '2' });
    assert.equal(ctx.paperWidth, 100);
    assert.equal(ctx.validateLayout(), true);
});
test('Letter and custom sheets keep a single grid with the correct page size', () => {
    const ctx = context({ paper: 'letter' });
    assert.equal(ctx.printPages.length, 1);
    assert.equal(ctx.paperWidth, 216);
    assert.match(ctx.pageRule(), /Letter; margin: 5mm/);
    assert.equal(ctx.gridStyle.rowGap, '3mm');
    const custom = context({ paper: 'custom', paper_width: 120, paper_height: 40 });
    assert.equal(custom.validateLayout(), true);
    assert.match(custom.pageRule(), /120mm 40mm/);
});
test('invalid columns, negative gaps, missing dimensions and overflowing sheets are refused', () => {
    for (const settings of [{ columns: 0 }, { columns: 2.5 }, { columns: 13 }, { gap_x: -1 }, { label_width: '' }, { label_height: 'bad' }, { paper: 'custom', paper_width: 50 }, { paper: 'custom', paper_height: 20 }]) {
        assert.equal(context(settings).validateLayout(), false, JSON.stringify(settings));
    }
});
test('print output breaks between rows only and removes duplicated sheet margins', () => {
    let output = '';
    sandbox.window = { open: () => ({ document: { writeln: text => { output = text; }, close() {} } }) };
    sandbox.document = { querySelectorAll: () => [], getElementById: () => ({ outerHTML: '<div id="label-printing-paper">rows</div>' }) };
    context().print();
    assert.match(output, /size: 100mm 25mm/);
    assert.match(output, /label-roll-page:not\(:last-child\)/);
    assert.doesNotMatch(output, /\.label-item \{ page-break-after/);
    assert.match(output, /padding: 0 !important; width: auto !important/);
    assert.equal((output.match(/<div id="label-printing-paper">/g) || []).length, 1);
});
test('empty selection and blocked popup produce an error instead of throwing', () => {
    errors.length = 0;
    context({}, 0).print();
    sandbox.window = { open: () => null };
    context().print();
    assert.equal(errors.length, 2);
});
test('content clipping is reported before opening the print window', () => {
    let opened = false;
    sandbox.window = { open: () => { opened = true; } };
    sandbox.document = { querySelectorAll: () => [{ scrollHeight: 117, clientHeight: 92, scrollWidth: 120, clientWidth: 120 }] };
    errors.length = 0;
    context().print();
    assert.equal(opened, false);
    assert.equal(errors.length, 1);
});
