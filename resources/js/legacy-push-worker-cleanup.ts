/**
 * Shim kompatibilitas sementara, bukan fitur Web Push.
 * Tujuannya hanya mencabut service worker lama PRIME (/push-service-worker.js)
 * yang masih tersimpan di browser pengguna lama. Web Push sudah dihapus, jadi
 * helper ini tidak pernah menyentuh Notification maupun PushManager.
 */
const LEGACY_PUSH_WORKER_PATHNAME = '/push-service-worker.js';

function isLegacyPushWorkerRegistration(registration: ServiceWorkerRegistration): boolean {
    const workers = [registration.installing, registration.waiting, registration.active];

    return workers.some((worker) => {
        if (worker === null) {
            return false;
        }

        try {
            // Pathname membandingkan tanpa query string, sehingga versi worker lama (?v=...) tetap cocok.
            return new URL(worker.scriptURL).pathname === LEGACY_PUSH_WORKER_PATHNAME;
        } catch {
            return false;
        }
    });
}

async function unregisterLegacyPushWorkerRegistrations(): Promise<void> {
    try {
        // getRegistrations() dipakai agar worker milik scope/PRIME lain tidak ikut dicabut.
        const registrations = await navigator.serviceWorker.getRegistrations();

        await Promise.all(
            registrations
                .filter(isLegacyPushWorkerRegistration)
                .map((registration) => registration.unregister()),
        );
    } catch {
        // Cleanup bersifat best-effort: kegagalan apa pun tidak boleh mengganggu UI PRIME.
    }
}

export function retireLegacyPushWorker(): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    void unregisterLegacyPushWorkerRegistrations();
}
