import { Container, Graphics, Text, TextStyle, Sprite, Texture } from 'pixi.js'
import { parseColor, applyTransform, drawRoundedRect } from '../../utils/styleToPixi.js'

const _textRes = Math.max(window.devicePixelRatio || 1, 3)
function crispText(opts) { return new Text({ ...opts, resolution: _textRes }) }

/**
 * Renders "card-like" items: link, todo_list, file, folder, board_link,
 * table, color_swatch, calendar_event, video, youtube, audio.
 * Uses simple PixiJS primitives for performance.
 */
export function createCard(item, textureCache) {
  const container = new Container()
  container.label = `card-${item.type}-${item.id}`
  drawCard(container, item, textureCache)
  applyTransform(container, item)
  return container
}

export function updateCard(container, item, textureCache) {
  container.removeChildren()
  container.mask = null
  drawCard(container, item, textureCache)
  applyTransform(container, item)
}

function drawCard(container, item, textureCache) {
  const renderer = cardRenderers[item.type]
  if (renderer) {
    renderer(container, item, textureCache)
  } else {
    drawGenericCard(container, item)
  }
}

const cardRenderers = {
  link: drawLinkCard,
  todo_list: drawTodoCard,
  file: drawFileCard,
  folder: drawFolderCard,
  board_link: drawBoardLinkCard,
  table: drawTableCard,
  color_swatch: drawColorSwatch,
  calendar_event: drawCalendarCard,
  video: drawVideoCard,
  youtube: drawYoutubeCard,
  audio: drawAudioCard,
}

function drawGenericCard(container, item) {
  const w = item.width || 200
  const h = item.height || 120
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xffffff })
  bg.stroke({ color: 0xe0e0e0, width: 1 })
  container.addChild(bg)

  if (item.title) {
    const text = crispText({ text: item.title, style: cardTitleStyle(w) })
    text.position.set(12, 12)
    container.addChild(text)
  }
}

// Layout constants shared with the pointer hit-testing below
const LINK_URL_ROW = { x: 36, y: 30, height: 18 }

function drawLinkCard(container, item) {
  const w = item.width || 240
  const h = item.height || 80
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xf8f9fa })
  bg.stroke({ color: 0xe2e8f0, width: 1 })
  container.addChild(bg)

  const icon = crispText({ text: 'link', style: iconStyle() })
  icon.position.set(12, h / 2 - 10)
  container.addChild(icon)

  const title = item.title || item.url || 'Link'
  const text = crispText({ text: title, style: cardTitleStyle(w - 48) })
  text.position.set(36, 12)
  container.addChild(text)

  // URL line (clickable via hitTestCardAction — DOM parity with the <a> tag)
  if (item.url && item.title) {
    const url = crispText({ text: item.url, style: linkUrlStyle(w - 48) })
    url.position.set(LINK_URL_ROW.x, LINK_URL_ROW.y + 2)
    container.addChild(url)
  }
}

// Layout constants shared with the pointer hit-testing below
const TODO_ROW = { startY: 32, rowH: 24, checkX: 12, checkSize: 16 }
const TODO_MAX_VISIBLE = 10

function todosOf(item) {
  // DOM renderer reads item.todos; legacy boards stored them in style_data
  return item.todos || item.style_data?.todos || []
}

function isTodoDone(todo) {
  return !!(todo.completed ?? todo.done)
}

