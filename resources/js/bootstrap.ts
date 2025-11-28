import { router } from '@inertiajs/react';
import axios from 'axios';
import { getCsrfToken } from '@/lib/csrf';

// Set up CSRF token for requests
const initialToken = getCsrfToken();

if (initialToken) {
    // Set CSRF token for axios requests
    axios.defaults.headers.common['X-CSRF-TOKEN'] = initialToken;
    
    // Set CSRF token for fetch requests
    const originalFetch = window.fetch;
    window.fetch = function(input, init = {}) {
        const headers = new Headers(init.headers);
        const currentToken = getCsrfToken();
        
        if (!headers.has('X-CSRF-TOKEN') && currentToken) {
            headers.set('X-CSRF-TOKEN', currentToken);
        }
        
        return originalFetch(input, {
            ...init,
            headers,
            credentials: 'same-origin'
        });
    };
}

// Configure Inertia to include CSRF token in requests
router.on('before', (event) => {
    const currentToken = getCsrfToken();
    if (currentToken) {
        // Always set the token for all requests
        if (!event.detail.visit.headers) {
            event.detail.visit.headers = {};
        }
        event.detail.visit.headers['X-CSRF-TOKEN'] = currentToken;
        // Also set for form data if it's a POST/PUT/PATCH/DELETE request
        const method = event.detail.visit.method?.toLowerCase();
        if (method && ['post', 'put', 'patch', 'delete'].includes(method)) {
            if (!event.detail.visit.data) {
                event.detail.visit.data = {};
            }
            if (typeof event.detail.visit.data === 'object') {
                event.detail.visit.data._token = currentToken;
            }
        }
    }
});

// Handle CSRF token errors
router.on('error', (event) => {
    const response = event.detail.response;
    if (response && response.status === 419) {
        // CSRF token mismatch - show user-friendly message
        console.warn('CSRF token mismatch detected.');
        
        // Show a user-friendly alert instead of just reloading
        if (confirm('Your session has expired. Click OK to refresh the page and try again.')) {
            window.location.reload();
        }
    }
});

// Update axios token after page changes
router.on('success', () => {
    const newToken = getCsrfToken();
    if (newToken) {
        axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
    }
});