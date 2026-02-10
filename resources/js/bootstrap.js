import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Don't set a static X-CSRF-TOKEN header from the meta tag.
// In an Inertia SPA, the initial Blade-rendered meta token won't refresh on client-side navigation,
// which can cause 419 "Page Expired" after logout/login (session token regeneration).
// Axios will automatically send the current token via the XSRF cookie (XSRF-TOKEN -> X-XSRF-TOKEN).
