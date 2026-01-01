# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.4] - 2026-01-01

### Added

- `/jsonapi/routes` secret-protected routes feed (optional) for build-time/SSG route enumeration

### Changed

- Secrets (proxy secret, routes feed secret, revalidation secret) are stored outside config exports by default (state), with optional `settings.php` overrides

### Fixed

- Added an update hook to migrate any existing secrets out of config storage

## [1.0.3] - 2026-01-01

### Changed

- Docs: clarify authentication, caching, and CSRF guidance

## [1.0.2] - 2025-12-31

### Changed

- Docs: public starter repo is `code-wheel/jsonapi-frontend-next`

## [1.0.1] - 2025-12-31

### Changed

- Docs: npm scope is now `@codewheel/*` (was `@codewheel-ai/*`)

## [1.0.0] - 2025-12-31

### Added

- `/jsonapi/resolve` endpoint (path → JSON:API URL)
- Hybrid headless configuration (per bundle; optional Views via `jsonapi_views`)
- Optional cache revalidation webhooks (frontend cache tags)
- Optional integrations:
  - Next.js starter template (`jsonapi-frontend-next`)
  - TypeScript client helpers (`@codewheel/jsonapi-frontend-client`)
- Resolver options:
  - Anonymous-only caching (configurable max-age)
  - Configurable langcode fallback when `langcode` is omitted (`site_default` or `current`)

### Security

- Respects entity access; restricted/unpublished content resolves as “not found”
- Optional origin protection via shared proxy secret (frontend-first mode)
- SSRF protection for webhook URLs

### Compatibility

- Drupal 10 or 11
