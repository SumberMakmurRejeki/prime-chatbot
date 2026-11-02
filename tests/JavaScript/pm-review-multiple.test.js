import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';

const bladeSource = readFileSync(
    new URL('../../resources/views/pages/pm/review/edit.blade.php', import.meta.url),
    'utf8',
);
const reviewScript = bladeSource.match(/<script>([\s\S]*?)<\/script>/)[1];

assert.ok(reviewScript.includes('[data-review-action-group]'));

const buildDom = () => {
    const dom = new JSDOM(`<!DOCTYPE html><form id="review-form">
        <div data-review-action-group="501">
            <input type="checkbox" name="items[501][action_value][]" value="OK" data-review-action-choice="501" checked>
            <input type="checkbox" name="items[501][action_value][]" value="LUBRIKASI" data-review-action-choice="501">
            <input type="hidden" name="execution_clear[501]" value="1" data-review-action-clear="501" disabled>
            <button type="button" data-review-action-clear-button="501">Kosongkan</button>
        </div>
        <div data-review-action-group="503">
            <input type="checkbox" name="items[503][action_value][]" value="OK" data-review-action-choice="503">
            <input type="hidden" name="execution_clear[503]" value="1" data-review-action-clear="503" disabled>
            <button type="button" data-review-action-clear-button="503">Kosongkan</button>
        </div>
        <div data-review-action-group="502">
            <input type="checkbox" name="items[502][action_value][]" value="OK" data-review-action-choice="502" checked>
            <input type="hidden" name="execution_clear[502]" value="1" data-review-action-clear="502" disabled>
            <button type="button" data-review-action-clear-button="502">Kosongkan</button>
        </div>
    </form>`, { runScripts: 'outside-only' });

    vm.runInContext(reviewScript, vm.createContext(dom.window));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

    return dom;
};

const intent = (dom, itemId) => {
    const formData = new dom.window.FormData(dom.window.document.getElementById('review-form'));

    return {
        actions: formData.getAll(`items[${itemId}][action_value][]`),
        clear: formData.get(`execution_clear[${itemId}]`),
    };
};

test('Review multiple: deselecting the last choice sends CLEAR; selecting again sends SET only', () => {
    const dom = buildDom();
    const firstChoice = dom.window.document.querySelector('[data-review-action-choice="501"]');

    assert.deepEqual(intent(dom, '501'), { actions: ['OK'], clear: null });
    firstChoice.checked = false;
    firstChoice.dispatchEvent(new dom.window.Event('change'));
    assert.deepEqual(intent(dom, '501'), { actions: [], clear: '1' });

    firstChoice.checked = true;
    firstChoice.dispatchEvent(new dom.window.Event('change'));
    assert.deepEqual(intent(dom, '501'), { actions: ['OK'], clear: null });
});

test('Review multiple: explicit clear is item-scoped and fresh empty item does not send CLEAR', () => {
    const dom = buildDom();
    dom.window.document.querySelector('[data-review-action-clear-button="501"]').click();

    assert.deepEqual(intent(dom, '501'), { actions: [], clear: '1' });
    assert.deepEqual(intent(dom, '502'), { actions: ['OK'], clear: null });
    assert.deepEqual(intent(dom, '503'), { actions: [], clear: null });
});
