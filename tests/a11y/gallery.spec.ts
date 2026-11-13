import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { PROJECT_ROOT } from './paths';
import { WCAG_TAGS, formatViolations } from './format';
import { expectFocusContained, expectKeyboardWalk } from './keyboard';
import { expectNoHorizontalScroll } from './states';

let pages: Record<string, string>;
test.beforeAll(({}, info) => {
    pages = JSON.parse(execFileSync('php', [path.join(PROJECT_ROOT, 'tests/a11y/gallery-fixtures.php'), String(info.project.metadata.theme), String(info.project.metadata.locale), String(info.project.use.colorScheme)], { cwd: PROJECT_ROOT, encoding: 'utf8' })) as Record<string, string>;
});
test.beforeEach(async ({ page }) => {
    await page.route('http://gallery.test/**', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (url.pathname.startsWith('/assets/')) {
            const asset = path.resolve(PROJECT_ROOT, 'public', '.' + url.pathname);
            if (!asset.startsWith(path.join(PROJECT_ROOT, 'public/assets/'))) throw new Error('Invalid asset path');
            const contentType = asset.endsWith('.css') ? 'text/css' : asset.endsWith('.woff2') ? 'font/woff2' : asset.endsWith('.woff') ? 'font/woff' : 'application/javascript';
            await route.fulfill({ contentType, body: await readFile(asset) }); return;
        }
        if (url.pathname.startsWith('/image/')) {
            await route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600"><rect width="900" height="600" fill="#9bb4bf"/><circle cx="660" cy="170" r="65" fill="#f2dfad"/><path d="M0 600L330 130L900 600" fill="#526b70"/></svg>' }); return;
        }
        if (request.method() !== 'GET') {
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ratingAverage: 4.5, ratingCount: 3, userRating: 5 }) }); return;
        }
        const key = url.pathname + (url.searchParams.has('discussion') ? '?discussion=1' : url.searchParams.get('sort') === 'best' ? '?sort=best' : '');
        const html = pages[key];
        if (!html) throw new Error(`Missing gallery fixture: ${key}`);
        await route.fulfill({ contentType: 'text/html', body: html });
    });
    page.on('pageerror', error => { throw error; });
});

/** No baseline and no disabled rules: new gallery states must be clean. */
async function scan(page: Page, info: TestInfo, state: string) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    });
    const result = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
    await info.attach(`${state}.json`, { body: JSON.stringify(result.violations, null, 2), contentType: 'application/json' });
    expect(formatViolations(result.violations), state).toBe('');
    await expectNoHorizontalScroll(page);
}

test('sidebar: one named link, decorative previews, keyboard focus and compact empty state', async ({ page }, info) => {
    for (const n of [0, 1, 2, 3]) {
        await page.goto(`/sidebar/${n}/`);
        const link = page.locator('.users-sidebar-link');
        await expect(link).toHaveAccessibleName(n ? /128/ : /.+/);
        await expect(link.locator('img')).toHaveCount(n);
        for (const image of await link.locator('img').all()) await expect(image).toHaveAttribute('alt', '');
        await scan(page, info, `sidebar-${n}`);
    }
    await expectKeyboardWalk(page);
    await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, 'sidebar-narrow');
    await page.locator('.users-sidebar-link').focus(); await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/albums\/$/);
});

test('album cards, preview grid, latest and best showcase reflow at 320px', async ({ page }, info) => {
    for (const url of ['/albums/', '/album/', '/showcase/', '/showcase/?sort=best']) {
        await page.setViewportSize({ width: 1280, height: 900 }); await page.goto(url); await scan(page, info, url);
        await expectKeyboardWalk(page);
        await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, `narrow-${url}`);
    }
    await expect(page.locator('.users-photo-showcase-sort [aria-current="page"]')).toHaveAttribute('href', '/showcase/?sort=best');
});

test('viewer: keyboard opening, focus containment, navigation and focus return', async ({ page }, info) => {
    await page.goto('/album/');
    const opener = page.locator('[data-photo-open]').first(); await opener.focus(); await page.keyboard.press('Enter');
    const dialog = page.locator('dialog.users-photo-viewer-dialog'); await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAccessibleName(/.+/);
    await expect(page.locator('[data-photo-compose-open]')).toBeVisible();
    await scan(page, info, 'viewer-desktop'); await expectFocusContained(page, dialog);
    await page.locator('[data-photo-comments-close]').click();
    await page.locator('[data-photo-viewer-close]').first().focus(); await page.keyboard.press('ArrowRight');
    await expect(page).toHaveURL(/\/photo\/2\/$/);
    await page.keyboard.press('Escape'); await expect(dialog).toBeHidden(); await expect(opener).toBeFocused();
    await page.setViewportSize({ width: 320, height: 640 }); await opener.focus(); await page.keyboard.press('Enter');
    await scan(page, info, 'viewer-mobile');
    await page.locator('.users-photo-viewer__mobile-bar [data-photo-comments-toggle]').last().focus(); await page.keyboard.press('Enter');
    await expect(page.locator('[data-photo-compose-open]')).toBeVisible(); await scan(page, info, 'discussion-mobile');
    await page.keyboard.press('Escape'); await expect(page.locator('[data-photo-discussion]')).toBeHidden();
    await page.keyboard.press('Escape'); await expect(opener).toBeFocused();
});

