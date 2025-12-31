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

- TypeScript client (optional): `@codewheel-ai/jsonapi-frontend-client`
- Next.js starter (optional): https://github.com/CodeWheel-AI/jsonapi-frontend-next

### Split routing frontend env

For the Next.js starter:

```env
DEPLOYMENT_MODE=split_routing
DRUPAL_BASE_URL=https://www.example.com
```

### Frontend-first env

For the Next.js starter:

```env
DEPLOYMENT_MODE=nextjs_first
DRUPAL_BASE_URL=https://cms.example.com
DRUPAL_ORIGIN_URL=https://cms.example.com
DRUPAL_PROXY_SECRET=your-secret-from-drupal-admin
```

In this mode the Drupal module enforces the `X-Proxy-Secret` header for most requests, and allows these paths through without the secret:
- `/jsonapi/*`, `/admin/*`, `/user/*`, `/batch*`, `/system*`

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
