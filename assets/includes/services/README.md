# Video pipeline: Vidara storage + Mux processing (no webhooks)

Only three credentials are needed: `VIDARA_API_KEY`, `MUX_TOKEN_ID`, and
`MUX_SECRET_KEY`. They are read server-side with `getenv()`; in Base44 the
web service must load `/run/base44/app.env`. No webhook, webhook secret,
public callback URL, or production deployment is required to process videos.
An old `MUX_WEBHOOK_SECRET` value can be removed from the secret manager;
it is no longer read. `mux-webhook.php` returns 410 and never processes events.

## Upload from PlayTube

For administrators with all three credentials, the normal `/upload-video`
screen uses Vidara + Mux instead of local FFmpeg. Other users and installations
without these credentials retain their existing upload behavior.

1. The existing uploader stages the video, retaining its upload limits and
   supported file formats.
2. The browser POSTs `action=process` and the owned `uploaded_id` to
   `/video-pipeline.php`, with PlayTube's session hash (CSRF protection).
3. The server stores the raw video on Vidara, then uploads a copy directly to
   Mux. Vidara watch-page URLs are not suitable for Mux ingestion.
4. The browser checks `action=status&id=<id>` every five seconds until Mux
   returns a ready asset and playback ID, then stops. There are no webhook
   callbacks or periodic background requests for completed videos.
5. The existing metadata/thumbnail/privacy form is enabled. Publishing checks
   the stored processing result and saves the Mux HLS playback URL in PlayTube's
   normal videos table. The existing watch player already supports HLS.

Keep the upload page open while processing. A failed status request or a
15-minute wait exposes **Check processing again**, which polls the same
entry without creating another upload or encoding job. The page intentionally
never enables Publish for failed or unfinished cloud processing.

## Backend

- `class-vidara-client.php`: storage upload/info/delete operations.
- `class-mux-client.php`: upload/asset API and public playback/poster URLs.
- `class-video-pipeline.php`: durable `video_pipeline` state, refreshed by
  outbound API reads. Uses the app DB wrapper's explicit array result mode.
- `cloud-upload.php`: shared feature detection and publishing checks.
- `video-pipeline.php`: administrator-only JSON operations. Creation requires
  POST, a valid session token, enabled uploads and an unsuspended account.
  `process` accepts only an existing video upload owned by the current user,
  never an arbitrary server file path.

`action=upload` (multipart raw file), `action=direct` (Mux-only direct upload),
and `action=url` (Mux-only public media URL) remain available to authenticated
administrators with a session token. They do not bypass the publish form.

## Checks

Run `php tests/video-pipeline.php` from the source root inside the PHP service.
It uses in-memory persistence and fake providers, never real storage or
encoding credits. Live upload/transcoding remains a separate test and may
incur provider charges. Provider authentication can be checked with read-only
API requests without creating video assets.