test('comments: named editor, reply context, actions menu and load-more contrast', async ({ page }, info) => {
    await page.goto('/photo/1/?discussion=1');
    await page.locator('[data-photo-compose-open]').focus(); await page.keyboard.press('Enter');
    const editor = page.locator('[data-photo-comment-form] textarea'); await expect(editor).toBeFocused(); await expect(editor).toHaveAccessibleName(/.+/);
    await scan(page, info, 'composer');
    await page.locator('[data-photo-comment-cancel]').click(); await page.locator('[data-photo-comment-reply]').focus(); await page.keyboard.press('Enter');
    await expect(editor).toBeFocused(); await expect(page.locator('[data-photo-comment-context]')).toBeVisible(); await expect(editor).toHaveAccessibleDescription(/Maria/); await scan(page, info, 'reply');
    await page.locator('[data-photo-comment-cancel]').click();
    const menu = page.locator('[data-photo-comment-menu]'); const summary = menu.locator('summary');
    await summary.focus(); await page.keyboard.press('Enter'); await expect(menu).toHaveAttribute('open', '');
    await page.keyboard.press('ArrowDown'); await expect(page.locator('[data-photo-comment-edit]')).toBeFocused(); await scan(page, info, 'comment-menu');
    await page.keyboard.press('Escape'); await expect(summary).toBeFocused(); await expect(page.locator('[data-photo-discussion]')).toBeVisible();
    await page.goto('/guest/1/?discussion=1'); await scan(page, info, 'guest-ratings-and-comments');
    await expect(page.locator('[data-photo-rate]:disabled')).toHaveCount(5);
});

test('album management: selection, keyboard reorder, photo editor and settings dialogs', async ({ page }, info) => {
    await page.goto('/album/edit/'); await scan(page, info, 'album-manager');
    await page.locator('[data-album-select]').focus(); await page.keyboard.press('Enter');
    await expect(page.locator('[data-album-select-all]')).toHaveAccessibleName(/.+/); await scan(page, info, 'selection');
    await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, 'selection-narrow');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.locator('[data-album-cancel-selection]').click(); await expect(page.locator('[data-album-select]')).toBeFocused(); await expect(page.locator('[data-album-reorder]')).toBeVisible(); await page.locator('[data-album-reorder]').focus(); await page.keyboard.press('Enter');
    await expect(page.locator('[data-album-manager]')).toHaveAttribute('data-reordering', 'true');
    const card = page.locator('[data-photo-card]').first(); await card.focus(); await expect(card).toBeFocused(); await page.keyboard.press('End');
    await expect(page.locator('[data-photo-card]').last()).toHaveAttribute('data-slug', '1'); await scan(page, info, 'reorder');
    await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, 'reorder-narrow');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.locator('[data-album-cancel-order]').click();
    const settingsTrigger = page.locator('[data-album-settings-open]'); await settingsTrigger.focus(); await page.keyboard.press('Enter');
    const settings = page.locator('[data-album-settings]'); await expect(settings).toBeVisible(); await expect(settings).toHaveAccessibleName(/.+/); await scan(page, info, 'album-settings');
    await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, 'album-settings-narrow');
    await page.setViewportSize({ width: 1280, height: 900 });
    await expectFocusContained(page, settings); await page.keyboard.press('Escape'); await expect(settingsTrigger).toBeFocused();
    await page.locator('[data-photo-menu-toggle]').first().focus(); await page.keyboard.press('Enter');
    await page.locator('[data-photo-edit]').first().focus(); await page.keyboard.press('Enter');
    const photoEditor = page.locator('[data-photo-editor]'); await expect(photoEditor).toBeVisible(); await expect(photoEditor).toHaveAccessibleName(/.+/); await scan(page, info, 'photo-editor');
    await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, 'photo-editor-narrow');
    await page.setViewportSize({ width: 1280, height: 900 }); await expectFocusContained(page, photoEditor);
    await page.keyboard.press('Escape'); await expect(page.locator('[data-photo-menu-toggle]').first()).toBeFocused();
    await page.setViewportSize({ width: 320, height: 640 }); await scan(page, info, 'manager-narrow');
    await page.goto('/albums/new/'); await scan(page, info, 'new-album-narrow');
});
