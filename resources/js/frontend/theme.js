/**
 * Account Portal theme switch.
 *
 * The server decides the initial <html class> (App\Services\ThemeResolver);
 * this module only flips the class on the client and persists the choice
 * through POST /profile/theme so the next page load renders the same way.
 * Exposed as window.portalTheme for the header button and profile form.
 */
const DARK_QUERY = '(prefers-color-scheme: dark)';

function applyTheme(theme) {
    const root = document.documentElement;
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia(DARK_QUERY).matches);

    root.classList.toggle('dark', dark);
    root.dataset.portalTheme = theme;
}

async function persistTheme(theme) {
    const meta = document.querySelector('meta[name="portal-theme-endpoint"]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (! meta || ! token) {
        return;
    }

    try {
        await fetch(meta.content, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ theme }),
        });
    } catch (error) {
        // Non-fatal: the class is already applied for this page view.
        console.warn('Could not save theme preference', error);
    }
}

window.portalTheme = {
    current() {
        return document.documentElement.dataset.portalTheme || 'light';
    },

    set(theme, { persist = true } = {}) {
        applyTheme(theme);

        if (persist) {
            persistTheme(theme);
        }
    },

    toggle() {
        const dark = document.documentElement.classList.contains('dark');
        this.set(dark ? 'light' : 'dark');
    },
};

// Follow OS changes while "system" is selected.
window.matchMedia(DARK_QUERY).addEventListener('change', () => {
    if (window.portalTheme.current() === 'system') {
        applyTheme('system');
    }
});
