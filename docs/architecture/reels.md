# Store reels

Marketing owns store-uploaded reels. Marketing → Reels requires `reels.view`; uploads, edits, status changes and deletion require `reels.manage`. Viewing identities/history additionally require `reels.analytics`. Permission sync registers capabilities without changing existing grants. Every mutation checks persisted capabilities and an existing reel's version. There is no customer upload endpoint or upload action on the storefront block.

Each reel has an active/disabled status, one private MP4 video, a required private cover image, locale-linked titles and up to 24 ordered product links. The default-language title is required; other active-language titles are optional, with the existing public fallback. Inactive-language input is rejected and saved inactive translations are preserved. Product links reference real, nondeleted products; display reuses `PublicCatalog::listed` and ordinary live product cards, including prices, taxes, ratings and customer visibility. A reel may have no products.

Videos reuse content-sniffed private MP4 storage, with a reel-specific 64 MiB limit (banners retain 16 MiB). Covers accept JPEG/PNG/WebP up to 5 MiB with shared image validation. H.264/AAC are recommended for browser compatibility. No transcoding, automatic cover extraction or third-party player is introduced. Upload failures roll back records and remove newly staged files. Replaced/deleted media follow the existing banner retention policy; bytes are not purged inline.

## Layouts and playback

Website → Layouts registers Reels through the existing container → 16-column grid → block organization and Create new / Use existing dialog. Manual filling uses the searchable reel picker and preserves order. Automatic sources are **Newest reels** (created within the period, descending creation date/ID) and **Most viewed reels** (views recorded within the period, descending count then creation date/ID, including older reels). General → Catalog → Reels period defaults to 90 days and accepts 1–3650. Manual picks are not age-limited. Single reel fills its grid using one item; the carousel shows configurable thumbnails. Missing, disabled, missing-default-title or missing-media reels are omitted. Only structure is cached; eligibility, counts and product content remain live.

Opening a thumbnail loads video and current products on demand. Desktop shows video beside products; mobile uses a horizontal product strip. Native controls provide playback, sound and seeking. Previous/next buttons, arrow up/down outside native video controls, Escape and focus restoration are supported through the shared native dialog. Closing/changing reels unmounts the player; backgrounding pauses it. Likes, comments, followers and public uploads are outside this slice.

## Views and privacy

The browser sends a view signal after three accumulated seconds of visible playback, excluding paused/seeking time. An encrypted token binds it to the reel and current viewer; the server enforces a minimum three-second delay and a one-hour token lifetime. User ID comes only from the authenticated session. Guest identity uses an HMAC of the session ID; registered identity uses an HMAC of user ID. Raw session IDs, IP addresses, user agents and device fingerprints are not stored.

PostgreSQL uniquely allows one event per reel/viewer/UTC half-hour, including concurrent requests/retries. Later buckets can record repeat views; signed-in viewers deduplicate across devices. Clearing guest cookies creates a new anonymous identity. These are browser playback signals, not verified human attention or fraud-proof measurement. Totals derive from event rows, with no independently mutable counter.

Private admin history shows total views, distinct currently registered viewers, anonymous/deleted-account views and paginated timestamps with user name/email. Staff can filter registered users or one viewer. Public payloads contain counts only. History stays out of the general audit ledger; metadata/configuration changes are audited. User deletion nulls identity while keeping counts. Deleting a reel cascades translations, product links, block selections and history and invalidates layout caches. Disabling retains history and immediately denies public detail, cover, video and view requests. Closed-store checks apply to every public reel route. Video responses support byte ranges and recheck ownership/status.

History has no automatic expiry because the requested feature includes historical reporting. Review retention/aggregation against actual traffic before changing this contract at larger event volumes. Time/reel and reel/time indexes support ranking/history.

## Deployment and verification

Apply `2026_11_20_000100_create_reels` with ordinary `php artisan migrate`, run `cms:permissions-sync`, and rebuild with `pnpm build`. Restart the local app container to load raised PHP limits (64 MiB per file, 72 MiB aggregate); production PHP/proxy limits must also permit these sizes. Existing permissions, banners, layouts and products are preserved. Migration rollback removes reels/history and is a destructive operator operation.

`ReelsTest` covers capabilities, uploads/rollback, conflicts, product filtering, owned/range media, layout ordering, automatic periods, token binding/deduplication and history privacy. React tests cover lazy loading, product links, navigation, single-item behavior, playback qualification and recoverable loading errors.
