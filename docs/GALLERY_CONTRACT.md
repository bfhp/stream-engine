# Gallery Module Contract and Development Plan

Date: November 13, 2026. Status: scaffold and metadata contract; public workflows are not implemented.

The Gallery module will let registered users create albums and upload
photographs. Visitors with access can browse them; registered users can
comment and rate photographs. Rankings expose the highest-rated photographs.
The controller currently contributes no pages, feed types, API or scheduled
tasks. Gallery uses the standard Feed editing and authorization mechanisms.
Feed type is selected at creation and cannot be changed through user-facing
updates, including the administration editor and API. Ordinary edits and
deletions are not blocked by a Gallery-specific reservation.

Gallery uses existing `feeds`, `feed_metadata` and `uploads` tables. No new
tables or Gallery migration are needed. Photo file references, dimensions and
processing state reside in the photo Feed's metadata. The album's metadata
stores its cover photo reference. Shared Upload records retain paths, MIME,
bytes and original filenames.

The Gallery service or controller will validate album/photo types, parent and
owner relationships, and combine shared AccessService checks for both records.
A separate policy class is not needed. Initial albums support
public/private visibility without memberships or containers. All image variants
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

Dependency direction: `Gallery -> Core / Service / Repository / Domain`.
Gallery must not import other modules. Module-private photo repositories,
services and view models stay in `src/Modules/Gallery`; the platform must not
import Gallery classes.

| Entity | Proposed source of truth |
| --- | --- |
| Album | Feed of type `gallery-album`: owner, title, description, visibility |
| Photograph | Feed of type `gallery-photo`, parented to its album: owner, title, caption, position |
| Image | Photo `feed_metadata`: Upload references, dimensions and processing state; shared `uploads`: file metadata |
| Album cover | Album `feed_metadata` referencing a ready photograph in the same album |
| Comments | Existing platform comment Feeds targeting the photograph |
| Votes | Existing `feed_ratings` and Feed rating aggregates |

The album and its photographs have the same owner. Creation and movement
validate album type, ownership and access on the server. A photograph cannot
become more visible than its album. Read access checks both records, including
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

Authenticate, verify CSRF, check album ownership, quotas and request limits
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
photo and album before handing an original or derivative to that delivery path;
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
the photo type and album access policy before wiring the UI or API.

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

The public mount will be `gallery.index`; runtime descendants will provide
album view, photo view, the owner's albums, album editing, uploads and ranking.
Only configurable mount actions belong in `pageActions()`. Final route patterns
and API payloads are fixed when these screens are implemented.

Templates belong under `Gallery/views/modules/gallery/` and extend shared
theme layouts. Use shared comment/rating components where supported. Include
captions and alt text, keyboard navigation, explicit upload progress and
per-file failures, accessible labels, translated messages and RTL support.
Translations must be added to every shared engine language catalog.

Every mutation checks authentication, resource authorization, HTTP method,
CSRF and input on the server. Album deletion removes or schedules deletion of
its photographs, comments, votes and files consistently; removing the cover
clears or replaces the cover. A failed cleanup must not restore public access.

## Implementation sequence and acceptance

1. Defined: storage/access rules and metadata contract using existing tables.
   Implement checks in the Gallery service or controller in stages 2–3. Verify
   metadata parsing, file references, cover membership and owner restrictions.
2. Implement album CRUD and paginated browsing. Verify isolation between owners
   and visibility on both album and direct photo requests.
3. Implement uploads, previews, ordering, covers and deletion. Verify invalid
   files, quota exhaustion, retries, partial storage failures and cleanup.
4. Connect comments and voting. Verify guest rejection, CSRF, moderation,
   self-vote rejection, changed votes and concurrent aggregate updates.
5. Add rankings and finish UI integration. Verify stable ordering, pagination,
   hidden-content exclusion, both themes, localization and keyboard access.

Register each capability only with its working implementation. Regenerate the
optimized Composer classmap when adding the controller; build frontend assets
when introducing browser entries. Run relevant PHPUnit, frontend, type,
formatting, CSRF and installation checks for the implemented phase.
