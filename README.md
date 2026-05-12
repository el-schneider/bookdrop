# Bookdrop

Minimal self-hosted EPUB dropzone with a Kobo-compatible sync endpoint.

## Local Docker setup

No host PHP, Composer, or Node required:

```bash
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

This repo contains `captain-definition` for CapRover repository builds. The production container listens on port `80`; keep CapRover's **Container HTTP Port** set to `80` and enable HTTPS in CapRover.

Required CapRover settings:

- Mark app persistent.
- Persistent directory: `/data`.
- Single instance only. SQLite and local EPUB files are not safe for horizontal scaling.
- Set environment variables:
  - `APP_NAME=Bookdrop`
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_KEY=<output of php artisan key:generate --show>`
  - `APP_URL=https://your-bookdrop-domain.example`
  - `BOOKDROP_PUBLIC_BASE_URL=https://your-bookdrop-domain.example`
  - `DB_CONNECTION=sqlite`
  - `DB_DATABASE=/data/database.sqlite`
  - `BOOKDROP_STORAGE_PATH=/data`
  - `BOOKDROP_BOOKS_PATH=books`
  - `SESSION_DRIVER=database`
  - `CACHE_STORE=database`
  - `QUEUE_CONNECTION=sync`
  - `SESSION_SECURE_COOKIE=true`

On boot the container creates `/data/books` and `/data/database.sqlite`, runs migrations, caches config/routes/views, then starts Apache on port `80`.
