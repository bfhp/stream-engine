import { expect, test, type Page, type TestInfo } from "@playwright/test";
import { expectNoNewViolations } from "./scan";
import { expectKeyboardWalk } from "./keyboard";
import { requireParentWithChild, requireSection } from "./content";

/*
 * Content pages a signed-in member uses to write: topic replies, the new
 * topic form, the blog editor, messages and a community as a member. The
 * rich-text editor (Trix) is scanned with its link dialog open. Nothing here
 * saves.
 */

async function scanPage(page: Page, path: string, id: string, testInfo: TestInfo, walk = true) {
    await page.goto(path);
    await expectNoNewViolations(page, id, testInfo);
    if (walk) await expectKeyboardWalk(page);
}

async function firstTopic(page: Page): Promise<{ forum: string; topic: string }> {
    const { parent: forum, child: topic } = await requireParentWithChild(page, requireSection("forums.list"), "forum with a topic");

    return { forum, topic };
}

test("topic with the reply editor", async ({ page }, testInfo) => {
    const { topic } = await firstTopic(page);
    await scanPage(page, topic, "member/forum-topic", testInfo);

    // The Trix link dialog: open it from the toolbar of the reply editor.
    // The visible reply editor; the per-post edit editors are hidden.
    const editor = page.locator("trix-editor:visible").first();
    test.skip(await editor.count() === 0, "This topic has no reply editor.");
    const toolbar = page.locator(`#${await editor.getAttribute("toolbar")}`);
    await editor.click();
    await toolbar.locator('[data-trix-attribute="href"]').click();
    await expect(toolbar.locator('[data-trix-dialog="href"]')).toBeVisible();
    await expectNoNewViolations(page, "member/trix-link-dialog", testInfo);
});

test("new topic form", async ({ page }, testInfo) => {
    const { forum } = await firstTopic(page);
    await scanPage(page, `${forum}new/`, "member/forum-topic-new", testInfo);
});

test("blog post editor", async ({ page }, testInfo) => {
    const users = requireSection("users.list");
    await page.goto(users);
    // The own profile is the one that offers a "new post" link.
    const profiles = await page.locator("main a[href]").evaluateAll((links, base) =>
        [...new Set(links.map(link => new URL((link as HTMLAnchorElement).href).pathname)
            .filter(path => path.startsWith(base) && path.split("/").filter(Boolean).length === base.split("/").filter(Boolean).length + 1))],
    users);

    let editor: string | null = null;
    for (const profile of profiles.slice(0, 10)) {
        await page.goto(profile);
        if (await page.locator(`main a[href$="${profile}post/"]`).count() > 0) {
            editor = `${profile}post/`;
            break;
        }
    }
    test.skip(editor === null, "No profile of the signed-in user is listed.");
    await scanPage(page, editor as string, "member/blog-post-editor", testInfo);
});

test("messages inbox and a conversation", async ({ page }, testInfo) => {
    const inbox = requireSection("messages.inbox");
    await scanPage(page, inbox, "member/messages", testInfo);

    // The list loads after the page; wait for a row.
    const conversation = page.locator("[data-conv-id]").first();
    await conversation.waitFor({ timeout: 5_000 }).catch(() => undefined);
    test.skip(await conversation.count() === 0, "No conversation to open.");

    // Open it from the keyboard (WCAG 2.1.1).
    await conversation.focus();
    await expect(conversation).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(conversation).toHaveAttribute("aria-current", "true");
    await expectNoNewViolations(page, "member/messages-conversation", testInfo);
});

test("community as a member", async ({ page }, testInfo) => {
    const { parent: community } = await requireParentWithChild(page, requireSection("community.main"), "community with a post");
    await scanPage(page, community, "member/community", testInfo);
});
