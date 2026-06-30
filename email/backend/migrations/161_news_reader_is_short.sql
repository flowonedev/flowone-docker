-- News Reader: filter out YouTube Shorts.
--
-- The YouTube channel RSS feed (videos.xml?channel_id=...) mixes regular
-- uploads and Shorts as identical <entry> elements — there's no flag in
-- the feed itself to tell them apart. We detect Shorts at ingest time by
-- HEAD-checking https://www.youtube.com/shorts/<videoId>:
--   * HTTP 200 -> page rendered as a Short -> skip (don't insert)
--   * HTTP 30x redirect to /watch?v=...  -> full video -> keep
--
-- This column is the cached result of that check for items that survive
-- ingest (always 0 for them), AND a sentinel for legacy rows from before
-- this column existed that may still contain Shorts. The list queries in
-- NewsReaderService::list*() filter on `is_short = 0` so any 1-marked
-- rows are hidden until the --purge-shorts CLI removes them.

ALTER TABLE news_reader_items
    ADD COLUMN is_short TINYINT(1) NOT NULL DEFAULT 0 AFTER is_video,
    ADD KEY idx_is_short (is_short, published_at);
