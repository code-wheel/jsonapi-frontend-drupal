# Migration Guide (Hybrid Headless)

`jsonapi_frontend` is designed for **gradual migration**: keep Drupal running, move routes/content types to a frontend over time.

## Deployment modes

### 1) Split routing (no DNS change)

- Drupal stays on the main domain (e.g. `https://www.example.com`)
- Your router/CDN sends selected paths to the frontend (e.g. `/blog/*`)
- Everything else stays on Drupal

#### Router configuration (examples)

You need *some* layer that can do path-based routing (CDN, edge worker, reverse proxy, load balancer).

**Cloudflare Worker (simple and flexible)**

```js
export default {
  async fetch(request) {
    const url = new URL(request.url)
    const frontend = "https://my-site.vercel.app"

    const headlessPrefixes = ["/blog", "/news"]
    if (headlessPrefixes.some((p) => url.pathname.startsWith(p))) {
      return fetch(new Request(frontend + url.pathname + url.search, request))
    }

    return fetch(request)
  },
}
```

**nginx (reverse proxy)**

```nginx
location ^~ /blog/ { proxy_pass https://my-site.vercel.app; }
location ^~ /news/ { proxy_pass https://my-site.vercel.app; }
location / { proxy_pass http://drupal_upstream; }
```

**Apache VirtualHost**

```apache
ProxyPass /blog https://my-site.vercel.app/blog
ProxyPassReverse /blog https://my-site.vercel.app/blog
ProxyPass / http://localhost:8080/
ProxyPassReverse / http://localhost:8080/
```

**.htaccess (sometimes possible, sometimes not)**

This requires `mod_rewrite` + `mod_proxy` and is often disallowed on shared hosting:

```apache
RewriteEngine On
RewriteRule ^blog/(.*)$ https://my-site.vercel.app/blog/$1 [P,L]
RewriteRule ^news/(.*)$ https://my-site.vercel.app/news/$1 [P,L]
```

### 2) Frontend-first (“Next.js First”)

- Frontend is on the main domain (e.g. `https://www.example.com`)
- Drupal runs on an origin/subdomain (e.g. `https://cms.example.com`)
- The frontend renders headless content and proxies non-headless requests to Drupal

## 1. Install + configure the module

Install:

```bash
composer require drupal/jsonapi_frontend
drush en jsonapi_frontend
```

Configure at `/admin/config/services/jsonapi-frontend`:

- Choose **Deployment mode**
- Set **Drupal URL** (used for `drupal_url` and/or origin proxying)
- Select which bundles are **headless** (or enable all)
- (Optional) enable Views support (requires `jsonapi_views`)
- (Optional) enable cache revalidation webhooks

## 2. Frontend integration

You can use any framework. Two easy options:

- TypeScript client (optional): `@codewheel/jsonapi-frontend-client`
- Next.js starter (optional): https://github.com/code-wheel/jsonapi-frontend-next
- Astro starter (optional): https://github.com/code-wheel/jsonapi-frontend-astro

### Split routing frontend env

For the starter templates (Next.js / Astro):

```env
DEPLOYMENT_MODE=split_routing
DRUPAL_BASE_URL=https://www.example.com
```

### Frontend-first env

For the starter templates (Next.js / Astro):

```env
DEPLOYMENT_MODE=nextjs_first
DRUPAL_BASE_URL=https://cms.example.com
DRUPAL_ORIGIN_URL=https://cms.example.com
DRUPAL_PROXY_SECRET=your-secret-from-drupal-admin
```

## Astro static builds (optional)

Astro can run in SSR mode (like this starter) or in its default static mode (SSG). If you want SSG, you still use `/jsonapi/resolve` for correctness — the missing piece is getting a build-time list of paths.

- SSG works best with `split_routing` (static builds can’t proxy Drupal HTML like `nextjs_first`).
- Only pre-render public content; if your JSON:API requires per-user auth, prefer SSR.
- In Drupal admin (`/admin/config/services/jsonapi-frontend`), the “Static builds (SSG)” section shows copy/paste route list sources based on your headless bundle/View selections.

### Option A: JSON:API collection endpoints

Fetch collections for the bundles you want to pre-render and collect `path.alias`.

Example (pages):

```bash
curl "https://cms.example.com/jsonapi/node/page?filter[status]=1&fields[node--page]=path&page[limit]=50"
```

Example `getStaticPaths()` (for a catch-all route like `src/pages/[...slug].astro`):

```ts
export async function getStaticPaths() {
  const baseUrl = import.meta.env.DRUPAL_BASE_URL
  const url = new URL("/jsonapi/node/page", baseUrl)
  url.searchParams.set("filter[status]", "1")
  url.searchParams.set("fields[node--page]", "path")
  url.searchParams.set("page[limit]", "50")

  const doc = await fetch(url).then((r) => r.json())
  const paths = (doc.data ?? [])
    .map((node) => node?.attributes?.path?.alias)
    .filter((p) => typeof p === "string" && p.startsWith("/"))

  return paths.map((p) => ({
    params: { slug: p.split("/").filter(Boolean) },
    props: { path: p },
  }))
}
```

