export const ITEM_DEFAULTS = {
  note: { width: 240, color: '#fef3c7', title: '', content: '' },
  text: { width: 300, height: 120, title: '', content: '', style_data: { font_family: 'Inter', font_size: 16 } },
  shape: { width: 200, height: 200, style_data: { shape_fill: '#6366f1', shape_border_color: '#4f46e5', shape_border_width: 2, shape_opacity: 100, radius_all: 8, radius_tl: 8, radius_tr: 8, radius_br: 8, radius_bl: 8 } },
  todo_list: { width: 260, title: 'Checklist', todos: [] },
  image: { width: 300, title: '', image_url: '' },
  image_set: { width: 320, height: 240, title: 'Image Set' },
  column: { width: 360, height: 400, title: 'New Column' },
  slide: { width: 480, height: 270, title: 'Slide' },
  frame: { width: 1920, height: 1080, title: 'Desktop', style_data: { fill_color: '#ffffff', frame_device: 'Desktop' } },
  table: { width: 400, height: 200, title: 'Table' },
  color_swatch: { width: 100, height: 100, color: '#6366f1' },
  calendar_event: { width: 260, title: 'Event', color: '#6366f1' },
  link: { width: 280, title: '', url: '' },
  file: { width: 220, height: 180, title: '' },
  folder: { width: 240, height: 200, title: 'Folder' },
  video: { width: 480, height: 270, title: '', url: '' },
  youtube: { width: 480, height: 270, title: '', url: '' },
  audio: { width: 280, height: 100, title: '', url: '', style_data: { audio_volume: 80, audio_loop: false, audio_autoplay: false, audio_accent: '#6366f1', audio_bg: '#1e1b2e', audio_text: '#e2e8f0' } },
  line: { width: 200, height: 20, style_data: { line_x1: 10, line_y1: 10, line_x2: 190, line_y2: 10, line_color: '#1e293b', line_width: 2, line_dash: 'solid', line_dash_gap: 0, line_arrow_start: false, line_arrow_end: false } },
}

export function createItemData(type, center, store, extraStyleData = null) {
  const defaults = ITEM_DEFAULTS[type] || {}
  const w = defaults.width || 100
  const h = defaults.height || 100
  const data = {
    ...defaults,
    type,
    pos_x: Math.round(center.x - w / 2),
    pos_y: Math.round(center.y - h / 2),
  }
  if (extraStyleData) {
    data.style_data = { ...(data.style_data || {}), ...extraStyleData }
  }
  if (type === 'slide') {
    const existingSlides = (store.currentBoard?.items || []).filter(i => i.type === 'slide')
    data.slide_order = existingSlides.length
  }
  return data
}
