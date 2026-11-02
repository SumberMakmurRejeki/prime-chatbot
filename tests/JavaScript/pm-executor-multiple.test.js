/**
 * Uji interaksi DOM mode Action "pilih banyak" untuk resources/views/pages/pm/executor.blade.php.
 *
 * FIDELITAS HARNESS — source-text approximation (pola yang sama dengan checksheet-wizard-xss.test.js):
 *
 *   1. Blok <script> inline dibaca apa adanya dari Blade production (satu-satunya blok script
 *      di file tersebut), lalu token Blade yang tidak bisa dievaluasi Node dinetralkan:
 *      - baris utuh "@if ($isInProgress)" dan "@endif" dihapus (guard media saja);
 *      - '{{ csrf_token() }}' → 'test-csrf-token';
 *      - @json(route(...)) media → URL dummy. Logika media sendiri tidak berjalan pada
 *        fixture minimal karena elemen pemicunya tidak ada.
 *   2. Logika pilihan Action, marker clear, dan handler-nya TIDAK diubah sama sekali.
 *   3. Fixture DOM meniru kontrak render Slice C: checkbox asli execution_action[id][]
 *      sebagai satu-satunya sumber keadaan terpilih, marker hidden execution_clear[id]
 *      selalu dimulai disabled, dan tombol Kosongkan Pilihan.
 *
 * Coverage valid: siklus intent SET → KEEP → CLEAR → SET (marker aktif hanya lewat
 * interaksi eksplisit, tidak pernah SET + CLEAR bersamaan) pada elemen form asli jsdom.
 * Coverage tidak valid: Tailwind CSS, submit HTTP, dan jalur media upload.
 */

import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';

const bladeSource = readFileSync(
    new URL('../../resources/views/pages/pm/executor.blade.php', import.meta.url),
    'utf8',
);

const executorScript = bladeSource
    .match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/^@if \(\$isInProgress\)\s*$/m, '')
    .replace(/^@endif\s*$/m, '')
    .replace(/'\{\{ csrf_token\(\) \}\}'/g, "'test-csrf-token'")
    .replace(/@json\(route\('pm-executor\.media\.upload', \$machine\)\)/g, "'/test-upload-url'")
    .replace(/@json\(route\('pm-executor\.media\.destroy', \[\$machine, 'MEDIA_ID'\]\)\)/g, "'/test-destroy-url/MEDIA_ID'");

assert.ok(executorScript.includes('[data-action-clear-button]'), 'script executor harus memuat handler marker clear');
assert.ok(! executorScript.includes('@json'), 'token Blade @json harus sudah dinetralkan');

const ITEM_ID = '501';

const buildDom = () => {
    const dom = new JSDOM(`<!DOCTYPE html>
        <html>
        <body>
            <form id="pm-executor-form">
                <section data-pm-executor>
                    <div data-action-group="${ITEM_ID}" data-action-mode="multiple">
                        <label><input type="checkbox" name="execution_action[${ITEM_ID}][]" value="OK" data-action-choice="${ITEM_ID}" class="peer sr-only" checked><span>OK</span></label>
                        <label><input type="checkbox" name="execution_action[${ITEM_ID}][]" value="TAMBAH OLI" data-action-choice="${ITEM_ID}" class="peer sr-only"><span>TAMBAH OLI</span></label>
                        <label><input type="checkbox" name="execution_action[${ITEM_ID}][]" value="LUBRIKASI" data-action-choice="${ITEM_ID}" class="peer sr-only" checked><span>LUBRIKASI</span></label>
                    </div>
                    <input type="hidden" name="execution_clear[${ITEM_ID}]" value="1" data-action-clear="${ITEM_ID}" disabled>
                    <button type="button" data-action-clear-button="${ITEM_ID}">Kosongkan Pilihan</button>
                </section>
            </form>
        </body>
        </html>`);

    return dom;
};

const choices = (dom) => Array.from(dom.window.document.querySelectorAll(`input[data-action-choice="${ITEM_ID}"]`));

