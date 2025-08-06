/**
 * Base URL Configuration for DCC Railway System
 */
const BASE_URL = 'https://highball.eu/dcc';

/**
 * API helper function to construct full API URLs
 */
function apiUrl(endpoint) {
    // Remove leading slash if present
    endpoint = endpoint.replace(/^\/+/, '');
    return `${BASE_URL}/${endpoint}`;
}

/**
 * Fetch wrapper that automatically uses the base URL for API calls
 */
async function apiFetch(endpoint, options = {}) {
    const url = endpoint.startsWith('http') ? endpoint : apiUrl(endpoint);
    return fetch(url, options);
}
