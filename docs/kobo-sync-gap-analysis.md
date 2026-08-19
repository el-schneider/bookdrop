# Kobo sync: gap analysis and course of action

Status of `app/Http/Controllers/KoboController.php` against the real Kobo sync protocol, and a
staged plan to close the gap.

Reference implementations studied:

- Calibre-Web `cps/kobo.py`, `cps/kobo_auth.py`, `cps/kobo_sync_status.py`, `cps/services/SyncToken.py`, `cps/ub.py` — the de-facto reference. <https://github.com/janeczku/calibre-web/blob/master/cps/kobo.py>
- Komga Kobo integration (modern Kotlin implementation + docs). <https://komga.org/docs/guides/kobo/>
- `jstriblet/kobo-highlights-sync` — reverse-engineered annotation protocol. <https://github.com/jstriblet/kobo-highlights-sync>
- Kepubify. <https://pgaskin.net/kepubify/docs/>

## 1. Direct answers

**Reading states are not synced.** `KoboController::getState()` returns a hardcoded
`ReadyToRead` with `CurrentBookmark: null` and `SpentReadingMinutes: 0`.
`KoboController::putState()` discards the request body and answers `Success`. Nothing is
persisted. There is no reading-state table. The device believes the write succeeded, so
progress made on the Kobo is silently lost on a factory reset or a second device.

**Notes and highlights are not supported, and they are not part of this API at all.**
Annotations do not travel over the store sync API. They use a separate service that the device
resolves from the `reading_services_host` key of the `/v1/initialization` response:

```
POST  {reading_services_host}/api/v3/content/checkforchanges
GET   {reading_services_host}/api/v3/content/{contentId}/annotations
PATCH {reading_services_host}/api/v3/content/{contentId}/annotations
```

