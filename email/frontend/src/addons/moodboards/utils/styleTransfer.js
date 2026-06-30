/**
 * Style transfer utility -- extracts visual properties from a source item
 * and produces a patch object that can be applied to a target item via updateItem.
 *
 * Positional properties (pos_x, pos_y, z_index, rotation, locked, parent_id,
 * slide_order, width, height) are deliberately excluded.
 * Content (title, content, image_url, file_id, etc.) is excluded too.
 * Only visual / appearance keys are transferred.
 */

const EXCLUDED_KEYS = new Set([
  'id', 'board_id', 'type', 'pos_x', 'pos_y', 'z_index', 'rotation',
  'locked', 'parent_id', 'slide_order', 'width', 'height',
  'content', 'title', 'image_url', 'file_id', 'url', 'link_url',
  'created_at', 'updated_at', 'user_id',
  'component_id', 'component_instance_id',
  'presenter_notes', 'slide_order',
])

function parseSd(item) {
  const sd = item?.style_data
  if (!sd) return {}
  if (typeof sd === 'string') { try { return JSON.parse(sd) } catch { return {} } }
  return sd
}

export function extractStyle(item) {
  if (!item) return null
  const sd = parseSd(item)

  const patch = {}

  if (item.color != null) patch.color = item.color
  if (Object.keys(sd).length) patch.style_data = { ...sd }

  return Object.keys(patch).length ? patch : null
}

export function applyStyle(targetItem, stylePatch, updateFn) {
  if (!targetItem || !stylePatch) return

  const update = {}

  if (stylePatch.color != null) {
    update.color = stylePatch.color
  }

  if (stylePatch.style_data) {
    const currentSd = parseSd(targetItem)
    update.style_data = { ...currentSd, ...stylePatch.style_data }
  }

  if (Object.keys(update).length) {
    updateFn(targetItem.id, update)
  }
}
