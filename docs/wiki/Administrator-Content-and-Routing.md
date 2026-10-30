# Administrator Guide: Content, pages, and routing

This chapter explains how content becomes a public URL in Stream Engine. Use
it after completing the
[initial configuration](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration).

Make structural changes on a staging site first. A page-tree error can prevent
normal requests from starting, so take a current database backup before
changing an established site's routes.

## The four parts of a public page

Stream Engine separates content from the URL and navigation that expose it:

```mermaid
flowchart LR
    F[Feed<br>content record] --> P[Page action<br>selects and renders content]
    P --> R[Route<br>public URL]
    M[Menu item<br>optional navigation] --> R
```

- A **feed** is a content record. It has a type, title, slug, body, optional
  parent, visibility, image, and ordering position.
- A **page** is a node in the public routing tree. Its pattern and parent
  determine a URL segment, while its action determines which module renders
  the request.
- A **page action** is supplied by an enabled module. It defines which feed or
  other fields are required. The page editor displays and validates that
  contract.
- A **menu item** is an optional link. It does not create a route and its
  access setting is not a security boundary.

Saving a feed alone does not guarantee that it has a public URL. Saving a menu
item alone does not create a page. The route must exist, its page action must
be configured correctly, and both the page and the selected content must
allow the visitor to access them.

## Before editing content

Sign in as an administrator and open **Administration**. The relevant areas
are **Feeds**, **Pages**, and **Menus**.

Before a route change:

1. record the current page ID, parent, pattern, action, feed fields, access,
   and menu items that reference it;
2. save a database backup;
3. test the proposed URL while signed in and in a private browser window;
4. plan redirects for any public URL that will move; and
5. make one structural change at a time.

The administration interface has no redirect manager. Configure permanent
redirects in the reverse proxy or web server before moving a URL that visitors
or search engines may already know.

## Manage feeds

Open **Administration → Feeds** to find content records. You can filter by
type, ID, slug, owner ID, or parent ID. The default view is limited to 100
newest records; use a narrow filter instead of assuming that an absent record
does not exist.

### Feed fields

