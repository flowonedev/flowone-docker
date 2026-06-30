/**
 * FlowOne in-editor presence layer (free-moving cursors, participant avatars,
 * and the "follow" toggle) for OnlyOffice documents.
 *
 * Disabled: we rely solely on OnlyOffice's native co-editing (selections, text
 * cursor, colored name flags), which is reliable across Docs, Sheets and Slides.
 * The presence code is intentionally kept in place so this can be re-enabled by
 * flipping the flag below.
 *
 * To fully re-enable, also flip `CURSORS_ENABLED` to true in
 * email/office/plugins/flowone-presence/plugin.js (and redeploy the plugin).
 */
export const OFFICE_PRESENCE_ENABLED = false
