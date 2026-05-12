# Bookdrop PRD

## Summary

Bookdrop is a minimal self-hosted web app for sending DRM-free EPUB books to a Kobo reader over Wi-Fi using Kobo's configurable sync endpoint.

It is not a library manager. It is a private dropzone plus a small Kobo sync API shim.

## Goals

- Upload EPUB files through a simple Livewire dropzone.
- Let a Kobo reader discover and download uploaded books via stock Kobo Sync.
- Require minimal setup and maintenance.
- Run development and production through Docker.
- Deploy production to the VPS `reef`.
- Avoid Calibre, Calibre-Web, metadata management, shelves, user libraries, built-in readers, and social/sharing features.

## Non-Goals

- Full ebook library management.
- Metadata editing UI.
- Multi-user accounts.
- Reading progress sync across apps.
- Storefront, recommendations, search, ratings, reviews, or collections.
- DRM handling.
- First-class PDF sync through Kobo's stock sync API.

## Target User

One technical user running a small app on a home server/VPS who wants to upload books from a browser and press **Sync** on a Kobo to receive them wirelessly.

## Core Use Case

1. User opens Bookdrop in a browser.
2. User drags one or more `.epub` files onto the page.
3. Bookdrop stores the files and extracts basic metadata.
4. User presses **Sync** on their Kobo.
5. Kobo calls Bookdrop's Kobo-compatible endpoint.
6. Bookdrop returns newly available books.
7. Kobo downloads the EPUB files from Bookdrop.

## Supported Formats

### MVP

- EPUB only.

### Explicitly Deferred

- PDF upload storage can be allowed later, but stock Kobo sync support for PDF is not part of MVP because existing Kobo sync implementations appear EPUB/KEPUB-oriented and PDF support is unreliable or unsupported.
- Optional future path: expose PDFs via OPDS/WebDAV for KOReader or manual download.

## Functional Requirements

### Upload Dropzone

- Single private upload page built as a Livewire component.
- Accept `.epub` files.
- Reject unsupported file types with a visible error.
- Store original file safely on disk.
- Generate a stable internal book ID.
- Extract best-effort metadata from EPUB:
  - title
  - author
  - file size
- Fall back to filename as title when metadata is missing.

### Book List

- Show uploaded books in newest-first order.
- Display title, author, filename, uploaded date, and sync status.
- Allow deleting a book from Bookdrop.

### Kobo Sync Endpoint

- Provide a private Kobo `api_endpoint` URL containing a long random token.
- Implement the minimum Kobo-compatible endpoints needed for device sync:
  - device auth stub
  - initialization response
  - library sync response
  - book file download URL
  - state/progress endpoint stubs returning success
  - analytics/other harmless stubs returning empty responses where needed
- Return uploaded EPUBs as Kobo library entitlements.
- Provide direct authenticated download URLs for EPUB files.

### Authentication

- Use the official Laravel Livewire starter kit auth flow.
- Disable public registration after the initial admin user exists.
- Kobo endpoint uses a separate long random token embedded in the endpoint URL.
- No public anonymous access to uploaded files.

### Configuration

- App exposes the exact line to place in the Kobo config:

```ini
[OneStoreServices]
api_endpoint=https://example.com/kobo/{token}
```

- App should work behind a reverse proxy with HTTPS.
- Production deployment targets CapRover on VPS `reef` (`Host reef`, SSH user `ploi`).
- Production container listens on HTTP port `80`; CapRover handles public routing and HTTPS.

## Data Model

### books

- `id` UUID
- `title` string
- `author` nullable string
- `original_filename` string
- `stored_path` string
- `format` enum: `epub`
- `size_bytes` integer
- `uploaded_at` timestamp
- `deleted_at` nullable timestamp

### settings

- `admin_password_hash`
- `kobo_token`
- `public_base_url`

## System Design

Recommended stack:

- Laravel 13.
- Official Laravel Livewire starter kit.
- Laravel Fortify auth from the starter kit.
- Livewire 4 for the dropzone and book list UI.
- Tailwind CSS for minimal functional styling.
- SQLite for the first version.
- Docker Compose for local development.
- CapRover deployment using a repository `Dockerfile` build.

