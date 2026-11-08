# Video pipeline: Vidara.so storage + Mux processing

Raw uploads are stored on **Vidara.so** and handed to **Mux** for transcoding
and adaptive streaming. Both providers are reached only from the server.

## Configuration

| Variable | Used by | Where to get it |
| --- | --- | --- |
| `VIDARA_API_KEY` | `VidaraClient` | Vidara → Settings (`https://vidara.so/settings`) |
| `MUX_TOKEN_ID` | `MuxClient` (HTTP Basic) | Mux dashboard → Settings → Access Tokens |
| `MUX_SECRET_KEY` | `MuxClient` (HTTP Basic) | same access token as above |
| `MUX_WEBHOOK_SECRET` | `mux-webhook.php` signature check | Mux dashboard → Settings → Webhooks |

In the sandbox these are injected from `/run/base44/app.env`; locally copy
`.env.example` and fill it in. Values are read with `getenv()` and are never
written to the database, the page or a log line.

## Files

| File | Purpose |
| --- | --- |
| `class-vidara-client.php` | Vidara REST client: upload, upload-from-URL, file info, encoding status, thumbnail, delete |
| `class-mux-client.php` | Mux REST client: direct uploads, assets from URL, asset/upload status, delete, playback + poster URLs, webhook signature verification |
| `class-video-pipeline.php` | Orchestrates storage → processing, records state in `video_pipeline` |
| `video-pipeline.php` (repo root) | Admin-only JSON endpoint that drives the pipeline |
| `mux-webhook.php` (repo root) | Receives `video.asset.*` events and keeps the row in sync |

## Workflow

```
POST /video-pipeline.php?action=upload  (multipart: video)
  1. validate the upload
  2. VidaraClient::uploadFile()    -> filecode + watch URL   (permanent copy)
  3. MuxClient::createDirectUpload() -> one-time upload URL
  4. MuxClient::uploadFileToUrl()    -> asset created
  5. resolve the asset id, store the row (status: preparing)
  6. Mux fires video.asset.ready -> /mux-webhook.php -> playback id stored
GET /video-pipeline.php?action=status&id=<id>   -> refresh both providers
```

`action=direct` returns a Mux upload URL so a browser can PUT the file itself
(no PHP upload limit, no double handling). `action=url` lets Mux ingest an
already hosted file.

## Why the file goes to Vidara and Mux separately

Vidara's API returns a **watch-page** URL (`https://vidara.so/v/<filecode>`),
not a direct file URL, and Mux can only ingest a fetchable media file. So the
raw file is uploaded to Vidara for storage and streamed to Mux through a direct
upload. If you have a direct file URL for a video (for example a Vidara direct
link on a plan that provides one), use `action=url` instead and Mux ingests it
directly.

## Security notes

- Both endpoints require an administrator session (`video-pipeline.php`) and
  never expose credentials to the browser.
- `mux-webhook.php` rejects any request whose `Mux-Signature` does not match
  `HMAC-SHA256("<timestamp>.<raw body>", MUX_WEBHOOK_SECRET)`; without
  `MUX_WEBHOOK_SECRET` set it rejects everything.
- Uploads are limited to known video extensions / `video/*` MIME types.
- `action=url` only accepts `http(s)` URLs and is admin-only, because Mux will
  fetch the URL server-side.