function drawTodoCard(container, item) {
  const todos = todosOf(item)
  const w = item.width || 220
  const h = item.height || Math.max(80, TODO_ROW.startY + todos.length * 28)
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xffffff })
  bg.stroke({ color: 0xe0e0e0, width: 1 })
  container.addChild(bg)

  if (item.title) {
    const text = crispText({ text: item.title, style: cardTitleStyle(w - 24) })
    text.position.set(12, 8)
    container.addChild(text)
  }

  todos.slice(0, TODO_MAX_VISIBLE).forEach((todo, i) => {
    const y = TODO_ROW.startY + i * TODO_ROW.rowH
    const done = isTodoDone(todo)
    const check = new Graphics()
    check.roundRect(TODO_ROW.checkX, y, TODO_ROW.checkSize, TODO_ROW.checkSize, 3)
    check.fill({ color: done ? 0x4CAF50 : 0xffffff })
    check.stroke({ color: done ? 0x4CAF50 : 0xcccccc, width: 1 })
    container.addChild(check)

    if (done) {
      const mark = new Graphics()
      mark.moveTo(TODO_ROW.checkX + 4, y + 8)
      mark.lineTo(TODO_ROW.checkX + 7, y + 11)
      mark.lineTo(TODO_ROW.checkX + 12, y + 5)
      mark.stroke({ color: 0xffffff, width: 2, cap: 'round', join: 'round' })
      container.addChild(mark)
    }

    const text = crispText({
      text: todo.text || '',
      style: done ? todoDoneTextStyle(w - 48) : smallTextStyle(w - 48),
    })
    text.position.set(34, y + 1)
    container.addChild(text)

    if (done) {
      const strike = new Graphics()
      const tw = Math.min(text.width, w - 48)
      strike.moveTo(34, y + 8).lineTo(34 + tw, y + 8)
      strike.stroke({ color: 0x999999, width: 1 })
      container.addChild(strike)
    }
  })
}

/**
 * Hit-test interactive regions of card items in item-local coordinates.
 * Returns { action: 'toggle-todo', todo } | { action: 'open-link', url } | null.
 */
export function hitTestCardAction(item, localX, localY) {
  if (item.type === 'todo_list') {
    const todos = todosOf(item)
    if (!todos.length) return null
    // Generous hit area around each checkbox
    if (localX < TODO_ROW.checkX - 4 || localX > TODO_ROW.checkX + TODO_ROW.checkSize + 4) return null
    const idx = Math.floor((localY - TODO_ROW.startY) / TODO_ROW.rowH)
    if (idx < 0 || idx >= Math.min(todos.length, TODO_MAX_VISIBLE)) return null
    const rowY = TODO_ROW.startY + idx * TODO_ROW.rowH
    if (localY < rowY - 2 || localY > rowY + TODO_ROW.checkSize + 4) return null
    return { action: 'toggle-todo', todo: todos[idx] }
  }
  if (item.type === 'link' && item.url) {
    const w = item.width || 240
    const rowY = item.title ? LINK_URL_ROW.y : 12
    if (localX >= LINK_URL_ROW.x && localX <= w - 12
      && localY >= rowY && localY <= rowY + LINK_URL_ROW.height) {
      return { action: 'open-link', url: item.url }
    }
  }
  return null
}

function drawFileCard(container, item, textureCache) {
  const w = item.width || 160
  const h = item.height || 120
  const sd = item.style_data || {}
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xf5f5f5 })
  bg.stroke({ color: 0xe0e0e0, width: 1 })
  container.addChild(bg)

  if (sd.thumbnail_url && textureCache) {
    const tex = textureCache.loadSync(sd.thumbnail_url)
    if (tex) {
      const sprite = new Sprite(tex)
      sprite.width = w
      sprite.height = h - 28
      container.addChild(sprite)
    }
  } else {
    const icon = crispText({ text: 'description', style: iconStyle(24) })
    icon.position.set(w / 2 - 12, h / 2 - 20)
    container.addChild(icon)
  }

  const title = item.title || sd.file_name || 'File'
  const text = crispText({ text: title, style: smallTextStyle(w - 16) })
  text.anchor.set(0.5, 0)
  text.position.set(w / 2, h - 22)
  container.addChild(text)
}

function drawFolderCard(container, item) {
  const w = item.width || 160
  const h = item.height || 120
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xFFF8E1 })
  bg.stroke({ color: 0xFFE082, width: 1 })
  container.addChild(bg)

  const icon = crispText({ text: 'folder', style: iconStyle(28) })
  icon.position.set(w / 2 - 14, h / 2 - 22)
  container.addChild(icon)

  const title = item.title || 'Folder'
  const text = crispText({ text: title, style: smallTextStyle(w - 16) })
  text.anchor.set(0.5, 0)
  text.position.set(w / 2, h / 2 + 12)
  container.addChild(text)
}

