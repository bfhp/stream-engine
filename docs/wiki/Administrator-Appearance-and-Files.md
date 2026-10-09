# Appearance, Navigation, and Files

This chapter explains how to change the public presentation of a Stream Engine
site without changing application code. It covers themes, branding, menus,
widgets, and the administrator File Browser.

These controls affect public URLs and files immediately. Make changes in a
maintenance window when the site is busy, keep a current backup of the database
and uploads directory, and verify the result as both a guest and a signed-in
user.

> [!CAUTION]
> Widgets accept trusted HTML, and the File Browser can permanently delete
> public files. Give administrator access only to people who are allowed to
> publish unsanitized markup and manage the site's stored files.

## Themes

Open **Administration → Themes** to see themes installed on the server. A theme
controls public Twig templates, stylesheets, scripts, and its own settings. The
administration interface itself is not themed.

Selecting a theme card changes only the draft in the administration page.
Review its settings, use **Preview**, and then choose **Save** to activate the
theme. Saving both activates the selected theme and stores the values shown for
that theme. Settings belonging to other themes are retained, so switching back
restores their previously saved values.

The built-in themes currently offer a **Color mode** setting:

- **Dark** always uses the dark presentation;
- **Light** always uses the light presentation; and
- **System** follows the visitor's operating-system preference.

An installed third-party theme can declare additional text, colour, selection,
and Boolean settings. Their meaning belongs to that theme's documentation.

### Preview before activation

**Preview** opens the public home page in a new tab with a `theme_preview`
query parameter. Preview is available only to a signed-in administrator and
does not change the active theme for other visitors. It shows the selected
theme with the values currently stored for that theme; save changed settings
before relying on the public preview.

During review, check at least:

- the home page and one page for each important layout;
- desktop and narrow/mobile widths;
- top, bottom, and signed-in user menus, including nested items;
- widgets, breadcrumbs, forms, validation errors, and long content;
- both guest and authenticated views; and
- right-to-left presentation if an RTL locale is enabled.

The preview parameter applies to the page on which it is present. Links opened
from that page do not have to retain it, so open representative URLs explicitly
with the same parameter when testing several pages.

### Theme fallback and inheritance

Themes are deployed by a developer or release operator; the administration
interface cannot upload them. Stream Engine discovers valid theme directories
and ignores themes whose manifest, parent chain, or required assets are
invalid. A parent cycle, a missing parent, or an excessively deep inheritance
chain also makes a theme unavailable.

If the configured active theme is removed or becomes invalid, Stream Engine
uses the built-in `default` theme and the Themes page reports that fallback is
active. The public site remains available, but the condition should be treated
as a deployment problem:

1. do not repeatedly save unrelated theme settings;
2. restore the missing theme files from the matching release or deployment;
3. reload **Administration → Themes** and confirm the warning has cleared; and
4. preview and verify the theme before returning the site to normal operation.

At an administrator level, template lookup works from the active theme through
its declared parents, then through `default`, and finally through module
templates. A child theme therefore needs to contain only the templates it
overrides. Theme assets can also inherit from parents, although a theme may
explicitly disable asset inheritance. This fallback is a compatibility aid,
not a substitute for testing a theme against the installed Stream Engine
release.

Site-owned theme templates and public theme assets are application files, not
uploads. Keep them in the deployment source, deploy them as a versioned unit,
and include them in the site's backup inventory. Do not edit files inside a
released package in place; an upgrade can replace those changes.

## Header logo and site icon

