# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project intends to follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- New `footer_legal` widget placement for legal information in the site footer.
- Admin menu management with nested items, menu groups, link types, access rules, ordering, and protected CRUD API endpoints.

### Changed

- Redesigned the default user avatar and applied it to users without a custom avatar in the site header.
- Made article, forum, community, and blog-post routes resolve feeds within their declared type and parent scope instead of selecting an ambiguous global slug match.

## [0.1.0] - 2026-09-14

### Added

- Initial version.