function drawBoardLinkCard(container, item) {
  const w = item.width || 200
  const h = item.height || 120
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 10)
  bg.fill({ color: 0xEDE7F6 })
  bg.stroke({ color: 0xB39DDB, width: 1 })
  container.addChild(bg)

  const icon = crispText({ text: 'dashboard', style: iconStyle() })
  icon.position.set(12, 12)
  container.addChild(icon)

  const title = item.title || 'Board'
  const text = crispText({ text: title, style: cardTitleStyle(w - 48) })
  text.position.set(40, 14)
  container.addChild(text)
}

const TABLE_TITLE_H = 28
const TABLE_HEADER_H = 24
const TABLE_ROW_H = 22
const TABLE_MAX_ROWS = 30
const TABLE_MAX_COLS = 8

function parseTableContent(item) {
  try {
    const data = typeof item.content === 'string' ? JSON.parse(item.content) : item.content
    return {
      columns: data?.columns || ['Column 1', 'Column 2'],
      rows: data?.rows || [['', '']],
    }
  } catch {
    return { columns: ['Column 1', 'Column 2'], rows: [['', '']] }
  }
}

function drawTableCard(container, item) {
  const w = item.width || 300
  const h = item.height || 200
  const { columns, rows } = parseTableContent(item)
  const cols = Math.min(columns.length || 1, TABLE_MAX_COLS)
  const cellW = w / cols

  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 12)
  bg.fill({ color: 0xffffff })
  bg.stroke({ color: 0xe0e0e0, width: 1 })
  container.addChild(bg)

  // Clip cell content to the card bounds
  const mask = new Graphics()
  drawRoundedRect(mask, 0, 0, w, h, 12)
  mask.fill({ color: 0xffffff })
  container.addChild(mask)
  const content = new Container()
  content.mask = mask
  container.addChild(content)

  // Title bar (DOM parity: icon + title strip on top)
  const titleBg = new Graphics()
  titleBg.rect(0, 0, w, TABLE_TITLE_H).fill({ color: 0xf8fafc })
  titleBg.moveTo(0, TABLE_TITLE_H).lineTo(w, TABLE_TITLE_H).stroke({ color: 0xe2e8f0, width: 1 })
  content.addChild(titleBg)
  const icon = crispText({ text: 'table_chart', style: iconStyle(14) })
  icon.position.set(10, 7)
  content.addChild(icon)
  const titleText = crispText({ text: item.title || 'Table', style: cardTitleStyle(w - 40) })
  titleText.style.fontSize = 11
  titleText.position.set(30, 8)
  content.addChild(titleText)

  // Header row
  const headerBg = new Graphics()
  headerBg.rect(0, TABLE_TITLE_H, w, TABLE_HEADER_H).fill({ color: 0xf1f5f9 })
  content.addChild(headerBg)
  for (let c = 0; c < cols; c++) {
    const head = crispText({ text: String(columns[c] ?? ''), style: tableHeaderStyle(cellW - 12) })
    head.position.set(c * cellW + 6, TABLE_TITLE_H + 6)
    content.addChild(head)
  }

  // Body cells
  const bodyTop = TABLE_TITLE_H + TABLE_HEADER_H
  const visibleRows = Math.min(
    rows.length,
    TABLE_MAX_ROWS,
    Math.max(0, Math.ceil((h - bodyTop) / TABLE_ROW_H)),
  )
  for (let r = 0; r < visibleRows; r++) {
    const row = rows[r] || []
    const y = bodyTop + r * TABLE_ROW_H
    for (let c = 0; c < cols; c++) {
      const val = String(row[c] ?? '')
      if (!val) continue
      const cell = crispText({ text: val, style: tableCellStyle(cellW - 12) })
      cell.position.set(c * cellW + 6, y + 5)
      content.addChild(cell)
    }
  }

  // Grid lines
  const grid = new Graphics()
  for (let r = 0; r <= visibleRows; r++) {
    const y = bodyTop + r * TABLE_ROW_H
    if (y > h) break
    grid.moveTo(0, y).lineTo(w, y)
  }
  for (let c = 1; c < cols; c++) grid.moveTo(c * cellW, TABLE_TITLE_H).lineTo(c * cellW, h)
  grid.stroke({ color: 0xe5e7eb, width: 1 })
  content.addChild(grid)
}