Bookdrop's initialization response does not emit `reading_services_host`, so the device keeps
talking to `readingservices.kobo.com` for annotations. Highlights therefore stay on the device
(and on Kobo's servers), never reaching Bookdrop.

**Which objects are sync objects at all.** The `/v1/library/sync` response is a flat JSON array
of tagged change records. The full vocabulary:

| Record | Meaning | Bookdrop |
| --- | --- | --- |
| `NewEntitlement` | book new to this device (`BookEntitlement` + `BookMetadata`, optional `ReadingState`) | emitted |
| `ChangedEntitlement` | known book whose metadata/entitlement changed | missing |
| `ChangedReadingState` | progress/status update for a known book | missing |
| `NewTag` / `ChangedTag` / `DeletedTag` | collections ("shelves" on the device) | missing |
| `BookEntitlement.IsRemoved` | archive/remove a book from the device | missing |

Annotations, dogears, and per-chapter statistics are **not** members of this vocabulary. They
belong to the reading-services API above.

## 2. Concrete defects in the current implementation

Ordered roughly by how much they hurt.

1. **No reading state persistence** (see above). Both directions dead.
2. **`getState()` returns the wrong shape.** Kobo expects a JSON *array* of reading states:
   `[{...}]`. Bookdrop returns `{"ReadingState": {...}}`.
3. **Deletions never reach the device.** `Book` uses `SoftDeletes`; a deleted book simply drops
   out of the sync query. The protocol requires a `ChangedEntitlement` carrying
   `BookEntitlement.IsRemoved: true`. `deleteEntitlement()` also answers `200 {"Result":"Success"}`
   where the reference returns `204` with an empty body.
4. **Metadata edits never reach the device.** `booksForSync()` filters on `uploaded_at` only, and
   `Book` has `$timestamps = false`. There is no `updated_at`, so nothing can ever be reported as
   changed — including a re-extracted cover or a corrected title.
5. **Sync token is a bare ISO-8601 string.** The real token is a base64-encoded JSON blob carrying
   five independent cursors: `books_last_created`, `books_last_modified`, `archive_last_modified`,
   `reading_state_last_modified`, `tags_last_modified`. With one timestamp, Bookdrop cannot
   distinguish new from changed, and cannot page reading states or tags independently. A token
   from the Kobo store (shape `blob.blob`) currently falls through `Carbon::parse()` into an
   exception and degrades to a full sync.
6. **No paging.** The device has a hard, non-configurable 30 s sync timeout. Reference
   implementations cap a response at 100 items and set `x-kobo-sync: continue` so the device
   immediately asks again. Bookdrop returns the entire library in one response. A large library
   plus a slow VPS equals a sync that never completes. Note the inverse failure mode too: emitting
   `continue` on an exhaustive batch pins the device cursor and causes an infinite sync loop.
7. **Truncated `/v1/initialization` resource map.** Bookdrop returns 10 keys. The device expects
   the full native map (~150 keys: `account_page`, `notebooks`, `reading_services_host`,
   `get_download_keys`, product/deals endpoints, …). Calibre-Web ships the whole native map and
   overwrites only the handful it serves itself. Anything the device wants that is absent from the
   map is a latent failure, and `reading_services_host` is exactly the key annotations need.
8. **Covers are unbounded and uncached.** `cover()` ignores `width`/`height` and unzips the EPUB on
   every request, returning the full-size image. The device requests several sizes per book per
   sync. Reference implementations bucket into small/medium/large thumbnails and cache.
9. **Stub responses have the wrong shapes.** `/v1/user/loyalty/benefits` must be
   `{"Benefits": {}}`; `/v1/analytics/gettests` must be
   `{"Result":"Success","TestKey":<x-kobo-userkey>,"Tests":{}}`. The catch-all returns `[]` for
   both.
10. **Thin `BookMetadata`.** `Series`, `Description`, `Language`, `Publisher`, `PublicationDate`
    are hardcoded or empty. Series in particular drives grouping on the device.
    `PhoneticPronunciations` should be `{}`, not `[]`.

## 3. Hard constraints found in research

These shape the plan; they are device-side facts, not implementation choices.

- **KEPUB is not cosmetic. Measured on this device, not just cited.** On firmware 4.45.23697,
  highlighting a sync-delivered book was attempted during a real 95-second reading session. The
  device wrote progress (0% -> 5%), reading time (6 s -> 101 s) and 21 analytics/event rows, but the
  `Bookmark` table stayed at 0 rows. Every other aspect of the session persisted; only the
  annotation was dropped. Note the books are already labelled `application/x-kobo-epub+zip`, so the
  MIME label is not sufficient - the missing in-content `koboSpan` markup is the cause.
  Serving plain EPUB caps what the device will do:
  - progress is tracked **per chapter only** — mid-chapter position is lost across sessions
    (Calibre-Web #1439, #2036; confirmed in Komga's docs);
  - **highlights and notes cannot be created at all** on an EPUB delivered via sync
    (Calibre-Web #1484);
  - no chapter graph, no estimated reading time.
  Every serious implementation converts with `kepubify` — Komga on the fly at download, Calibre-Web
  at sync time. `kepubify` is a single static Go binary; dropping it into the Dockerfile is a
  two-line change. Format string in `DownloadUrls` must then be `KEPUB` (not `EPUB3`/`EPUB`).
- **Annotation sync needs the host override plus a second small API**, and it only produces data
  once books are KEPUB. So it strictly follows the KEPUB stage.
- **Annotation store must be sticky.** If the server answers `GET /annotations` with an empty body
  *and* an ETag, the device wipes its local copy. Empty body with **no** ETag is the signal that
  makes the device `PATCH` its annotations up. Getting this backwards destroys user data.
- **The device is the only client.** No spec, no error messages worth reading. Budget for
  on-device logging: enable developer mode (search `devmodeon`), enable `sync` + `packetdump`
  logging categories, then `nc -v <kobo-ip> 5001`.
- **HTTPS only.** The sync protocol carries the Kobo user key in headers.

## 4. Proposed course of action

Six stages. Each is independently shippable and independently testable; stop after any of them.

### Stage 0 — protocol correctness (no schema change)

Fix items 2, 3 (response code), 6, 7, 8, 9, 10 above. Emit the full native resource map with
Bookdrop overrides, correct `getState()` to an array, cap the sync response at 100 items with
`x-kobo-sync: continue` **only when more items remain**, bucket cover sizes and cache them,
correct stub shapes, enrich `BookMetadata` from the EPUB (series, description, language,
publisher, pubdate).

Cheap, low risk, removes most of the latent breakage.

### Stage 1 — real sync token and entitlement lifecycle

- Add `created_at` / `updated_at` to `books` (drop `$timestamps = false`).
- Replace the ISO string token with the base64-JSON, five-cursor token; tolerate a Kobo store
  token (`.` in the value) by treating it as "no cursor".
- Emit `NewEntitlement` vs `ChangedEntitlement` off `books_last_created`.
- Query soft-deleted books and emit `ChangedEntitlement` with `IsRemoved: true` so deletions
  propagate; honour `DELETE /v1/library/{id}` by archiving instead of no-op.

This is the foundation for everything after it. No extra tables: each device carries its own
cursor in its own token, so multi-device works for free.

> Ceiling: token-only tracking means a device that loses its token gets a full re-sync, and a book
> re-added after deletion may not re-appear until the cursor moves past it. Calibre-Web keeps an
> explicit `kobo_synced_books` table for exactly this. Add that table only if drift shows up in
> practice.

### Stage 2 — reading state (the headline feature)

One table, not Calibre-Web's four:

```
reading_states: book_id, status, times_started_reading, last_time_started_reading,
                progress_percent, content_source_progress_percent,
                location_value, location_type, location_source,
                spent_reading_minutes, remaining_time_minutes,
                last_modified, priority_timestamp
```

- `PUT /v1/library/{id}/state`: persist `ReadingStates[0]`'s `CurrentBookmark`, `Statistics`,
  `StatusInfo`; bump `last_modified`; return per-section `Result` only for sections actually
  present in the request (be tolerant of missing keys — Calibre-Web's strict indexing 400s here).
- `GET`: return `[readingState]`.
- Sync: attach `ReadingState` to an entitlement when it changed, otherwise emit a standalone
  `ChangedReadingState`; never both for the same book in one response.
- Status mapping: `ReadyToRead` / `Reading` / `Finished`. Increment `times_started_reading` on the
  unread→reading transition.
- Serialise `ProgressPercent` as an int when it is a whole number; use `is not null` checks, `0` is
  valid.
- Surface status and percentage in the library UI. That is the payoff.

### Stage 3 — KEPUB conversion

Install `kepubify` in the Dockerfile. Convert on upload (queue job; `QUEUE_CONNECTION=sync` is
fine at this scale) and store the `.kepub.epub` alongside the original. Advertise `KEPUB` in
`DownloadUrls` when present, fall back to the EPUB when conversion fails — never block the upload.

Unlocks precise progress and makes Stage 5 possible at all.

### Stage 4 — collections

Tables `shelves` and `book_shelf`. Implement the tag endpoints
(`POST /v1/library/tags`, `PUT|DELETE /v1/library/tags/{id}`, `POST /v1/library/tags/{id}/items`,
`POST /v1/library/tags/{id}/items/delete`) and emit `NewTag` / `ChangedTag` / `DeletedTag` in the
sync response. Bidirectional: collections created on the device appear in Bookdrop and vice versa.

Note this contradicts the current PRD ("no shelves"). Decide explicitly before building.

### Stage 5 — annotations (highlights, notes, dogears)

**Hard dependency on Stage 3.** Until books carry KEPUB span markup the device will not create an
annotation at all, so there is nothing for this stage to sync. Confirmed by measurement (§3).

- Override `reading_services_host` in the initialization map to Bookdrop's own tokenized base.
- Implement `checkforchanges` / `GET annotations` / `PATCH annotations` under
  `/api/v3/content/{contentId}/...`, with `contentId` = the book UUID.
- Store annotations verbatim as JSON plus a per-book ETag. Payload shape:
  `{id, type: highlight|note|dogear, highlightedText, noteText, clientLastModifiedUtc,
  location: {span: {chapterFilename, chapterProgress, …}}}`.
- Respect the empty-body/no-ETag handshake described in §3.
- Render highlights on the book page.

### Stage 6 — optional reach

OPDS feed and/or KOReader sync endpoints, for phone and KOReader clients. Entirely independent of
the Kobo protocol; only worth it if the reading-state store from Stage 2 exists.

## 5. Recommended sequence

Stage 0 → 1 → 2 → 3 → 5, with 4 slotted wherever collections become annoying to live without.
Stages 0–2 alone move Bookdrop from "file dropzone" to "reading tracker" and are the bulk of the
perceived value. Stage 3 is small in code and large in effect.

## 6. Upgrade risk against a live instance

`docker/entrypoint.sh` runs `php artisan migrate --force` unattended on every boot against the single
SQLite file at `/data/database.sqlite`, and a push to `main` deploys automatically. Migrations are
therefore irreversible in practice unless a snapshot exists — the entrypoint now takes one before
migrating.

### Changes that alter what the device already holds

| Change | Effect on a device already in use | Mitigation |
| --- | --- | --- |
| `IsRemoved` for soft-deleted books (Stage 1) | Every book ever deleted server-side is removed from the device, including ones still being read | **Measured, no mitigation needed.** Prod holds 3 soft-deleted books; only one is still on the device, at 0% and 29 s of reading. Ship plain `IsRemoved` semantics |
| Reading state first emission (Stage 2) | Server has no state; pushing an empty `ReadyToRead` down can overwrite live on-device progress | Create state rows only in `putState()`, never in the sync handler. Never emit a `ReadingState` for a book the device has not PUT |
| KEPUB switch (Stage 3) | Device re-downloads a structurally different file; reading position does not carry over EPUB→KEPUB | Convert new uploads only, leave existing entitlements on EPUB |
| Sync token format (Stage 1) | Legacy ISO-8601 token is unparseable by the new reader → one full re-sync, possible re-download of the whole library | Keep parsing the legacy ISO string for one release and seed the cursors from it |

Entitlement IDs (`Id`, `RevisionId`, `CrossRevisionId`) are the stable book UUID, so a full re-sync
updates entitlements in place rather than duplicating them.

### Changes that can break the app or wedge the device

- **Do not set `reading_services_host` before Stage 5.** The catch-all
  `Route::any('{path}')->where('path', '.*')` answers `200 []` for everything under `/kobo/{token}/`.
  Pointing the device's annotation host at Bookdrop before the endpoints exist yields an empty body
  with no ETag, which makes the device `PATCH` its highlights up; the catch-all then answers `200`
  and the device treats them as synced while nothing was stored.
- **`x-kobo-sync: continue` on an exhaustive batch** pins the device cursor and produces a sustained
  ~3 req/s sync loop. Test the exact-`SYNC_ITEM_LIMIT` boundary.
- **The catch-all shadows later routes.** Laravel matches in registration order, so anything
  registered after it in `routes/web.php` never fires. `route:cache` at boot will not warn.
- **Do not `->change()` the `format` enum.** On SQLite it is a check constraint and altering it forces
  an unattended table rebuild on live data. Add a nullable `kepub_path` column instead.
- **Backfill `created_at` / `updated_at` from `uploaded_at`** in the Stage 1 migration. A null
  `LastModified` in an entitlement is a good way to confuse the device.

### Low risk

Stage 0 response-shape fixes, cover bucketing and caching, and richer `BookMetadata` only cause the
device to re-fetch. Emitting the full initialization resource map restores the device's normal
chatter with `storeapi.kobo.com` for products and deals, as before the endpoint was overridden.

## 7. Testing

`tests/Feature/KoboSyncTest.php` already covers auth tokens and delta sync. Extend per stage:
token round-trip (encode → decode → cursors preserved), `NewEntitlement` vs `ChangedEntitlement`
selection, `IsRemoved` on delete, `PUT` state persistence and `ChangedReadingState` emission,
paging boundary (exactly 100 items must **not** set `x-kobo-sync: continue`), annotation
ETag handshake (empty store must answer without an ETag).
