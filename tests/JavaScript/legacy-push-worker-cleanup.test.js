/**
 * Uji regresi cleanup service worker push lama (resources/js/legacy-push-worker-cleanup.ts).
 *
 * Source production (.ts) dieksekusi langsung oleh Node 22.18+/24 lewat type stripping,
 * sama seperti modul ESM biasa yang dibawa Vite. Lingkungan browser dipalsukan melalui
 * global navigator + serviceWorker tiruan yang merekam pemanggilan unregister();
 * tidak ada jaringan, storage, maupun DOM.
 *
 * Coverage claim yang valid: hanya registrasi yang salah satu worker-nya ber-pathname
 * persis /push-service-worker.js yang dicabut; worker lain tidak disentuh dan kegagalan
 * API tidak pernah merambat ke pemanggil (fail-safe).
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { retireLegacyPushWorker } from '../../resources/js/legacy-push-worker-cleanup.ts';

const ORIGINAL_NAVIGATOR_DESCRIPTOR = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
const LEGACY_SCRIPT_URL = 'https://prime.test/push-service-worker.js?v=20260607-2';

class FakeServiceWorker {
    constructor(scriptURL) {
        this.scriptURL = scriptURL;
    }
}

class FakeServiceWorkerRegistration {
    constructor({ installing = null, waiting = null, active = null, unregisterFailure = null } = {}) {
        this.installing = installing;
        this.waiting = waiting;
        this.active = active;
        this.unregisterFailure = unregisterFailure;
        this.unregisterCallCount = 0;
    }

    async unregister() {
        this.unregisterCallCount += 1;
        if (this.unregisterFailure) {
            throw this.unregisterFailure;
        }

        return true;
    }
}

/**
 * Bangun satu set registrasi representatif: worker lama PRIME (aktif/menunggu/terpasang),
 * worker aplikasi lain, path mirip yang bukan pathname persis, dan registrasi tanpa worker.
 */
function buildRegistrations() {
    return {
        legacyActive: new FakeServiceWorkerRegistration({ active: new FakeServiceWorker(LEGACY_SCRIPT_URL) }),
        legacyWaiting: new FakeServiceWorkerRegistration({ waiting: new FakeServiceWorker(LEGACY_SCRIPT_URL) }),
        legacyInstalling: new FakeServiceWorkerRegistration({ installing: new FakeServiceWorker(LEGACY_SCRIPT_URL) }),
        unrelated: new FakeServiceWorkerRegistration({
            active: new FakeServiceWorker('https://prime.test/assets/app-worker.js'),
        }),
        lookalikeSubpath: new FakeServiceWorkerRegistration({
            active: new FakeServiceWorker('https://prime.test/assets/push-service-worker.js'),
        }),
        lookalikeSuffix: new FakeServiceWorkerRegistration({
            active: new FakeServiceWorker('https://prime.test/push-service-worker.js.bak'),
        }),
        emptyRegistration: new FakeServiceWorkerRegistration(),
    };
}

// Pasang navigator tiruan selama satu test, lalu pulihkan descriptor asli Node.
async function withFakeNavigator(fakeNavigator, run) {
    Object.defineProperty(globalThis, 'navigator', {
        value: fakeNavigator,
        configurable: true,
        writable: true,
    });

    try {
        return await run();
    } finally {
        Object.defineProperty(globalThis, 'navigator', ORIGINAL_NAVIGATOR_DESCRIPTOR);
    }
}

// settle(): beri kesempatan rantai promise fire-and-forget di dalam helper selesai.
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

// Rekam unhandled rejection selama run agar klaim fail-safe bisa diuji eksplisit.
async function captureUnhandledRejections(run) {
    const rejections = [];
    const onUnhandledRejection = (reason) => rejections.push(reason);
    process.on('unhandledRejection', onUnhandledRejection);

    try {
        await run();
        await settle();

        return rejections;
    } finally {
        process.off('unhandledRejection', onUnhandledRejection);
    }
}

test('cleanup mencabut hanya registrasi dengan pathname persis /push-service-worker.js', async () => {
    const registrations = buildRegistrations();
    const otherWorkerRegistrations = [
        registrations.unrelated,
        registrations.lookalikeSubpath,
        registrations.lookalikeSuffix,
        registrations.emptyRegistration,
    ];

    await withFakeNavigator(
        { serviceWorker: { getRegistrations: async () => Object.values(registrations) } },
        async () => {
            const rejections = await captureUnhandledRejections(async () => {
                retireLegacyPushWorker();
            });

            assert.deepEqual(rejections, [], 'cleanup tidak boleh meninggalkan unhandled rejection');

            for (const state of ['legacyActive', 'legacyWaiting', 'legacyInstalling']) {
                assert.equal(
                    registrations[state].unregisterCallCount, 1,
                    `worker lama pada state ${state} harus dicabut tepat satu kali`,
                );
            }

            for (const registration of otherWorkerRegistrations) {
                assert.equal(
                    registration.unregisterCallCount, 0,
                    'registrasi worker selain /push-service-worker.js tidak boleh dicabut',
                );
            }
        },
    );
});

test('kegagalan unregister satu registrasi lama tidak menghentikan cleanup lain', async () => {
    const failingLegacy = new FakeServiceWorkerRegistration({
        active: new FakeServiceWorker(LEGACY_SCRIPT_URL),
        unregisterFailure: new Error('unregister ditolak browser'),
    });
    const healthyLegacy = new FakeServiceWorkerRegistration({ waiting: new FakeServiceWorker(LEGACY_SCRIPT_URL) });

    await withFakeNavigator(
        { serviceWorker: { getRegistrations: async () => [failingLegacy, healthyLegacy] } },
        async () => {
            const rejections = await captureUnhandledRejections(async () => {
                assert.doesNotThrow(() => retireLegacyPushWorker());
            });

            assert.equal(failingLegacy.unregisterCallCount, 1);
            assert.equal(healthyLegacy.unregisterCallCount, 1);
            assert.deepEqual(rejections, [], 'kegagalan unregister harus ditelan di dalam helper');
        },
    );
});

test('kegagalan getRegistrations tidak merambat ke pemanggil', async () => {
    let getRegistrationsCallCount = 0;

    await withFakeNavigator(
        {
            serviceWorker: {
                getRegistrations: async () => {
                    getRegistrationsCallCount += 1;
                    throw new Error('service worker API tidak tersedia');
                },
            },
        },
        async () => {
            const rejections = await captureUnhandledRejections(async () => {
                assert.doesNotThrow(() => retireLegacyPushWorker());
            });

            assert.equal(getRegistrationsCallCount, 1);
            assert.deepEqual(rejections, [], 'error getRegistrations harus ditelan di dalam helper');
        },
    );
});

test('environment tanpa Service Worker API tidak melakukan apa pun', async () => {
    await withFakeNavigator({ userAgent: 'node-test' }, async () => {
        const rejections = await captureUnhandledRejections(async () => {
            assert.doesNotThrow(() => retireLegacyPushWorker());
        });

        assert.deepEqual(rejections, []);
    });
});
