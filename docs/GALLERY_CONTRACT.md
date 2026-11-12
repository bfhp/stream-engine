# Users Photo Gallery Contract and Development Plan

Date: November 13, 2026. Status: initial Users album service implemented; public workflows are not implemented.

Photo galleries belong to the Users module. Registered users will create
albums and upload photographs within a personal blog or a community. Visitors with access can browse them; registered users can
comment and rate photographs. Rankings expose the highest-rated photographs.
UsersController will own the photo pages and API. No photo pages, feed types,
API or scheduled tasks are registered yet; the standalone Gallery controller
has been removed. Gallery uses the standard Feed editing and authorization mechanisms.
Feed type is selected at creation and cannot be changed through user-facing
updates, including the administration editor and API. Ordinary edits and
deletions are not blocked by a Gallery-specific reservation.

Gallery uses existing `feeds`, `feed_metadata` and `uploads` tables. No new
tables or Gallery migration are needed. Photo file references, dimensions and
processing state reside in the photo Feed's metadata. The album's metadata
stores its cover photo reference. Shared Upload records retain paths, MIME,
bytes and original filenames.

The Users photo service or UsersController will validate album/photo types, parent and
author and context relationships, and combine shared AccessService checks for
the blog/community, album and photograph.
A separate policy class is not needed. Initial albums support
`public`/`members`/`private` visibility, matching blog and community posts. Community
membership and publication permissions reuse the existing Users community
rules; no separate gallery membership system is introduced. All image variants
will use protected local storage, including public photos, so making an album
private can revoke access without relocating files.

No Gallery Feed types are advertised yet. Before exposing them, album/photo
workflows must enforce these policies on writes, check ready state and cover
membership, and integrate effective album visibility into shared SQL listing,
search and direct Feed access. The module policy alone does not filter shared
queries. UploadService currently chooses private storage only for message
attachments; Gallery must extend that shared capability before uploads are
connected, without reusing message attachment purpose or endpoints.

## Scope and proposed defaults

The requested scope is albums, photo uploads, comments, votes and rankings.
The following defaults are implementation proposals, rather than additional
requirements agreed with the site owner:

- A photograph belongs to exactly one album; nested albums are excluded from
  the first version.
- Comments and votes target photographs. Album voting and user rankings are
  deferred.
- Guests can browse public content. Album creation, uploads, comments and
  votes require authentication.
- Owners manage their own albums and photographs; moderators use explicit
  platform permissions. Users cannot rate their own photographs.
- The initial image formats are JPEG, PNG and WebP. SVG, animated images,
  video, bulk imports and image editing are outside the first version.

## Architecture and data ownership

Dependency direction: `Users -> Core / Service / Repository / Domain`.
Photo services and view models stay in `src/Modules/Users` alongside the blog
and community services. UsersController owns routing and delegates photo
operations to a Users-local service. There is no separate Gallery module or
controller registration; the platform must not import Users photo classes.

| Entity | Proposed source of truth |
| --- | --- |
| Album | Feed of type `gallery-album`, parented to a personal `blog` or `community` Feed: owner, title, description, visibility |
| Photograph | Feed of type `gallery-photo`, parented to its album: owner, title, caption, position |
| Image | Photo `feed_metadata`: Upload references, dimensions and processing state; shared `uploads`: file metadata |
| Album cover | Album `feed_metadata` referencing a ready photograph in the same album |
| Comments | Existing platform comment Feeds targeting the photograph |
| Votes | Existing `feed_ratings` and Feed rating aggregates |

Every album belongs to exactly one personal blog or community, resolved from
its parent Feed. Every photograph belongs to exactly one album and inherits
that context. `owner_id` identifies the author, not the community; community
publication and management permissions use the existing CommunityService
rules. A personal album and its photographs belong to the blog owner; a
community photo's author may differ from the album author where community
permissions allow contribution. Upload ownership is checked against the photo
author. Parentage is the source of truth for context; any access container
fields must agree with that ancestry and existing AccessService conventions.

