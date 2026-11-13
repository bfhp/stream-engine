import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { OUTPUT_DIR } from './paths';

/** Real Twig + built assets; isolated HTTP fixtures, no site, credentials or database. */
export default defineConfig({
    testDir: '.',
    testMatch: 'gallery.spec.ts',
    outputDir: path.join(OUTPUT_DIR, 'gallery/test-results'),
    workers: 2,
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: path.join(OUTPUT_DIR, 'gallery/report') }]],
    use: {
        baseURL: 'http://gallery.test',
        reducedMotion: 'reduce',
        viewport: { width: 1280, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: process.env.A11Y_CHROMIUM_PATH ? { executablePath: process.env.A11Y_CHROMIUM_PATH } : {},
    },
    projects: ['default', 'bootstrap'].flatMap(theme => [
        ...(['light', 'dark'] as const).map(colorScheme => ({
            name: `${theme}-${colorScheme}`,
            use: { colorScheme },
            metadata: { theme, locale: 'en' },
        })),
        { name: `${theme}-rtl`, use: { colorScheme: 'light' as const }, metadata: { theme, locale: 'ar' } },
    ]),
});
