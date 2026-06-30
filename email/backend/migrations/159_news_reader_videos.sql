-- News Reader: support YouTube channels as a "video" feed kind alongside
-- regular news RSS. YouTube exposes per-channel RSS feeds at
-- https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxx so we can
-- reuse the existing fetch/parse/ingest pipeline; we only need:
--   * a kind discriminator on the feed (so the UI can badge it as a video)
--   * per-item video metadata so the reader can embed the YouTube player
--     instead of trying to extract an article body that doesn't exist
--
-- feed_kind:
--   'news'  - regular RSS/Atom article feed (default)
--   'video' - YouTube channel/playlist feed
--
-- Per-item:
--   is_video             1 when the item is playable video content
--   video_id             YouTube videoId (e.g. dQw4w9WgXcQ) — used to build
--                        the embed URL https://www.youtube-nocookie.com/embed/<id>
--   video_thumbnail_url  preferred thumbnail (yt: media:thumbnail), used as
--                        the tile cover when image_url is missing
--   video_duration_s     duration in seconds when known (currently NULL —
--                        the YouTube RSS doesn't ship duration; reserved for
--                        future oEmbed lookups)

ALTER TABLE news_reader_feeds
    ADD COLUMN feed_kind VARCHAR(16) NOT NULL DEFAULT 'news' AFTER feed_type,
    ADD KEY idx_feed_kind (feed_kind);

ALTER TABLE news_reader_items
    ADD COLUMN is_video TINYINT(1) NOT NULL DEFAULT 0 AFTER image_url,
    ADD COLUMN video_id VARCHAR(32) NULL AFTER is_video,
    ADD COLUMN video_thumbnail_url VARCHAR(2048) NULL AFTER video_id,
    ADD COLUMN video_duration_s INT NULL AFTER video_thumbnail_url,
    ADD KEY idx_is_video (is_video, published_at);