Creation and movement validate the full blog/community → album → photo chain,
publication permissions and access on the server. Initial moves stay within
the same blog/community; cross-context transfers are deferred. A photograph
cannot become more visible than its album or context. Read access checks the
blog/community, album and photograph, including
direct photo links, comments, previews, ranking results and file delivery.
Generic Feed mutations must enforce the same invariants before these types
are exposed in the editor; module endpoints alone are insufficient.

Image fields do not belong on the shared Feed model. Use the existing
FeedMetadataRepository and FeedService metadata loading/editing conventions.
Metadata values are strings; Gallery parses and validates them at its service
boundary. Missing or invalid metadata never implies a ready image.

| Metadata key | Feed | Value |
| --- | --- | --- |
| `gallery.original_upload_id` | Photograph | Positive decimal Upload ID |
| `gallery.preview_upload_id` | Photograph | Positive decimal Upload ID, distinct from the original |
| `gallery.width` | Photograph | Positive decimal pixel width |
| `gallery.height` | Photograph | Positive decimal pixel height |
| `gallery.state` | Photograph | `pending`, `ready` or `failed` |
| `gallery.updated_at` | Photograph | Decimal Unix timestamp of processing state change |
| `gallery.cover_photo_id` | Album | Positive decimal photo Feed ID; absent means no selected cover |

These keys define the persistence contract for the upcoming workflows; no
upload or metadata-writing workflow is implemented yet. Publishing a ready
photo requires valid original/preview references, positive dimensions and
verification that both Upload records belong to the photo owner and represent
Gallery files. Cover selection checks that the photo is ready and belongs to
the album. These are service validations, not foreign-key constraints.

Updating Gallery metadata must preserve unrelated Feed metadata. Since
`replaceForFeed()` replaces the entire map, workflows must merge existing
values and serialize concurrent changes in a transaction using a Feed row
lock. File cleanup must check references in metadata before removing an
Upload. Deleting or moving a photograph clears or replaces the previous
album's cover. Generic edits must use the same validation and cleanup rules
before the module exposes these records.

## Upload lifecycle

Authenticate, verify CSRF, check context publication rights, album management permissions, quotas and request limits
before accepting a file. Validate actual MIME and successful image decoding,
byte size, dimensions and decoded pixel limits. Limits must be configurable
and fixed before implementing uploads. Generate storage keys on the server;
original names are metadata only. Re-encode published derivatives and strip
EXIF, including location data, from all publicly delivered images.

Use the shared upload/image/storage capabilities behind platform contracts.
Current runtime uploads use local storage; S3-only delivery is not assumed.
Private albums require protected originals and derivatives: an unpredictable
public URL does not enforce visibility. Reuse the messenger's shared local
storage primitives (`FileStorage::storePrivateUserFile` and
`UploadService::sendPrivateFile`) with Gallery-owned access checks and routes.
Private files live beside the public uploads root and are delivered only through
Nginx's internal `/protected-uploads/` location. Gallery must authorize both the
photo, album and blog/community before handing an original or derivative to that delivery path;
message attachment purposes and membership checks remain messenger-specific.

An upload moves from `pending` to `ready` or `failed`. Publish the photo only
when its file and required previews are ready. A repeated request must not
publish duplicate photographs. Database and storage changes need compensating
cleanup and retryable deletion, since they share no transaction. Temporary
files and abandoned uploads expire. Replacing a file creates a new storage
reference; old files are removed only after checking references.

## Comments, votes and rankings

Reuse FeedService comment and rating operations; do not duplicate their tables
or call another module's controller. Verify that shared operations support
the photo type and context/album access policy before wiring the UI or API.

- Comments support creation and authorized editing/deletion, with platform
  sanitization, moderation and pagination.
- Votes use the platform's validated scale. One vote per user and photograph;
  changing a vote replaces its value. Keep aggregates transactional.
- Initial ranking: accessible, ready photographs with at least one vote,
  ordered by average descending, vote count descending, then Feed ID
  descending for deterministic pagination. Unrated images display no rating.
- Display average and vote count together. Album rankings, time windows and
  Bayesian weighting are later decisions.
