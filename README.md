# Bookdrop

Minimal self-hosted EPUB dropzone with a Kobo-compatible sync endpoint.

## Local Docker setup

No host PHP, Composer, or Node required:

```bash
cp .env.example .env
perl -0pi -e 's/^APP_KEY=$/APP_KEY=base64:'"$(openssl rand -base64 32)"'/m' .env
docker compose up --build
```

Open `http://localhost:8080`, register the first admin user, then upload DRM-free `.epub` files. Registration returns 404 after the first user exists.

Local persistent data lives in the Docker volume `bookdrop-data`:

- `/data/database.sqlite`
- `/data/books`

## Kobo config

The dashboard shows the exact tokenized endpoint. Add it to `.kobo/Kobo/Kobo eReader.conf`:

```ini
[OneStoreServices]
api_endpoint=https://example.com/kobo/generated-token
```

Uploaded books are stored privately and served only through tokenized Kobo download URLs.

## CapRover deployment on `reef`

Production deploys are handled by GitHub Actions on every push to `main`:

1. Run Composer/npm setup and PHPUnit.
2. Build the Docker image on GitHub Actions with layer caching.
3. Push the image to GitHub Container Registry (`ghcr.io`).
4. Ask CapRover to deploy that immutable image tag.

Required GitHub repository secrets:

- `CAPROVER_SERVER`: CapRover captain URL, for example `https://captain.example.com`.
- `CAPROVER_APP`: CapRover app name, for example `bookdrop`.
- `CAPROVER_APP_TOKEN`: app deployment token from the CapRover Deployment tab.

CapRover must be able to pull the GHCR image. Either make the package public, or add `ghcr.io` as a private registry in CapRover using a GitHub token with package read access.

This repo also keeps `captain-definition` as a source-build fallback. The production container listens on port `80`; keep CapRover's **Container HTTP Port** set to `80` and enable HTTPS in CapRover.

Required CapRover settings:

- Mark app persistent.
- Persistent directory: `/data`.
- Single instance only. SQLite and local EPUB files are not safe for horizontal scaling.
- Set environment variables:
  - `APP_NAME=Bookdrop`
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_KEY=<output of php artisan key:generate --show>`
  - `LOG_LEVEL=warning`
  - `APP_URL=https://your-bookdrop-domain.example`
  - `BOOKDROP_PUBLIC_BASE_URL=https://your-bookdrop-domain.example` (required in production)
  - `DB_CONNECTION=sqlite`
  - `DB_DATABASE=/data/database.sqlite`
  - `BOOKDROP_STORAGE_PATH=/data`
  - `BOOKDROP_BOOKS_PATH=books`
  - `SESSION_DRIVER=database`
  - `CACHE_STORE=database`
  - `QUEUE_CONNECTION=sync`
  - `SESSION_SECURE_COOKIE=true`

On boot the container creates `/data/books` and `/data/database.sqlite`, runs migrations, caches config/routes/views, then starts Apache on port `80`.
