# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project intends to follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- A read-only admin catalog of page actions exported by module controllers through `pageActions()`.

### Changed

- Split dual-purpose article, community, and blog-post show page actions into explicit `-id` and `-slug` actions, including migration of existing pages.
- Replaced free-form action, feed type, list feed type, and parent ID fields in the Pages editor with searchable selectors while preserving unknown legacy values.
- Extended page actions with backend-enforced configuration contracts for feed IDs, feed types, list feed types, and term vocabularies, including constrained and compound requirements.
- Made the Pages editor explain and validate each action's supported fields, constrain feed-type choices, preserve legacy configuration, and warn about suspicious feed bindings and route patterns.
- Made the feedback page's hint feed optional; pages without a feed now use the configured page name and render without hint content or feed metadata.

### Fixed

- Prevented production startup failures when optimized Composer classmaps contain optional integration classes whose development-only dependencies are not installed.

## [0.2.1] - 2026-09-17

### Added

- A complete favicon set for modern browsers, legacy clients, and Apple touch icons — at long last.

### Changed

- Made Composer and Packagist the canonical distribution channel, with npm kept only for frontend development tooling.

## [0.2.0] - 2026-09-17

### Added

- Explicit URL ownership for generated public pages, fixed versioned API endpoints, and resource paths.
- Query parameters and URI fragments for generated action, page, feed, term, and listing URLs, with RFC 3986 encoding and consistent `?query#fragment` ordering.
- Frontend redirects for registration, feedback, and direct messages now use same-origin-validated page URLs resolved by backend routing instead of hard-coded route paths.
- New `footer_legal` widget placement for legal information in the site footer.
- Admin menu management with nested items, menu groups, link types, access rules, ordering, and protected CRUD API endpoints.

### Changed

- Centralized request error handling with JSON responses for API routes, HTML error pages elsewhere, and safe logging for internal failures.
- Centralized application-environment reads in `Config`, including development-mode behavior in `StreamEngine` and `PdoDatabase`.
- Canonical links in account emails and user notifications now use the configured `SITE_URL` instead of request host data, with explicit handling when the canonical URL is missing.
- Redesigned the default user avatar and applied it to users without a custom avatar in the site header.
- Made article, forum, community, and blog-post routes resolve feeds within their declared type and parent scope instead of selecting an ambiguous global slug match.
- Made an external once-per-minute scheduler the production cron model, while reducing the request-driven fallback from 49 out of 50 requests to 1 out of 50.
- Bounded feed list and search page sizes, respected search limits, rejected structured comment content, and made unsupported API methods consistently return 405.
- Added controller-level authorization to mutating API endpoints and stopped message edits from exposing whether another user's message is outside the editing window.

## [0.1.0] - 2026-09-14

### Added

- Initial version.
