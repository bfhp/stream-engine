# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project intends to follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Explicit URL ownership for generated public pages, fixed versioned API endpoints, and resource paths.
- Query parameters and URI fragments for generated action, page, feed, term, and listing URLs, with RFC 3986 encoding and consistent `?query#fragment` ordering.
- Frontend redirects for registration, feedback, and direct messages now use same-origin-validated page URLs resolved by backend routing instead of hard-coded route paths.
- New `footer_legal` widget placement for legal information in the site footer.
- Admin menu management with nested items, menu groups, link types, access rules, ordering, and protected CRUD API endpoints.

### Changed

- Canonical links in account emails and user notifications now use the configured `SITE_URL` instead of request host data, with explicit handling when the canonical URL is missing.
- Redesigned the default user avatar and applied it to users without a custom avatar in the site header.
- Made article, forum, community, and blog-post routes resolve feeds within their declared type and parent scope instead of selecting an ambiguous global slug match.
- Made an external once-per-minute scheduler the production cron model, while reducing the request-driven fallback from 49 out of 50 requests to 1 out of 50.
- Bounded feed list and search page sizes, respected search limits, rejected structured comment content, and made unsupported API methods consistently return 405.
- Added controller-level authorization to mutating API endpoints and stopped message edits from exposing whether another user's message is outside the editing window.

## [0.1.0] - 2026-09-14

### Added

- Initial version.
