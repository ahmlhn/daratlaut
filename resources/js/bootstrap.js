import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Don't set a static X-CSRF-TOKEN header from the meta tag.
// In an Inertia SPA, the initial Blade-rendered meta token won't refresh on client-side navigation,
// which can cause 419 "Page Expired" after logout/login (session token regeneration).
// Axios will automatically send the current token via the XSRF cookie (XSRF-TOKEN -> X-XSRF-TOKEN).

function isCsrfMismatch(error) {
    const status = Number(error?.response?.status || 0);
    if (status === 419) return true;

    const payloadMessage = String(
        error?.response?.data?.message
        || error?.response?.data?.error
        || error?.message
        || ''
    ).toLowerCase();

    return payloadMessage.includes('csrf token mismatch')
        || payloadMessage.includes('page expired');
}

async function refreshCsrfToken() {
    // Regenerates CSRF token + refreshes XSRF-TOKEN cookie for stale tabs.
    await window.axios.get('/csrf-token', {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
}

window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const original = error?.config || {};

        if (!isCsrfMismatch(error)) {
            return Promise.reject(error);
        }

        if (original.__csrfRetried) {
            return Promise.reject(error);
        }

        original.__csrfRetried = true;

        try {
            await refreshCsrfToken();
            return window.axios(original);
        } catch (refreshErr) {
            return Promise.reject(error);
        }
    }
);
