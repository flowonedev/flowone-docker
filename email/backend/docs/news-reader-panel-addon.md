# News Reader addon — Panel registration

The slug **`news_reader`** is registered in the Panel addon catalog (sibling
repo `panel/`) inside:

`panel/api/src/Controllers/AddonController.php` → `ensureTable()` `$defaults` array.

Entry:

```php
[
    'slug' => 'news_reader',
    'name' => 'News Reader',
    'description' => 'Flipboard-style RSS reader with a collapsible bottom ticker (right-to-left marquee), curated HU/EN/US feed catalog, custom feed URLs, in-app fullscreen reader, sandboxed iframe, and per-user unread tracking.',
    'icon'        => 'newspaper',
    'default_enabled' => false,
],
```

## Deployment

1. Upload the modified `AddonController.php` to the Panel staging directory on
   the server (path used by `panel/copy-panel.sh`):

   `/home/panel.devcon1.hu/public_html/api/src/Controllers/AddonController.php`

2. Run the panel deploy script as root, which copies staging → `/var/www/vps-admin/`,
   clears Redis cache and restarts services:

   ```bash
   bash /home/panel.devcon1.hu/public_html/copy-panel.sh
   ```

3. Hit any admin endpoint that calls `ensureTable()` once (e.g. open the
   "Addons" page in the Panel dashboard). The `news_reader` row is INSERTed
   automatically because the seed loop only inserts slugs that don't exist yet.

   Or seed it manually:

   ```bash
   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' vps_admin <<'SQL'
   INSERT IGNORE INTO panel_addons (slug, name, description, icon, enabled)
   VALUES (
     'news_reader',
     'News Reader',
     'Flipboard-style RSS reader with bottom ticker, curated HU/EN/US feeds, custom URLs, in-app fullscreen reader, sandboxed iframe, per-user unread tracking.',
     'newspaper',
     0
   );
   SQL
   ```

4. Toggle it ON in the Panel UI for the desired global / group / user scope.
   The Panel will fire-and-forget POST `/addons/invalidate` to the Email App,
   which clears its Redis `addon_status` cache. After that, this Email App's
   backend `AddonService::isNewsReaderEnabled()` returns `true`, the `/news/*`
   routes register, and the SPA's `useAddons().newsReaderEnabled` becomes `true`.

## Frontend (Email App)

No additional Panel-side frontend work needed. The Panel dashboard's addon
list is rendered dynamically from `GET /addons` (which reads `panel_addons`),
so the new entry shows up automatically once the row is seeded.

## Per-user / per-group overrides

Use the existing Panel "Email Addons" UI (`emailAddons_assignments` table) to
assign `news_reader` to specific users or groups. The same priority rule
applies: user override > group override (most permissive) > global toggle.