function drawColorSwatch(container, item) {
  const sd = item.style_data || {}
  const w = item.width || 80
  const h = item.height || 80
  const swatchColor = item.color || sd.swatch_color || '#ccc'
  const { color } = parseColor(swatchColor)
  const g = new Graphics()
  drawRoundedRect(g, 0, 0, w, h, 8)
  g.fill({ color })
  g.stroke({ color: 0xcccccc, width: 1 })
  container.addChild(g)

  if (item.title) {
    const text = crispText({ text: item.title, style: smallTextStyle(w - 8) })
    text.anchor.set(0.5, 0)
    text.position.set(w / 2, h + 4)
    container.addChild(text)
  }
}

function drawCalendarCard(container, item) {
  const w = item.width || 200
  const h = item.height || 80
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xE3F2FD })
  bg.stroke({ color: 0x90CAF9, width: 1 })
  container.addChild(bg)

  const icon = crispText({ text: 'event', style: iconStyle() })
  icon.position.set(12, h / 2 - 10)
  container.addChild(icon)

  const title = item.title || 'Event'
  const text = crispText({ text: title, style: cardTitleStyle(w - 48) })
  text.position.set(40, 12)
  container.addChild(text)
}

function drawVideoCard(container, item, textureCache) {
  const w = item.width || 320
  const h = item.height || 180
  drawMediaCard(container, item, w, h, 'videocam', 0x212121, textureCache)
}

function drawYoutubeCard(container, item, textureCache) {
  const w = item.width || 320
  const h = item.height || 180
  drawMediaCard(container, item, w, h, 'play_circle', 0xFF0000, textureCache)
}

function drawAudioCard(container, item) {
  const w = item.width || 280
  const h = item.height || 80
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0xF3E5F5 })
  bg.stroke({ color: 0xCE93D8, width: 1 })
  container.addChild(bg)

  const icon = crispText({ text: 'audiotrack', style: iconStyle() })
  icon.position.set(12, h / 2 - 10)
  container.addChild(icon)

  const title = item.title || 'Audio'
  const text = crispText({ text: title, style: cardTitleStyle(w - 48) })
  text.position.set(40, h / 2 - 8)
  container.addChild(text)
}

function drawMediaCard(container, item, w, h, iconName, accentColor, textureCache) {
  const bg = new Graphics()
  drawRoundedRect(bg, 0, 0, w, h, 8)
  bg.fill({ color: 0x1a1a1a })
  container.addChild(bg)

  if (item.thumbnail_url && textureCache) {
    const tex = textureCache.loadSync(item.thumbnail_url)
    if (tex) {
      const sprite = new Sprite(tex)
      sprite.width = w
      sprite.height = h
      sprite.alpha = 0.7
      container.addChild(sprite)
    }
  }

  const icon = crispText({ text: iconName, style: iconStyle(36) })
  icon.anchor.set(0.5)
  icon.position.set(w / 2, h / 2)
  icon.style.fill = accentColor
  container.addChild(icon)
}

function cardTitleStyle(maxWidth) {
  return new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 13,
    fontWeight: '600',
    fill: 0x333333,
    wordWrap: true,
    wordWrapWidth: maxWidth,
  })
}

function smallTextStyle(maxWidth) {
  return new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 11,
    fill: 0x666666,
    wordWrap: true,
    wordWrapWidth: maxWidth,
  })
}

function todoDoneTextStyle(maxWidth) {
  return new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 11,
    fill: 0xaaaaaa,
    wordWrap: true,
    wordWrapWidth: maxWidth,
  })
}

function linkUrlStyle(maxWidth) {
  return new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 10,
    fill: 0x6366f1,
    wordWrap: false,
    wordWrapWidth: maxWidth,
  })
}

function tableHeaderStyle(maxWidth) {
  return new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 10,
    fontWeight: '600',
    fill: 0x475569,
    wordWrap: false,
    wordWrapWidth: maxWidth,
  })
}

function tableCellStyle(maxWidth) {
  return new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 10,
    fill: 0x555555,
    wordWrap: false,
    wordWrapWidth: maxWidth,
  })
}

function iconStyle(size = 20) {
  return new TextStyle({
    fontFamily: 'Material Symbols Rounded',
    fontSize: size,
    fill: 0x666666,
  })
}