- Access filtering happens before pagination and counting. Private or removed
  photographs must not leak through rankings, counts or cached responses.

## Planned screens and extension points

Photo routes are runtime descendants of the existing `user.show` and
`community.show-slug` pages. Their base pattern is `photos`, producing
`<user.show URL>/photos/` and `<community.show-slug URL>/photos/` (for example,
`/users/alice/photos/` and `/communities/devs/photos/` with those mounts).
A separate configurable page action, `photos.showcase`, will provide a photo
showcase across personal blogs and communities. It belongs to Users and will
be advertised in UsersController::pageActions() when implemented. Administrators
choose its mount URL through the page tree; it does not require a separate
Gallery module. The showcase lists only ready photographs accessible to the
viewer, applying context and album access filters before pagination and counts.
Photo links lead to their canonical personal-blog or community routes.

| Parent action | Planned list action | Relative pattern |
| --- | --- | --- |
| `user.show` | `user.photos` | `photos` |
| `community.show-slug` | `community.photos` | `photos` |

Album/photo views, album editing, uploads and rankings descend from the
corresponding photos route. Register runtime pages only when those workflows
work. The scoped photo routes remain runtime descendants; only the separate
showcase is a configurable photo mount in `pageActions()`. Resolve context
from the ancestor page and route parameters, never from a client-supplied owner
or context ID alone. Generate links with UrlGenerator and the ancestor params;
community `{slug}` must remain distinct from descendant album/photo identifiers.
Direct links and Feed canonical URLs must select the matching personal or
community route and reject resources belonging to a different context. API
mutations enforce the same context checks. Detailed descendant patterns and
API payloads will be fixed when the screens are implemented.

Templates belong under `Users/views/modules/users/photos/` and extend shared
theme layouts. Use shared comment/rating components where supported. Include
captions and alt text, keyboard navigation, explicit upload progress and
per-file failures, accessible labels, translated messages and RTL support.
Translations must be added to every shared engine language catalog.

Every mutation checks authentication, resource authorization, HTTP method,
CSRF and input on the server. Album deletion removes or schedules deletion of
its photographs, comments, votes and files consistently; removing the cover
clears or replaces the cover. A failed cleanup must not restore public access.

## Current implementation

`src/Modules/Users/PhotoService.php` provides album creation, context-scoped
reading and paginated listing, plus edit permission checks. It reloads context
and album through FeedService, uses CommunityService::canPost() for community
publication, and AccessService::canEditFeed() for management. Personal albums
require the blog owner. Container assignment matches BlogPostService, including
personal members-only content. Lists use shared SQL access filtering before
pagination and counting. No photo types, endpoints or pages are advertised yet.
No new core classes, tables or Feed fields are introduced. Cover references,
image references and processing fields remain reserved for `feed_metadata`;
empty albums require no service metadata.

Next: wire album forms and scoped runtime routes, then album updates/deletion,
metadata validation and protected photo uploads before exposing photographs.

## Implementation sequence and acceptance

1. Defined: storage/access rules and metadata contract using existing tables.
   Implement checks in the Users photo service or UsersController in stages 2–3. Verify
   metadata parsing, file references, cover membership and owner restrictions.
2. Implement album CRUD and paginated browsing. Verify isolation between blogs
   and communities, community contribution permissions, both `/photos/` route
   families, canonical URLs and visibility on album and direct photo requests.
3. Implement uploads, previews, ordering, covers and deletion. Verify invalid
   files, quota exhaustion, retries, partial storage failures and cleanup.
4. Connect comments and voting. Verify guest rejection, CSRF, moderation,
   self-vote rejection, changed votes and concurrent aggregate updates.
5. Add rankings and finish UI integration. Verify stable ordering, pagination,
   hidden-content exclusion, both themes, localization and keyboard access.

Register each capability only with its working implementation. Regenerate the
optimized Composer classmap after removing the standalone controller; build frontend assets
when introducing browser entries. Run relevant PHPUnit, frontend, type,
formatting, CSRF and installation checks for the implemented phase.
