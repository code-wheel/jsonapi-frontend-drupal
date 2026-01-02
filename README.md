# JSON:API Frontend

`jsonapi_frontend` is a minimal Drupal module that makes JSON:API frontend-ready by adding a **path → JSON:API URL** resolver endpoint.

- Resolve frontend paths (including aliases) to JSON:API resource URLs
- Optional Redirect support (honors `redirect` module; returns 301/302 info)
- Hybrid headless: choose what your frontend renders vs what stays on Drupal
- Optional Views support via `jsonapi_views`
- Optional secret-protected routes feed (`/jsonapi/routes`) for static builds (SSG)
- Optional cache revalidation webhooks for frontend caches (Next.js, etc.)
- Optional menu endpoint (`/jsonapi/menu/{menu}`) via `jsonapi_frontend_menu`

Documentation: https://www.drupal.org/docs/contributed-modules/jsonapi-frontend
Issue queue: https://www.drupal.org/project/issues/jsonapi_frontend

## Requirements

- Drupal 10 or 11
- Core modules: JSON:API, Path Alias

Optional:
- [`jsonapi_views`](https://www.drupal.org/project/jsonapi_views) (Views support)

## Install

```bash
composer require drupal/jsonapi_frontend
drush en jsonapi_frontend
```

## Try it

```bash
curl "https://your-site.com/jsonapi/resolve?path=/about-us&_format=json"
```

Typical successful entity resolution looks like:

```json
{
  "resolved": true,
  "kind": "entity",
  "jsonapi_url": "/jsonapi/node/page/…",
  "headless": true
}
```

## Frontend usage

### Option A: TypeScript client (optional)

```bash
npm i @codewheel/jsonapi-frontend-client
```

```ts
import { resolvePath, fetchJsonApi } from "@codewheel/jsonapi-frontend-client"

const resolved = await resolvePath("/about-us")
if (resolved.resolved && resolved.kind === "entity") {
  const doc = await fetchJsonApi(resolved.jsonapi_url)
  console.log(doc.data)
}
```

### Option B: Call the endpoint directly

```ts
const resolved = await fetch(`${DRUPAL_BASE_URL}/jsonapi/resolve?path=${path}&_format=json`).then((r) => r.json())
if (resolved.resolved && resolved.kind === "entity") {
  const doc = await fetch(`${DRUPAL_BASE_URL}${resolved.jsonapi_url}`).then((r) => r.json())
}
```

### Starters (optional)

- Next.js (routing + rendering + media helpers): https://github.com/code-wheel/jsonapi-frontend-next
- Astro (SSR + optional proxy middleware): https://github.com/code-wheel/jsonapi-frontend-astro
- Nuxt 3 + Remix recipes: see `MIGRATION.md`

#### One-click deploy (Vercel)

[![Deploy Next.js starter](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https://github.com/code-wheel/jsonapi-frontend-next&env=DRUPAL_BASE_URL&envDescription=Drupal%20site%20URL%20(example%3A%20https%3A%2F%2Fwww.example.com)&envLink=https%3A%2F%2Fgithub.com%2Fcode-wheel%2Fjsonapi-frontend-next%2Fblob%2Fmaster%2F.env.example)
[![Deploy Astro starter](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https://github.com/code-wheel/jsonapi-frontend-astro&env=DRUPAL_BASE_URL&envDescription=Drupal%20origin%20URL%20(example%3A%20https%3A%2F%2Fcms.example.com)&envLink=https%3A%2F%2Fgithub.com%2Fcode-wheel%2Fjsonapi-frontend-astro%2Fblob%2Fmaster%2F.env.example)

## Configuration

Configure at `/admin/config/services/jsonapi-frontend`.

Typical settings:
- Deployment mode (Split routing vs frontend-first)
- Drupal URL / origin + optional proxy secret
- Resolver cache max-age (anonymous-only)
- Resolver langcode fallback (`site_default` or `current`)
- Headless-enabled bundles (entities) and View displays

For deployment and migration examples, see `MIGRATION.md`.

## Supported content

- **Entities:** any canonical content entity route exposed by JSON:API (nodes, terms, media, users, and custom entities)
- **Views:** page displays with paths (requires `jsonapi_views`)
- **Layout Builder:** hybrid mode (keep bundles non-headless) or true headless via the optional add-on `jsonapi_frontend_layout` (`/jsonapi/layout/resolve`). See `MIGRATION.md`.

## Security notes

- The resolver respects entity access; unpublished/restricted content resolves as “not found”.
- Resolver caching is only applied for anonymous requests; authenticated requests return `Cache-Control: no-store`.
- If you enable the routes feed (`/jsonapi/routes`), keep the secret in build-only env vars and don’t expose it to browsers.
- For config-managed sites, secrets are stored outside config exports (state) and can be overridden in `settings.php`.
- The endpoint lives under `/jsonapi/` so it can share the same perimeter rules you apply to JSON:API.
- If you run “frontend-first”, you can protect the Drupal origin with a shared secret header.
- If you want a fully hidden origin, you can also require the proxy secret for `/jsonapi/*` (server-side fetching only).
- For authenticated JSON:API, keep credentials server-side (Basic/OAuth/JWT). Cookie-based writes require Drupal CSRF tokens (`/session/token`) and strict CORS.

## Links

- Migration guide: `MIGRATION.md`
- Changelog: `CHANGELOG.md`
- NPM client: https://www.npmjs.com/package/@codewheel/jsonapi-frontend-client
- Starter: https://github.com/code-wheel/jsonapi-frontend-next
- Menus (optional): https://www.drupal.org/project/jsonapi_frontend_menu
- Webforms (optional): https://www.drupal.org/project/jsonapi_frontend_webform
- Layout Builder (optional): https://www.drupal.org/project/jsonapi_frontend_layout
