import { test, type Page, type TestInfo } from "@playwright/test";
import { expectNoNewViolations } from "./scan";
import { expectKeyboardWalk } from "./keyboard";
import { expectNoHorizontalScroll, expectTextSpacingFits } from "./states";
import { requireParentWithChild, requireSection } from "./content";

/*
 * Content pages as a guest sees them: forums, user blogs, communities, search
 * and feedback. Each page gets the axe scan and a Tab walk; the pages people
 * read most also get a 320 CSS px reflow check. Nothing here saves.
 */

async function scanPage(page: Page, path: string, id: string, testInfo: TestInfo, walk = true) {
    await page.goto(path);
    await expectNoNewViolations(page, id, testInfo);
    if (walk) await expectKeyboardWalk(page);
}

async function scanNarrow(page: Page, path: string, id: string, testInfo: TestInfo) {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto(path);
    await expectNoHorizontalScroll(page);
    await expectNoNewViolations(page, id, testInfo);
}

test("forums: list, forum and topic", async ({ page }, testInfo) => {
    const forums = requireSection("forums.list");
    await scanPage(page, forums, "content/forums", testInfo);

    const { parent: forum, child: topic } = await requireParentWithChild(page, forums, "forum with a topic");
    await scanPage(page, forum, "content/forum", testInfo);
    await scanPage(page, topic, "content/forum-topic", testInfo);
    await scanNarrow(page, topic, "content/forum-topic-narrow", testInfo);
    await scanNarrow(page, forums, "content/forums-narrow", testInfo);
    await scanNarrow(page, forum, "content/forum-narrow", testInfo);
});

test("users: list, profile and blog post", async ({ page }, testInfo) => {
    const users = requireSection("users.list");
    await scanPage(page, users, "content/users", testInfo);

    const { parent: profile, child: post } = await requireParentWithChild(page, users, "profile with a blog post");
    await scanPage(page, profile, "content/user-profile", testInfo);
    await scanPage(page, post, "content/blog-post", testInfo);
    await scanNarrow(page, post, "content/blog-post-narrow", testInfo);
});

test("communities: list, community and post", async ({ page }, testInfo) => {
    const communities = requireSection("community.main");
    await scanPage(page, communities, "content/communities", testInfo);

    const { parent: community, child: post } = await requireParentWithChild(page, communities, "community with a post");
    await scanPage(page, community, "content/community", testInfo);
    await scanPage(page, post, "content/community-post", testInfo);
});

test("search results", async ({ page }, testInfo) => {
    const search = requireSection("search.results");
    await scanPage(page, `${search}?q=demo`, "content/search", testInfo);
});

test("feedback form", async ({ page }, testInfo) => {
    const feedback = requireSection("feedback.show");
    await scanPage(page, feedback, "content/feedback", testInfo);
});

/* WCAG 1.4.12 on the pages people read most. */
test("text spacing: forum topic and blog post", async ({ page }) => {
    const { child: topic } = await requireParentWithChild(page, requireSection("forums.list"), "forum with a topic");
    await page.goto(topic);
    await expectTextSpacingFits(page);

    const { child: post } = await requireParentWithChild(page, requireSection("users.list"), "profile with a blog post");
    await page.goto(post);
    await expectTextSpacingFits(page);
});