Branding is global and remains selected when the theme changes. Open
**Administration → Settings** to upload or remove the header logo and to upload
or restore the site icon. Exact formats, limits, generated variants, and the
Imagick dependency are documented under
[Header logo and site icon](https://github.com/bfhp/stream-engine/wiki/Administrator-Configuration#header-logo-and-site-icon).

A theme decides where and how the header logo is rendered. After changing a
theme or branding file, check that the logo is legible in every colour mode,
does not overflow on a narrow screen, and still leaves the site name and
navigation usable. Also verify the favicon URL directly because browser icon
caches can survive a normal reload.

## Menus and navigation

Open **Administration → Menus** to manage public navigation. Menu access rules
control whether a link is displayed; the destination page has its own access
rule and remains the authoritative authorization check. Keep the two rules in
agreement, and always test a protected destination by entering its URL
directly.

The built-in themes render three menu groups:

| Group | Public location |
| --- | --- |
| `top` | Main site navigation in the header. |
| `bottom` | Quick links in the site footer. |
| `user` | The signed-in user's navigation. It is not built for guests. |

The editor accepts other group names, and the preview can display them, but the
built-in themes do not render them. Use a custom group only when the active
theme or site-specific code explicitly consumes it.

### Choose the item type

| Type | Use it for |
| --- | --- |
| **Internal** | A link to one fixed page. Stream Engine generates its canonical URL. |
| **Dynamic** | A page whose route placeholders are filled for the current user. At present, only `{username}` is supported; guests and users missing a required value do not see the item. |
| **External** | An off-site URL or a root-relative site URL that is not represented by a fixed page link. |
| **Action** | A supported application action. At present, the available action is **Log out**. |
| **Divider** | A visual separator with no label or destination. |

For an off-site link, enter the complete `https://` URL. For a local route that
cannot use **Internal**, enter a root-relative URL such as `/about/`. Do not use
`javascript:`, `data:`, or another executable URL scheme. External URLs are
administrator-controlled input and must be reviewed before publication.

Each non-divider item requires a label. Choose its group, optional parent,
audience, enabled state, and destination. The available audience rules are
**Public**, **Authenticated**, **Moderator**, and **Administrator**. Public is
inclusive: it does not mean guest-only.

### Nest and order items

Parent and child items must belong to the same group. The editor prevents an
item from becoming its own parent or descendant. On the menu list:

1. clear search and group filters before reordering;
2. drag items or use the indent and outdent controls to build the tree;
3. reorder groups when the consuming theme cares about group order;
4. choose **Save order**; and
5. open **Preview**, then verify the real public menu.

The administration preview reflects enabled items visible to the current
administrator. It is useful for checking hierarchy, but it does not prove what
a guest, ordinary user, or moderator will see.

Deleting a parent requires an explicit child strategy. **Promote children**
keeps its immediate children and moves them to the deleted item's parent.
**Delete children** removes the entire descendant branch. The latter is
destructive; record or export the intended tree before using it.

## Widgets

Open **Administration → Widgets** to place a reusable HTML fragment in one of
the theme's standard locations. Select a placement, edit the HTML, and choose
**Save**. Saving an empty value removes the widget from that placement.

| Placement | Standard-theme location |
| --- | --- |
| **After header** | Full-width block directly below the site header. |
| **Before content** | Immediately before the main page content. |
| **After content** | Immediately after the main page content. |
| **Sidebar top** | First block on layouts that have a sidebar. |
| **Sidebar bottom** | Last block on layouts that have a sidebar. |
| **Footer legal** | Left-hand footer column, before the copyright text. |
| **Footer contacts** | Right-hand footer column. |

Widgets have no audience selector. When a public template includes a
placement, its saved fragment is available to every visitor of that template.
A page without a sidebar will not show either sidebar placement, and a custom
theme may move or omit any placement.

> [!WARNING]
> Widget HTML is rendered without sanitization or escaping. It can break the
> page, load third-party resources, execute scripts, or expose visitor data.
> Paste only reviewed markup from a trusted source. Never place passwords,
> API keys, private embed tokens, or other secrets in a widget.

Prefer semantic HTML and existing theme classes. After every change, check the
browser console, keyboard navigation, mobile layout, light and dark modes, and
the site's Content Security Policy. To recover from broken markup, return to
the placement in **Widgets**, clear its value, and save.

## File Browser

Open **Administration → File Browser** to manage the configured local uploads
directory. It is not a general server file manager: paths are confined to the
uploads root, and symbolic links are neither listed nor followed.

The interface supports:

- opening folders and public files;
- creating a folder;
- uploading one or more accepted files;
- renaming a file or folder;
- dragging entries to move them to another folder;
- copying selected entries and pasting them into a folder; and
- deleting selected files or directory trees.

Move, rename, copy, and delete operations also update matching upload records
in the database. They cannot update URLs already embedded as text in content,
widgets, theme settings, or external sites. Before moving, renaming, or
deleting a published file, search for its current `/uploads/...` URL and plan
the corresponding content changes.

### Upload rules

Administrator uploads accept JPEG, PNG, WebP, GIF, MP3, Ogg audio, and PDF.
The server detects the content type and assigns the canonical extension; it
does not trust the extension supplied by the browser. Administrator images
keep their original bytes and are not re-encoded.

An individual file is limited to 50 MB. Administrators are exempt from the
per-user total upload quota, but PHP and the web server can impose smaller
request limits. If an allowed upload fails before Stream Engine reports a
validation error, compare `upload_max_filesize`, `post_max_size`, and the web
server request-body limit with the intended policy.

The visible file name is retained where possible, and an upload fails rather
than overwriting an existing name. Copy creates a name such as `copy` or
`copy 2` when the destination already contains that entry. Names and complete
relative paths are limited to 255 bytes.

### Destructive operations

> [!CAUTION]
> File Browser deletion is permanent and recursively removes a selected
> directory. There is no trash folder or undo action. Restore requires an
> external backup.

Use this procedure for a published file or directory:

1. take or confirm a current backup of both the uploads directory and database;
2. identify content, widget, branding, and external references to its public
   URL;
3. update or remove those references;
4. select the exact entry and review every name in the confirmation dialog;
5. delete it; and
6. verify the affected pages and check for broken requests in web-server logs.

Copying a directory duplicates its storage consumption. Moving a directory
into itself is rejected, but a large valid copy can still exhaust the volume.
Check free space before bulk operations. Avoid modifying the uploads directory
directly on disk: the filesystem and upload records can diverge, and direct
changes bypass the path and symlink protections used by the administration
interface.

## Appearance change checklist

- [ ] A current database and uploads backup exists.
- [ ] The selected theme was previewed on representative pages and widths.
- [ ] Theme fallback warnings are absent.
- [ ] Logo, site icon, colour modes, and RTL presentation were checked where
      applicable.
- [ ] Menu destinations and direct page access were tested as guest, user,
      moderator, and administrator where relevant.
- [ ] Only the `top`, `bottom`, and `user` groups are assumed by built-in themes.
- [ ] Widget HTML was reviewed as executable, public-site content.
- [ ] Published upload URLs were checked before file moves or deletion.
- [ ] Free storage and the 50 MB per-file limit were considered.
- [ ] Public pages and server logs were checked after the change.

[Back: Users and permissions](https://github.com/bfhp/stream-engine/wiki/Administrator-Users-and-Permissions) · [Administrator Guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Guide) · [Next: Scheduler and background tasks](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler)
