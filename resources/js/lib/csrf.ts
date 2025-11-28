/**
 * Get the current CSRF token from the meta tag
 */
export function getCsrfToken(): string | null {
    const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
    return token ? token.content : null;
}

/**
 * Make a POST request with CSRF protection using fetch
 */
export async function csrfPost(url: string, data?: any): Promise<Response> {
    const token = getCsrfToken();
    
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token || '',
            'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: data ? JSON.stringify(data) : undefined,
    });
}

/**
 * Get fresh CSRF token from server
 */
async function refreshCsrfToken(): Promise<string | null> {
    try {
        const response = await fetch('/login', {
            method: 'GET',
            credentials: 'same-origin',
        });
        
        if (response.ok) {
            const html = await response.text();
            const match = html.match(/name="csrf-token"\s+content="([^"]+)"/);
            return match ? match[1] : null;
        }
    } catch (error) {
        console.error('Failed to refresh CSRF token:', error);
    }
    return null;
}

/**
 * Logout helper that uses fetch instead of Inertia
 */
export async function logoutUser(redirectUrl: string = '/'): Promise<void> {
    try {
        let token = getCsrfToken();
        console.log('Initial CSRF Token:', token); // Debug log
        
        if (!token) {
            console.log('No token found, trying to refresh...'); // Debug log
            token = await refreshCsrfToken();
            console.log('Refreshed CSRF Token:', token); // Debug log
        }
        
        if (!token) {
            console.error('No CSRF token available after refresh attempt');
            // Fallback: just redirect without proper logout
            window.location.href = redirectUrl;
            return;
        }
        
        // Try multiple approaches in sequence
        const approaches = [
            // Approach 1: Standard Laravel form submission
            {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: `_token=${encodeURIComponent(token)}`,
            },
            // Approach 2: Simple form with just token
            {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': token,
                },
                body: `_token=${encodeURIComponent(token)}`,
            },
            // Approach 3: Just header token
            {
                headers: {
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: '',
            },
        ];
        
        for (let i = 0; i < approaches.length; i++) {
            const approach = approaches[i];
            console.log(`Trying logout approach ${i + 1}...`); // Debug log
            
            const response = await fetch('/logout', {
                method: 'POST',
                headers: approach.headers,
                credentials: 'same-origin',
                body: approach.body,
            });
            
            console.log(`Approach ${i + 1} response:`, response.status, response.statusText); // Debug log
            
            // Success with 200 or redirect
            if (response.ok || response.status === 302) {
                console.log('Logout successful, redirecting...'); // Debug log
                if (typeof window !== 'undefined') {
                    window.location.href = redirectUrl;
                }
                return;
            }
            
            // If 419, try refreshing token for next attempt
            if (response.status === 419 && i < approaches.length - 1) {
                console.log('419 error, refreshing token for next attempt...'); // Debug log
                token = await refreshCsrfToken();
                if (token) {
                    // Update the remaining approaches with new token
                    for (let j = i + 1; j < approaches.length; j++) {
                        approaches[j].headers['X-CSRF-TOKEN'] = token;
                        if (approaches[j].body.includes('_token=')) {
                            approaches[j].body = `_token=${encodeURIComponent(token)}`;
                        }
                    }
                }
            }
        }
        
        // If all approaches failed, force redirect
        console.error('All logout approaches failed, forcing redirect...'); // Debug log
        window.location.href = redirectUrl;
        
    } catch (error) {
        console.error('Logout error:', error);
        // Fallback: redirect anyway
        window.location.href = redirectUrl;
    }
}