| Field | Meaning |
| --- | --- |
| Title | Human-readable content title. |
| Type | Content contract supplied by a module, such as `article` or `article-section`. Choose the type before connecting the feed to a page action. |
| Slug | URL-safe name used by dynamic routes. Keep it stable and unique within the relevant parent and type. Use short lowercase words separated by hyphens. |
| Parent ID | Optional feed hierarchy. This is a feed ID, not a page ID. Dynamic article routes use it to keep content in the same hierarchy as the page route. |
| Visibility | Content-level access: `public`, `members`, or `private`. See [Access has three layers](#access-has-three-layers). |
| Position | Non-negative ordering value used by parent-based listings; lower values sort first where the listing supports position order. |
| Description | Summary used by supported views and metadata. |
| Image URL | Existing image URL. The upload button beside the content editor inserts an uploaded image into the body instead of filling this field. |
| Content | Sanitized HTML body edited in the source editor. Preview the result in the active theme after saving. |

After creation, the browser address changes to `/admin/feeds/ID`. You do not
need to note this ID for a page: the page editor finds feeds by title. You
still need it when another feed uses this record as its parent, because
**Parent ID** is a numeric field.

The top of the feed form shows the page the feed is bound to, with a link to
it, or says that the feed is not bound to any page.

> [!CAUTION]
> The generic feed editor does not currently provide a delete operation.
> Do not delete feed rows directly from the database: related content and cached
> URLs may depend on them. Restrict access while deciding on a supported
> content-removal workflow.

## Understand the page tree

Open **Administration → Pages** to see the routing table in hierarchy order.
Page ID `1` is the root page at `/`. Its pattern and parent cannot be changed,
and it cannot be deleted.

Every other page requires a parent. The public path is built from its ancestor
patterns. For example:

| Parent path | Child pattern | Resulting path |
| --- | --- | --- |
| `/` | `about` | `/about/` |
| `/` | `articles` | `/articles/` |
| `/articles/` | `{slug}` | `/articles/{slug}/` |

A static pattern is literal. A dynamic pattern contains a placeholder such as
`{slug}`. Stream Engine also supports a constrained placeholder such as
`{id:\d+}`, but administrators should use only a pattern required by the
selected module's documented route. An invalid regular expression can break
request startup.

Static siblings take precedence over a dynamic sibling. If `/profile/` and
`/{slug}/` share the root parent, `/profile/` reaches the static page and an
otherwise unmatched segment can reach the dynamic page.

### Route rules that must remain true

- Page IDs must be unique.
- Every non-root page must reference an existing parent.
- A page cannot be its own parent or a child of one of its descendants.
- Static sibling patterns must be unique.
- Only one dynamic sibling pattern is allowed under a given parent.
- Page actions marked as singleton must remain unique in the complete runtime
  page tree. Other actions may be reused by multiple concrete pages.

The editor prevents invalid parent cycles and validates action-specific fields.
Before saving, it also checks the complete page tree the site will load,
including pages added by modules (for example forum topics under the forums
page) and the built-in `api` and `admin` paths. A change that would break any of
the rules above is rejected with an error and is not saved.
Changes made directly in the database are not checked: the runtime validates
the complete tree on the next request and may fail to start if such a change
conflicts. Keep the database backup until the site has passed its post-change
checks.

## Configure a page action

Select the action first. The editor then marks **Feed**, **Feed type**,
**List feed type**, and **Term vocabulary** as required, optional, fixed, or
unused for that action. Clear values reported as unsupported. Do not copy
field combinations from a different action.

The **Feed** field pins one feed to the page:

- Type part of a title to search, or enter an ID as `42` or `#42`. If the
  action accepts only certain feed types, only feeds of those types are
  listed. Each result shows the title, ID, type, slug and parent. An action
  that does not restrict the type searches all feeds by full text and needs
  at least three characters.
- Select **Create feed** below the field to create a feed with a title,
  optional slug and type. Only the types the action accepts are offered. Set
  the parent, content and other fields afterwards in the Feeds editor.
- Select **Open feed** to edit the selected feed.

A feed can be bound to only one page. The picker lists feeds that are
already used by another page together with that page's path, but you cannot
select them, and the server rejects the conflict when you save. Older
duplicate bindings that are not changed remain saveable, so that you can
clean them up. To move a feed to a different page, clear the **Feed** field
on the page that currently uses it first.

The **Feed** column of the Pages list shows the title of the bound feed,
with its ID and the page's feed type below it.

Common article actions are:

| Action | Use |
| --- | --- |
| `article.show-id` | Render one fixed `article` selected in the **Feed** field. The installed root page uses this action for the welcome article. |
| `article.show-slug` | Render an `article` selected from a `{slug}` route. Feed type is fixed to `article`. |
| `articles.list` | Resolve an `article-section` from the route and list its child `article` feeds. |
| `sections.list` | List `article-section` children under a fixed root feed selected in the **Feed** field. |

Other enabled modules add their own actions. Use the descriptions and field
validation shown by the editor; unavailable legacy actions may be preserved
on an existing page but cannot be selected for a new one.

**Page name** supplies a fallback display name. **Article content width** is
available for article display actions. **Show comments** and **Show share
buttons** affect only views and themes that support those features.

## Worked example: publish `/about/`

This example publishes an About article at `/about/` and adds a navigation
link. It shows two ways to do it:

- **Option A: a dynamic article route.** One `{slug}` page serves every
  public article under the root feed. Choose this option when the site will
  have several standalone articles.
- **Option B: a fixed page.** A static `about` page shows exactly one
  article. Choose this option for a single page, or when articles must not
  appear at arbitrary top-level slugs.

Both options end with the same verification and navigation steps. Try the
example on a new or staging installation first.

### 1. Inspect the root page and existing routes

1. Open **Administration → Pages**.
2. Find page `#1`, the root page. The **Feed** column shows the title of its
   feed, with the feed ID below it, for example `Welcome` and `#1 · article`.
   Call this feed ID `ROOT_FEED_ID`. Select the title to open the feed.
3. Search the page list for `about` and for the action `article.show-slug`.

If a page with the pattern `about` already exists, edit that page instead of
creating another one. If an `article.show-slug` page already exists, Option A
is already partly set up: skip step A2 and use the URL hierarchy that page
defines.

### Option A: a dynamic article route

#### A1. Create the article feed

Open **Administration → Feeds → New feed** and enter:

| Field | Value |
| --- | --- |
| Title | `About` |
| Type | `article` |
| Slug | `about` |
| Parent ID | `ROOT_FEED_ID` |
| Visibility | `public` |
| Position | `0`, or the desired order among sibling articles |
| Description | A short public summary |
| Content | The About page body as valid HTML |

Save the feed. Check that it now has an ID and is still attached to the
expected parent. The parent matters: `article.show-slug` looks up the article
in the feed hierarchy of the nearest ancestor page.

The quick-create form in the page editor sets only the title, slug and type,
so it cannot set a parent. Use the Feeds editor for this step.

#### A2. Create the dynamic page once

Skip this step if an `article.show-slug` page already exists. Otherwise, open
**Administration → Pages → New page** and enter:

| Field | Value |
| --- | --- |
| Pattern | `{slug}` |
| Action | `article.show-slug` |
| Page name | `Article` |
| Parent | `#1`, the root page |
| Feed type | `article` (filled automatically from the action contract) |
| List feed type | empty |
| Term vocabulary | empty |
| Feed | empty |
| Change frequency | `monthly` |
| Access | `public` |

Choose the content width and whether to show comments and share buttons, then
save. Leave **Feed** empty. This route finds articles by their slug and serves
all public `article` children of `ROOT_FEED_ID`. Pinning the About feed here
would replace the slug lookup with that one article.

To publish more articles, create another `article` feed under `ROOT_FEED_ID`
with a unique slug. Each one reuses the same `{slug}` page.

### Option B: a fixed page

Open **Administration → Pages → New page** and enter:

| Field | Value |
| --- | --- |
| Pattern | `about` |
| Action | `article.show-id` |
| Page name | `About` |
| Parent | `#1`, the root page |
| Change frequency | `monthly` |
| Access | `public` |

Then pick the feed:

- **If the About article already exists**, start typing its title in the
  **Feed** field, or enter its ID as `42` or `#42`. Only feeds of the type the
  action accepts (`article`) are listed. Each result shows the title, ID,
  type and slug.
- **If it does not exist yet**, select **Create feed** below the field. Enter
  the title `About` and, optionally, a slug. The type is fixed to `article`.
  The new feed is selected as soon as it is created.

Save the page. Then select **Open feed** below the field and fill in the
description and content of the article in the Feeds editor. The top of the
feed form shows which page the feed is bound to, with a link back to it.

A feed can be bound to only one page. If a feed is already used by another
page, the picker shows that page's path and does not let you select the feed.
The server rejects the same conflict when you save. To move a feed to a
different page, first clear the **Feed** field on the page that currently
uses it.

### 2. Verify the URL before adding navigation

Open `https://example.com/about/`, replacing the host with the site's
canonical host. Confirm all of the following:

- the request returns the About article rather than a 404 or another page;
- the title, description, body and images render correctly;
- the canonical URL uses the expected HTTPS host and path;
- comments and share controls match the page settings; and
- the same URL works in a private browser window.

With Option A, also revisit the existing top-level URLs. Static routes should
still take precedence over `{slug}`, but this check catches a misplaced parent
or a conflicting route.

In **Administration → Pages**, the **Feed** column of the page now shows the
title `About` instead of a bare ID.

### 3. Add navigation

Open **Administration → Menus → New menu item** and enter:

| Field | Value |
| --- | --- |
| Menu group | `top` |
| Type | `internal` for Option B; `external` for Option A |
| Label | `About` |
| Parent item | empty for a top-level item, or the desired item in the same group |
| Page | Option B only: the `about` page |
| URL | Option A only: `/about/` |
| Access | `public` |
| Enabled | on |

With Option B the menu item refers to a concrete page, so it keeps working if
the page's pattern changes later. Option A needs the `external` type: the
target page is dynamic, and the menu editor cannot bind the fixed `about`
slug to an `internal` page reference. A relative URL keeps the link on the
canonical site.

Save the item, check the menu preview, then open the public site and test the
link. Add menu items only for articles that belong in persistent navigation.

## Access has three layers

Access must be considered separately at each layer:

1. **Page access** gates the route: `public`, `authenticated`, `moderator`, or
   `admin`.
2. **Feed visibility** gates the selected content: `public`, `members`, or
   `private`, with owner and administrator overrides.
3. **Menu access** controls whether a navigation item is displayed.

Hiding a menu item does not protect its URL. Set the page access rule and feed
visibility first, then give the menu item the same or a stricter audience.

`members` and `private` visibility depend on membership in a content container.
For a generic global feed without a container, they do not mean “all signed-in
users” and may leave the item available only to its owner and administrators.
Use `public` for the worked example. User, moderator, ownership, and community
rules are covered in the next administrator chapter.

## Sitemap settings and current limits

**Change frequency** is a search-engine hint, not a publishing schedule and
not a cache lifetime. Use a realistic value. `never` still permits a sitemap
entry; it tells crawlers that the URL is not expected to change.

In the current release:

- a public static HTML page is included in `sitemap-pages.xml` unless its
  value is `noindex`;
- a public dynamic page with a feed type enables feed sitemap entries for that
  type;
- dynamic feed sitemap entries use the feed update time and do not include the
  page's change-frequency value; and
- `noindex` on a page controls generated sitemap inclusion only where the
  sitemap implementation checks it. It does not by itself guarantee a
  `robots` meta tag or HTTP header.

If exclusion from search engines is mandatory, verify the rendered response
and apply an appropriate reverse-proxy header or theme-level robots directive
in addition to checking the generated sitemap. Do not rely on the field label
alone.

The generated sitemap index is available at `/sitemap-index.xml` and is
advertised through `/robots.txt`. After publishing, verify both endpoints and
search the relevant sitemap for the expected canonical URL.

## Change or remove a route safely

Changing a page pattern or parent changes its URL and the paths of its
descendants. Changing a feed slug changes a dynamic URL. Before either change:

1. inventory inbound menu links and known external links;
2. create the replacement route and web-server redirect plan;
3. make the change during a maintenance window;
4. test as every intended audience; and
5. verify canonical links, menus, and sitemaps.

Feed canonical URLs are cached in Memcached for up to 24 hours. A route or
slug change can therefore leave an old canonical URL in rendered content or a
sitemap until that entry expires. Do not flush a shared Memcached service
indiscriminately; it may affect other applications or Stream Engine sites.

To delete a page, remove or repoint menu items that reference it and delete
its child pages first. The root page cannot be deleted. Page deletion is
immediate and cannot be undone through the interface, so retain the database
backup until verification is complete.

## Post-change checklist

- The intended URL works with and without a trailing slash as expected by the
  web-server configuration.
- Static sibling URLs still resolve correctly.
- Guests and each required signed-in role receive the intended response.
- Content visibility agrees with page and menu access.
- Canonical URLs use `SITE_URL` and HTTPS.
- Menus show the correct label and target to the correct audience.
- `/sitemap-index.xml` and the relevant child sitemap are valid.
- Application and PHP-FPM logs contain no page-tree, route-regex, action, or
  missing-feed errors.
- The rollback backup and old route details are retained until monitoring
  shows normal traffic.

[Back: Initial configuration](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide) · [Next: Users and permissions](https://github.com/bfhp/stream-engine/wiki/Administrator-Users-and-Permissions)
