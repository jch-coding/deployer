import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import { LicensingVisitLoader } from '@/components/licensing-visit-loader';
import { initializeTheme } from './hooks/use-appearance';
import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
});

function syncCsrfMetaToken(token: string | undefined): void {
    if (! token) {
        return;
    }

    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.setAttribute('content', token);
}

const appName = import.meta.env.VITE_APP_NAME || 'Deployer';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        syncCsrfMetaToken((props.initialPage.props as { csrf_token?: string }).csrf_token);

        const root = createRoot(el);

        root.render(
            <StrictMode>
                <LicensingVisitLoader />
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

router.on('success', (event) => {
    syncCsrfMetaToken((event.detail.page.props as { csrf_token?: string }).csrf_token);
});

// This will set light / dark mode on load...
initializeTheme();