Required packages:

- `mikespub/php-epub-meta` for EPUB metadata extraction.

Packages intentionally not used in MVP:

- `spatie/laravel-medialibrary`: well-maintained, but unnecessary for one EPUB file per book.
- `kiwilan/php-ebook`: useful later for broader ebook/PDF/cover support, but broader than MVP.
- `indy2kro/php-epub`: avoid due GPL-2.0-or-later licensing.
- `laravel/octane` / FrankenPHP worker mode: unnecessary for this low-traffic app.
- MySQL/PostgreSQL/Redis: unnecessary for single-user MVP.

Components:

- Livewire upload/dropzone component.
- Livewire book list/delete component.
- EPUB metadata extractor service.
- Book storage service using Laravel filesystem.
- Kobo sync controller.
- Download controller.
- Simple admin settings page.

Storage:

- SQLite database.
- Local filesystem for EPUB files.

## Error Handling

- Invalid upload: reject loudly with visible message.
- Metadata extraction failure: accept file, use filename as title.
- Missing stored file during sync/download: exclude from sync or return 404 on download.
- Invalid Kobo token: return 404 or 401.
- Unexpected Kobo endpoint requests: log request and return safe empty JSON where possible.

## Deployment Requirements

- Repository includes a production-ready `Dockerfile`.
- Repository includes root `captain-definition` for CapRover repository builds:

```json
{
  "schemaVersion": 2,
  "dockerfilePath": "./Dockerfile"
}
```

- Repository includes `docker-compose.yml` for local development.
- Repository includes `.env.example` documenting required variables.
- Docker image contains all PHP extensions needed by Laravel and `mikespub/php-epub-meta`, including DOM, XML, Zip, ZLib, mbstring, SQLite, pdo_sqlite, fileinfo, curl, and openssl.
- Production container listens on HTTP port `80`.
- CapRover "Container HTTP Port" should remain `80`.
- Production deployment targets CapRover on VPS `reef`.
- Production deployment is built by CapRover from the repository `Dockerfile`; no image registry is required for MVP.
- Production app must not require Docker Compose inside CapRover.
- CapRover app must be marked persistent.
- CapRover persistent directory is `/data`.
- `/data` stores:
  - SQLite database at `/data/database.sqlite`.
  - uploaded EPUB files at `/data/books`.
- Docker entrypoint must initialize missing persistent state on boot:
  - create `/data/books`.
  - create `/data/database.sqlite` if missing.
  - run `php artisan migrate --force`.
  - run Laravel optimization commands safe for production.
  - start the web server on port `80`.
- Production app is single-instance only because SQLite and local EPUB storage are not safe for horizontal scaling.
- Development workflow should not require host PHP, Composer, or Node beyond Docker itself.
- Production should assume CapRover's reverse proxy handles HTTPS.

## Success Criteria

- User can upload a DRM-free EPUB through the browser.
- Uploaded EPUB appears in Bookdrop list.
- Kobo configured with Bookdrop endpoint can sync and download the EPUB.
- Deleting a book prevents future sync/download.
- App can run locally via Docker Compose.
- App can run on `reef` via CapRover behind HTTPS reverse proxy.

## MVP Scope

Build only:

1. Laravel 13 app scaffold using the official Livewire starter kit.
2. Dockerized local development setup.
3. Authenticated Livewire upload/list/delete UI.
4. EPUB storage and basic metadata extraction via `mikespub/php-epub-meta`.
5. Minimal Kobo sync API for EPUB delivery.
6. CapRover deployment setup for `reef` using `captain-definition` and `Dockerfile`.
7. Setup instructions for editing `Kobo eReader.conf`.

Do not build:

- Shelves.
- Metadata editing.
- PDF sync.
- OPDS.
- KOReader support.
- Multi-user auth.
- Cover extraction.
- KEPUB conversion.
- Spatie Media Library integration.

## Open Questions

- Should uploaded EPUBs be converted to KEPUB later for better Kobo reading stats?
- Should PDF support be added separately via OPDS/WebDAV instead of Kobo sync?
- Should sync expose all books forever, or only books not yet downloaded by the Kobo?