const choiceByValue = (dom, value) => {
    const found = choices(dom).find((choice) => choice.value === value);
    assert.ok(found, `pilihan ${value} harus ada pada fixture`);
    return found;
};

const clearMarker = (dom) => dom.window.document.querySelector(`input[data-action-clear="${ITEM_ID}"]`);

const submittedIntent = (dom) => {
    const formData = new dom.window.FormData(dom.window.document.getElementById('pm-executor-form'));

    return {
        actions: formData.getAll(`execution_action[${ITEM_ID}][]`),
        clear: formData.get(`execution_clear[${ITEM_ID}]`),
    };
};

test('pilih banyak: siklus SET → KEEP → CLEAR → SET pada kontrol form asli', () => {
    const dom = buildDom();

    vm.runInContext(executorScript, vm.createContext(dom.window));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

    // Muat ulang dengan hasil tersimpan OK + LUBRIKASI: marker tetap nonaktif (KEEP), tidak ada CLEAR otomatis.
    assert.equal(clearMarker(dom).disabled, true, 'marker clear harus nonaktif saat muat');
    assert.deepEqual(submittedIntent(dom), { actions: ['OK', 'LUBRIKASI'], clear: null });

    // Melepas salah satu pilihan belum menjadi CLEAR (masih ada sisa pilihan aktif).
    const lubrikasi = choiceByValue(dom, 'LUBRIKASI');
    lubrikasi.checked = false;
    lubrikasi.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    assert.equal(clearMarker(dom).disabled, true, 'sisa pilihan aktif menjaga marker nonaktif');
    assert.deepEqual(submittedIntent(dom), { actions: ['OK'], clear: null });

    // Melepas pilihan terakhir = CLEAR eksplisit.
    const ok = choiceByValue(dom, 'OK');
    ok.checked = false;
    ok.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    assert.equal(clearMarker(dom).disabled, false, 'melepas pilihan terakhir mengaktifkan marker');
    assert.deepEqual(submittedIntent(dom), { actions: [], clear: '1' });

    // Melepas pilihan terakhir menghasilkan CLEAR: tidak ada checkbox yang tersisa tercentang dan marker tetap aktif (bukan KEEP).
    assert.equal(choices(dom).some((choice) => choice.checked), false);

    // Reseleksi membatalkan intent CLEAR → SET saja.
    const tambahOli = choiceByValue(dom, 'TAMBAH OLI');
    tambahOli.checked = true;
    tambahOli.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    assert.equal(clearMarker(dom).disabled, true, 'reseleksi mematikan kembali marker');
    assert.deepEqual(submittedIntent(dom), { actions: ['TAMBAH OLI'], clear: null });
});

test('pilih banyak: tombol Kosongkan Pilihan mengosongkan semua dan mengaktifkan marker', () => {
    const dom = buildDom();

    vm.runInContext(executorScript, vm.createContext(dom.window));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

    dom.window.document.querySelector(`[data-action-clear-button="${ITEM_ID}"]`).click();

    assert.equal(choices(dom).some((choice) => choice.checked), false, 'semua pilihan harus kosong');
    assert.equal(clearMarker(dom).disabled, false, 'tombol clear mengaktifkan marker');
    assert.deepEqual(submittedIntent(dom), { actions: [], clear: '1' });

    // Pilih lagi setelah tombol clear: kembali ke intent SET tanpa marker.
    const ok = choiceByValue(dom, 'OK');
    ok.checked = true;
    ok.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    assert.deepEqual(submittedIntent(dom), { actions: ['OK'], clear: null });
});

test('pilih banyak: item segar tanpa hasil tidak mengirim CLEAR', () => {
    const dom = buildDom();

    choices(dom).forEach((choice) => {
        choice.checked = false;
        choice.removeAttribute('checked');
    });

    vm.runInContext(executorScript, vm.createContext(dom.window));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

    // Tidak ada interaksi: marker harus tetap disabled sehingga intent tetap KEEP.
    assert.equal(clearMarker(dom).disabled, true);
    assert.deepEqual(submittedIntent(dom), { actions: [], clear: null });
});