Then render each page by calling `/jsonapi/resolve` (for the path) and fetching the returned `jsonapi_url`.

If you have a lot of content, paginate using JSON:API `links.next` (or `page[offset]`/`page[limit]`).

### Option B: Built-in routes feed (recommended for SSG)

If you prefer one build-time routes feed, enable the “Routes feed endpoint” in the “Static builds (SSG)” section. Your build tooling can then page through a single endpoint:

```bash
curl -H "X-Routes-Secret: $ROUTES_FEED_SECRET" "https://cms.example.com/jsonapi/routes?_format=json&page[limit]=50"
```

Follow `links.next` until it is null. Each item includes `path` plus either `jsonapi_url` (entity) or `data_url` (View).

### Option C: Views route list (via `jsonapi_views`)

If you prefer one “routes feed”, create a View that returns the alias/path for everything you want to pre-render (and expose it via `jsonapi_views`).

Then fetch `/jsonapi/views/{view_id}/{display_id}` in `getStaticPaths()` and map each row into route params.

## Authentication & caching (optional)

- For best CDN caching, keep `/jsonapi/resolve` + JSON:API public (anonymous) and rely on entity access and published state.
- If you require authenticated reads, keep credentials server-side and forward the `Authorization` header through your router/proxy. Do not edge-cache auth responses.
- If you use cookie-based Drupal sessions for writes, you’ll need `X-CSRF-Token` (`/session/token`) plus a strict CORS policy; bearer tokens avoid CSRF.

## Security hardening (recommended)

### 1) Rate limit resolver + JSON:API

The resolver is safe, but it’s still an extra lookup. Treat it like part of your public JSON:API surface and rate limit it at the edge:

- `/jsonapi/resolve*` (path enumeration / load)
- `/jsonapi/*` (API load)

**Cloudflare (high-level)**

- Add a Rate Limiting rule or WAF rule for `/jsonapi/resolve*` and `/jsonapi/*` (block or managed challenge after a threshold).

**nginx (example)**

```nginx
limit_req_zone $binary_remote_addr zone=jsonapi_resolve:10m rate=30r/m;
limit_req_zone $binary_remote_addr zone=jsonapi_api:10m rate=120r/m;

location = /jsonapi/resolve {
  limit_req zone=jsonapi_resolve burst=30 nodelay;
  proxy_pass http://drupal_upstream;
}

location ^~ /jsonapi/ {
  limit_req zone=jsonapi_api burst=60 nodelay;
  proxy_pass http://drupal_upstream;
}
```

### 2) Prevent image-host abuse (Next.js)

In production, always restrict remote images to your Drupal host:

- Set `DRUPAL_IMAGE_DOMAIN`, or
- Ensure `DRUPAL_BASE_URL` is set at build time so the starter can derive a safe allowlist.

### 3) Host / redirect safety (Drupal)

- Set `trusted_host_patterns` in Drupal `settings.php` (prevents Host-header injection issues).
- Set “Drupal URL” in the module settings so generated `drupal_url` values are deterministic.

### 4) Keep secrets out of config exports

This module avoids storing secrets in config exports (config sync). Secrets are stored in Drupal state by default, and you can optionally override them in `settings.php` for deterministic deploys:

```php
$settings['jsonapi_frontend']['proxy_secret'] = getenv('DRUPAL_PROXY_SECRET');
$settings['jsonapi_frontend']['routes_secret'] = getenv('ROUTES_FEED_SECRET');
$settings['jsonapi_frontend']['revalidation_secret'] = getenv('REVALIDATION_SECRET');
```

In this mode the Drupal module enforces the `X-Proxy-Secret` header for most requests, and allows these paths through without the secret:
- `/jsonapi/*`, `/admin/*`, `/user/*`, `/batch*`, `/system*`

If you proxy Drupal HTML through your frontend, also proxy Drupal assets (commonly `/sites/*`, `/core/*`, `/modules/*`, `/themes/*`) so pages can load CSS/JS/files.

## 3. Routing

### Split routing

Create rules in your edge/router so headless paths go to the frontend, and everything else goes to Drupal. Example rule set:

```
/blog/*   → frontend
/news/*   → frontend
/*        → Drupal
```

### Frontend-first

Point the main domain to your frontend, and keep Drupal on an origin/subdomain.

## 4. Test

Resolve a path:

```bash
curl "https://YOUR-DRUPAL-BASE-URL/jsonapi/resolve?path=/about-us&_format=json"
```

Expected behavior:
- `resolved: false` for unknown/unviewable paths
- `kind: "entity"` with `jsonapi_url` for entities
- `kind: "view"` with `data_url` when `jsonapi_views` is installed and configured
- `headless: true/false` depending on your configuration

## 5. Migrate incrementally

- To move more content: enable additional bundles (and add routing rules in split-routing mode).
- To keep content on Drupal: leave bundles unchecked (the resolver returns `headless: false` and `drupal_url`).

## Multilingual (optional)

If you use multilingual routes:
- Pass `langcode` to the resolver, or
- Set **Resolver langcode fallback** to `current` in the module settings.
