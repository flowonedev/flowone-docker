<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= htmlspecialchars($boardName, ENT_QUOTES, 'UTF-8') ?> — Presentation</title>
<link rel="stylesheet" href="/fonts/material-symbols-rounded/font.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;overflow:hidden;font-family:'Inter',system-ui,-apple-system,sans-serif;background:#111;color:#fff;-webkit-font-smoothing:antialiased}

/* Landing */
#landing{position:fixed;inset:0;z-index:9000;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#111;transition:opacity .4s}
#landing.hidden{opacity:0;pointer-events:none}
#landing h1{font-size:clamp(1.5rem,4vw,2.5rem);font-weight:700;margin-bottom:.5rem;text-align:center;padding:0 1rem}
#landing .subtitle{font-size:.875rem;color:#999;margin-bottom:2rem}
#landing button{padding:.875rem 2.5rem;border:none;border-radius:9999px;background:#6366f1;color:#fff;font-size:1rem;font-weight:600;cursor:pointer;transition:background .2s,transform .1s;display:flex;align-items:center;gap:.5rem}
#landing button:hover{background:#4f46e5;transform:scale(1.03)}
#landing button:active{transform:scale(.98)}

/* Canvas */
#canvas-container{position:fixed;inset:0;overflow:hidden}
#canvas-layer{position:absolute;top:0;left:0;transform-origin:0 0;will-change:transform}

/* Background effects */
#bg-effects{position:fixed;inset:0;pointer-events:none;z-index:1}

/* Items */
.mood-item{position:absolute;overflow:visible}
.mood-item img{display:block;max-width:100%;height:100%;width:100%}
.mood-item-image img{object-fit:cover;border-radius:inherit}
.mood-item-note{border-radius:.75rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.mood-item-note .note-title{font-weight:600;margin-bottom:.25rem}
.mood-item-note .note-content{font-size:.875rem;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.mood-item-text{overflow:visible;white-space:pre-wrap;word-break:break-word;line-height:1.5}
.mood-item-text *{max-width:100%}
.mood-item-shape{overflow:hidden}
.mood-item-color_swatch{border-radius:.75rem;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.mood-item-color_swatch .swatch-label{margin-top:auto;padding:.375rem .5rem;text-align:center}
.mood-item-color_swatch .swatch-hex{font-family:monospace;font-size:.75rem;padding:.125rem .5rem;border-radius:.25rem;background:rgba(0,0,0,.2);color:rgba(255,255,255,.9)}
.mood-item-color_swatch .swatch-detail{font-family:monospace;font-size:.5625rem;color:rgba(255,255,255,.6)}
.mood-item-todo_list{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;padding:.75rem;overflow:hidden}
.mood-item-todo_list .todo-title{font-size:.875rem;font-weight:600;color:#111827;margin-bottom:.5rem}
.mood-item-todo_list label{display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:#374151;margin-bottom:.375rem}
.mood-item-todo_list .todo-check{width:1.25rem;height:1.25rem;border-radius:.375rem;border:2px solid #d1d5db;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mood-item-todo_list .todo-check.done{background:#6366f1;border-color:#6366f1;color:#fff}
.mood-item-todo_list .todo-text.done{text-decoration:line-through;color:#9ca3af}
.mood-item-link{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;padding:.75rem;overflow:hidden}
.mood-item-link .link-url{font-size:.75rem;color:#6366f1;word-break:break-all}
.mood-item-file{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;overflow:hidden;display:flex;flex-direction:column}
.mood-item-file .file-body{flex:1;display:flex;align-items:center;justify-content:center;background:#f9fafb;min-height:80px}
.mood-item-file .file-footer{padding:.5rem .75rem;font-size:.75rem;color:#374151;border-top:1px solid #e5e7eb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mood-item-image_set{display:grid;gap:2px;overflow:hidden;border-radius:.5rem}
.mood-item-image_set img{width:100%;height:100%;object-fit:cover}
.mood-item-calendar_event{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;padding:.75rem}
.mood-item-calendar_event .event-title{font-size:.875rem;font-weight:600;color:#111827}
.mood-item-calendar_event .event-detail{font-size:.75rem;color:#6b7280;margin-top:.25rem}
.mood-item-video{overflow:hidden;border-radius:.5rem;background:#000}
.mood-item-video video{width:100%;height:100%;object-fit:cover}
.mood-item-youtube{overflow:hidden;border-radius:.5rem;background:#000;display:flex;align-items:center;justify-content:center;position:relative}
.mood-item-youtube .yt-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,.6);color:#fff;font-size:.75rem;gap:.5rem}
.mood-item-youtube .yt-play{width:3rem;height:3rem;background:rgba(255,0,0,.85);border-radius:.75rem;display:flex;align-items:center;justify-content:center}
.mood-item-audio{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;padding:.75rem;display:flex;flex-direction:column;gap:.5rem}
.mood-item-audio .audio-title{font-size:.75rem;font-weight:600;color:#111827}
.mood-item-audio audio{width:100%;height:2rem}
.mood-item-frame{border:1px solid #d1d5db;border-radius:.5rem;overflow:visible;position:relative}
.mood-item-drawing svg{width:100%;height:100%}
.mood-item-table{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;overflow:auto;font-size:.8125rem}
.mood-item-table table{width:100%;border-collapse:collapse}
.mood-item-table th,.mood-item-table td{padding:.375rem .5rem;border:1px solid #e5e7eb;text-align:left;color:#374151}
.mood-item-table th{background:#f3f4f6;font-weight:600;color:#111827}
.mood-item-generic{border-radius:.75rem;background:#fff;border:1px solid #e5e7eb;padding:.75rem;display:flex;align-items:center;justify-content:center;gap:.5rem;color:#6b7280;font-size:.75rem}

/* Connections SVG */
#connections-svg{position:absolute;top:0;left:0;overflow:visible;pointer-events:none;width:10000px;height:10000px}

/* Fade overlay for transitions */
#fade-overlay{position:fixed;inset:0;z-index:8000;pointer-events:none;opacity:0;transition:opacity .2s}
#fade-overlay.active{opacity:1}

/* HUD */
#hud{position:fixed;inset:0;z-index:9500;pointer-events:none;transition:opacity .3s}
#hud.hidden{opacity:0}
#hud-top{position:absolute;top:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;pointer-events:auto;background:linear-gradient(to bottom,rgba(0,0,0,.4),transparent)}
#hud-top .slide-title{font-size:.875rem;font-weight:500;color:rgba(255,255,255,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-right:1rem}
#hud-close{padding:.5rem;border-radius:9999px;border:none;background:transparent;color:rgba(255,255,255,.8);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
#hud-close:hover{background:rgba(255,255,255,.1)}
#hud-bottom{position:absolute;bottom:0;left:0;right:0;pointer-events:auto;background:linear-gradient(to top,rgba(0,0,0,.4),transparent)}
#progress-bar{margin:0 1.5rem;height:4px;background:rgba(255,255,255,.2);border-radius:9999px;overflow:hidden;margin-bottom:.75rem}
#progress-fill{height:100%;background:rgba(255,255,255,.7);border-radius:9999px;transition:width .3s}
#hud-nav{display:flex;align-items:center;gap:.375rem;padding:0 1rem 1rem}
#hud-nav button{padding:.625rem;border-radius:9999px;border:none;background:transparent;color:rgba(255,255,255,.8);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
#hud-nav button:hover{background:rgba(255,255,255,.1)}
#hud-nav button:disabled{opacity:.3;cursor:not-allowed}
#hud-counter{font-size:.75rem;font-family:monospace;color:rgba(255,255,255,.7);min-width:3.5rem;text-align:center;user-select:none}
#hud-spacer{flex:1}

/* BG audio indicator */
#bg-audio-indicator{position:fixed;top:1rem;right:5rem;z-index:9600;font-size:.625rem;color:rgba(255,255,255,.5);background:rgba(0,0,0,.3);padding:.25rem .75rem;border-radius:9999px;pointer-events:none;opacity:0;transition:opacity .3s}
#bg-audio-indicator.visible{opacity:1}

/* Material icons helper */
.msym{font-family:'Material Symbols Rounded';font-weight:normal;font-style:normal;font-size:1.25rem;display:inline-block;line-height:1;text-transform:none;letter-spacing:normal;word-wrap:normal;white-space:nowrap;direction:ltr;-webkit-font-feature-settings:'liga';-webkit-font-smoothing:antialiased}

/* Clip paths for shapes */
.clip-triangle{clip-path:polygon(50% 0%,0% 100%,100% 100%)}
.clip-star{clip-path:polygon(50% 0%,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)}
.clip-pentagon{clip-path:polygon(50% 0%,100% 38%,82% 100%,18% 100%,0% 38%)}
.clip-hexagon{clip-path:polygon(25% 0%,75% 0%,100% 50%,75% 100%,25% 100%,0% 50%)}
.clip-diamond{clip-path:polygon(50% 0%,100% 50%,50% 100%,0% 50%)}
.clip-arrow_right{clip-path:polygon(0% 20%,60% 20%,60% 0%,100% 50%,60% 100%,60% 80%,0% 80%)}
.clip-cross{clip-path:polygon(35% 0%,65% 0%,65% 35%,100% 35%,100% 65%,65% 65%,65% 100%,35% 100%,35% 65%,0% 65%,0% 35%,35% 35%)}

@media (max-width:640px){
  #hud-top{padding:.75rem 1rem}
  #hud-nav{padding:0 .75rem .75rem}
  #landing button{padding:.75rem 2rem;font-size:.875rem}
}
</style>
</head>
<body>

<!-- Landing screen -->
<div id="landing">
  <h1><?= htmlspecialchars($boardName, ENT_QUOTES, 'UTF-8') ?></h1>
  <div class="subtitle"><?= $slideCount ?> slide<?= $slideCount !== 1 ? 's' : '' ?></div>
  <button id="start-btn" onclick="startPresentation()">
    <span class="msym">present_to_all</span>
    Start Presentation
  </button>
</div>

<!-- Fade overlay for transitions -->
<div id="fade-overlay"></div>

<!-- Background effects layer -->
<div id="bg-effects"></div>

<!-- Canvas -->
<div id="canvas-container">
  <div id="canvas-layer"></div>
</div>

<!-- HUD -->
<div id="hud" class="hidden">
  <div id="hud-top">
    <span class="slide-title" id="hud-title"></span>
    <button id="hud-close" onclick="exitPresentation()" title="Exit (Esc)">
      <span class="msym" style="font-size:1.5rem">close</span>
    </button>
  </div>
  <div id="hud-bottom">
    <div id="progress-bar"><div id="progress-fill" style="width:0%"></div></div>
    <div id="hud-nav">
      <button id="btn-prev" onclick="prevSlide()" title="Previous"><span class="msym">arrow_back</span></button>
      <span id="hud-counter">1 / 1</span>
      <button id="btn-next" onclick="nextSlide()" title="Next"><span class="msym">arrow_forward</span></button>
      <div id="hud-spacer"></div>
    </div>
  </div>
</div>

<!-- BG audio indicator -->
<div id="bg-audio-indicator"></div>

<script>
// ============================================================
// BOARD DATA (injected by PHP)
// ============================================================
const BOARD = <?= $boardDataJson ?>;
const ASSETS = <?= $assetMapJson ?>;

// ============================================================
// STATE
// ============================================================
let zoom = 1, panX = 0, panY = 0;
let currentSlideIndex = 0;
let slides = [];
let animRaf = null;
let isPresenting = false;
let hudTimer = null;
let hudHidden = false;
let navigating = false;

// Touch / wheel state
let touchStartX = 0, touchStartY = 0, touchStartTime = 0;
let scrollAccum = 0, scrollCooldown = false;

// Background audio
let bgAudioEl = null;

// ============================================================
// INIT
// ============================================================
function init() {
  const items = BOARD.items || [];

  // Extract slides sorted by slide_order
  slides = items
    .filter(i => i.type === 'slide')
    .sort((a, b) => (a.slide_order || 9999) - (b.slide_order || 9999));

  // Set background
  const container = document.getElementById('canvas-container');
  container.style.backgroundColor = BOARD.background_color || '#f5f5f5';

  if (BOARD.background_image) {
    const bgUrl = resolveAsset(BOARD.background_image);
    if (bgUrl) {
      const size = BOARD.background_image_size || 'cover';
      const bgDiv = document.createElement('div');
      bgDiv.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:0;';
      bgDiv.style.backgroundImage = 'url(' + bgUrl + ')';
      bgDiv.style.backgroundPosition = 'center';
      if (size === 'repeat') {
        bgDiv.style.backgroundSize = 'auto';
        bgDiv.style.backgroundRepeat = 'repeat';
      } else {
        bgDiv.style.backgroundSize = size;
        bgDiv.style.backgroundRepeat = 'no-repeat';
      }
      container.insertBefore(bgDiv, container.firstChild);
    }
  }

  // Background effects (gradient, vignette)
  renderBgEffects();

  // Render all non-slide items on the canvas
  renderItems(items);

  // Render connection lines
  renderConnections();

  // Setup background audio
  setupBgAudio();
}

// ============================================================
// ASSET RESOLVER
// ============================================================
function resolveAsset(url) {
  if (!url) return '';
  if (url.startsWith('data:')) return url;
  if (ASSETS[url]) return ASSETS[url];
  // Try without leading slash
  const noSlash = url.startsWith('/') ? url.substring(1) : url;
  if (ASSETS[noSlash]) return ASSETS[noSlash];
  return url;
}

// ============================================================
// BACKGROUND EFFECTS
// ============================================================
function renderBgEffects() {
  let fx = BOARD.bg_effect;
  if (!fx) return;
  if (typeof fx === 'string') {
    try { fx = JSON.parse(fx); } catch(e) { return; }
  }

  const layers = [];
  if (fx.gradient && fx.gradient.enabled) {
    const angle = fx.gradient.angle || 135;
    const from = fx.gradient.from || '#000000';
    const to = fx.gradient.to || '#ffffff';
    const op = (fx.gradient.opacity || 30) / 100;
    layers.push('linear-gradient(' + angle + 'deg, ' + hexToRgba(from, op) + ', ' + hexToRgba(to, op) + ')');
  }
  if (fx.vignette && fx.vignette.enabled) {
    const intensity = (fx.vignette.intensity || 40) / 100;
    const spread = fx.vignette.spread || 60;
    layers.push('radial-gradient(ellipse at center, transparent ' + spread + '%, rgba(0,0,0,' + intensity + ') 100%)');
  }
  if (layers.length) {
    const el = document.getElementById('bg-effects');
    el.style.background = layers.join(', ');
  }
}

function hexToRgba(hex, alpha) {
  hex = hex.replace('#', '');
  if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
  const r = parseInt(hex.substring(0,2), 16);
  const g = parseInt(hex.substring(2,4), 16);
  const b = parseInt(hex.substring(4,6), 16);
  return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

// ============================================================
// RENDER ITEMS
// ============================================================
function renderItems(items) {
  const canvas = document.getElementById('canvas-layer');
  const nonSlideItems = items
    .filter(i => i.type !== 'slide')
    .sort((a, b) => (a.z_index || 0) - (b.z_index || 0));

  nonSlideItems.forEach(item => {
    // Skip items that are children of frames -- they'll be rendered inside their parent
    if (item.parent_id) {
      const parent = items.find(p => p.id === item.parent_id);
      if (parent && parent.type === 'frame') return;
    }
    const el = createItemElement(item, items);
    if (el) canvas.appendChild(el);
  });
}

function createItemElement(item, allItems) {
  const el = document.createElement('div');
  el.className = 'mood-item mood-item-' + item.type;
  el.dataset.id = item.id;

  const x = item.pos_x || 0;
  const y = item.pos_y || 0;
  const w = item.width;
  const h = item.height;
  const rot = item.rotation ? ' rotate(' + item.rotation + 'deg)' : '';
  const sd = item.style_data || {};
  const flipX = sd.flip_x ? ' scaleX(-1)' : '';
  const flipY = sd.flip_y ? ' scaleY(-1)' : '';

  el.style.transform = 'translate(' + x + 'px, ' + y + 'px)' + rot + flipX + flipY;
  el.style.zIndex = item.z_index || 0;
  if (w) el.style.width = w + 'px';
  if (h) el.style.height = h + 'px';

  // Opacity
  if (sd.opacity != null && sd.opacity !== 1) el.style.opacity = sd.opacity;
  // Blend mode
  if (sd.blend_mode && sd.blend_mode !== 'normal') el.style.mixBlendMode = sd.blend_mode;
  // Hidden items
  if (sd._hidden) { el.style.opacity = '0'; el.style.pointerEvents = 'none'; }

  // Render inner content by type
  switch (item.type) {
    case 'image': renderImage(el, item); break;
    case 'text': renderText(el, item); break;
    case 'note': renderNote(el, item); break;
    case 'shape': renderShape(el, item); break;
    case 'pen_shape': renderPenShape(el, item); break;
    case 'drawing': renderDrawing(el, item); break;
    case 'color_swatch': renderColorSwatch(el, item); break;
    case 'todo_list': renderTodoList(el, item); break;
    case 'link': renderLink(el, item); break;
    case 'file': renderFile(el, item); break;
    case 'image_set': renderImageSet(el, item); break;
    case 'video': renderVideo(el, item); break;
    case 'youtube': renderYoutube(el, item); break;
    case 'audio': renderAudio(el, item); break;
    case 'table': renderTable(el, item); break;
    case 'line': renderLine(el, item); break;
    case 'frame': renderFrame(el, item, allItems); break;
    case 'calendar_event': renderCalendarEvent(el, item); break;
    case 'column': renderColumn(el, item, allItems); break;
    default: renderGeneric(el, item); break;
  }

  return el;
}

// --- Individual item renderers ---

function renderImage(el, item) {
  const sd = item.style_data || {};
  const src = resolveAsset(item.image_url || item.thumbnail_url);
  if (!src) {
    el.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af"><span class="msym" style="font-size:2rem">image</span></div>';
    return;
  }
  const img = document.createElement('img');
  img.src = src;
  img.draggable = false;
  img.decoding = 'async';
  img.style.objectFit = sd.object_fit || 'cover';
  if (sd.border_radius) img.style.borderRadius = sd.border_radius + 'px';
  if (sd.border_radius) el.style.borderRadius = sd.border_radius + 'px';
  el.style.overflow = 'hidden';
  el.appendChild(img);
}

function renderText(el, item) {
  const sd = item.style_data || {};
  const div = document.createElement('div');
  div.innerHTML = item.content || '';
  div.style.fontFamily = sd.font_family || 'Inter, system-ui, sans-serif';
  div.style.fontSize = (sd.font_size || 16) + 'px';
  div.style.color = sd.text_color || '#1f2937';
  div.style.textAlign = sd.text_align || 'left';
  if (sd.font_weight) div.style.fontWeight = sd.font_weight;
  if (sd.line_height) div.style.lineHeight = sd.line_height;
  if (sd.letter_spacing) div.style.letterSpacing = sd.letter_spacing + 'px';
  el.appendChild(div);
}

function renderNote(el, item) {
  el.style.backgroundColor = item.color || '#fef3c7';
  el.style.height = '100%';
  el.style.minHeight = '100px';
  const pad = document.createElement('div');
  pad.style.padding = '1rem';
  if (item.title) {
    const t = document.createElement('div');
    t.className = 'note-title';
    t.textContent = item.title;
    t.style.color = getContrastColor(item.color || '#fef3c7');
    pad.appendChild(t);
  }
  if (item.content) {
    const c = document.createElement('div');
    c.className = 'note-content';
    c.innerHTML = item.content;
    c.style.color = getContrastColor(item.color || '#fef3c7');
    pad.appendChild(c);
  }
  el.appendChild(pad);
}

function renderShape(el, item) {
  const sd = item.style_data || {};
  const shapeType = sd.shape_type || 'rectangle';
  const bgColor = item.color || sd.background_color || '#6366f1';
  const maskUrl = sd.mask_image_url ? resolveAsset(sd.mask_image_url) : null;

  // Apply clip path
  const clipClass = 'clip-' + shapeType;
  if (['triangle','star','pentagon','hexagon','diamond','arrow_right','cross'].includes(shapeType)) {
    el.classList.add(clipClass);
  }

  // Border radius
  if (shapeType === 'circle' || shapeType === 'ellipse') {
    el.style.borderRadius = '50%';
  } else if (sd.border_radius != null) {
    el.style.borderRadius = sd.border_radius + 'px';
  }

  // Background
  if (sd.gradient && sd.gradient.enabled) {
    const angle = sd.gradient.angle || 135;
    el.style.background = 'linear-gradient(' + angle + 'deg, ' + (sd.gradient.from || bgColor) + ', ' + (sd.gradient.to || '#000') + ')';
  } else {
    el.style.backgroundColor = bgColor;
  }

  // Border
  if (sd.border_width) {
    el.style.border = sd.border_width + 'px ' + (sd.border_style || 'solid') + ' ' + (sd.border_color || '#000');
  }

  // Mask image
  if (maskUrl) {
    const img = document.createElement('img');
    img.src = maskUrl;
    img.draggable = false;
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
    if (shapeType === 'circle' || shapeType === 'ellipse') img.style.borderRadius = '50%';
    el.style.overflow = 'hidden';
    el.appendChild(img);
  }
}

function renderPenShape(el, item) {
  const sd = item.style_data || {};
  el.style.overflow = 'hidden';

  // Parse path data
  let pathData = sd.pen_path || (typeof item.content === 'string' ? item.content : '');

  if (pathData) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 ' + (item.width || 200) + ' ' + (item.height || 200));
    svg.style.cssText = 'width:100%;height:100%;';

    const clipId = 'pen-clip-' + item.id;
    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    const clipPath = document.createElementNS('http://www.w3.org/2000/svg', 'clipPath');
    clipPath.id = clipId;
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', pathData);
    clipPath.appendChild(path);
    defs.appendChild(clipPath);
    svg.appendChild(defs);

    const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    rect.setAttribute('width', '100%');
    rect.setAttribute('height', '100%');
    rect.setAttribute('fill', item.color || sd.background_color || '#6366f1');
    rect.setAttribute('clip-path', 'url(#' + clipId + ')');
    svg.appendChild(rect);

    // Mask image
    const maskUrl = sd.mask_image_url ? resolveAsset(sd.mask_image_url) : null;
    if (maskUrl) {
      const img = document.createElementNS('http://www.w3.org/2000/svg', 'image');
      img.setAttribute('href', maskUrl);
      img.setAttribute('width', '100%');
      img.setAttribute('height', '100%');
      img.setAttribute('preserveAspectRatio', 'xMidYMid slice');
      img.setAttribute('clip-path', 'url(#' + clipId + ')');
      svg.appendChild(img);
    }

    el.appendChild(svg);
  }
}

function renderDrawing(el, item) {
  let strokeData = null;
  try {
    strokeData = typeof item.content === 'string' ? JSON.parse(item.content) : item.content;
  } catch(e) { return; }

  if (!strokeData || !strokeData.strokes || !strokeData.strokes.length) return;

  const vbW = strokeData.viewBox?.w || item.width || 200;
  const vbH = strokeData.viewBox?.h || item.height || 200;

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 ' + vbW + ' ' + vbH);
  svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
  svg.style.cssText = 'width:100%;height:100%';

  strokeData.strokes.forEach(stroke => {
    if (!stroke.points || stroke.points.length < 2) return;
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const d = strokePointsToPath(stroke.points, stroke.size || 3);
    path.setAttribute('d', d);
    path.setAttribute('fill', stroke.color || '#1f2937');
    path.setAttribute('stroke', 'none');
    svg.appendChild(path);
  });

  el.appendChild(svg);
}

function strokePointsToPath(points, size) {
  if (points.length < 2) return '';
  // Build a filled path with variable width based on pressure
  const halfSize = size / 2;
  let d = '';
  for (let i = 0; i < points.length; i++) {
    const [x, y, pressure] = points[i];
    const r = halfSize * (pressure || 0.5);
    if (i === 0) {
      d += 'M ' + (x - r).toFixed(1) + ' ' + y.toFixed(1);
    }
    d += ' L ' + x.toFixed(1) + ' ' + (y - r).toFixed(1);
  }
  for (let i = points.length - 1; i >= 0; i--) {
    const [x, y, pressure] = points[i];
    const r = halfSize * (pressure || 0.5);
    d += ' L ' + x.toFixed(1) + ' ' + (y + r).toFixed(1);
  }
  d += ' Z';
  return d;
}

function renderColorSwatch(el, item) {
  el.style.backgroundColor = item.color || '#6366f1';
  el.style.minHeight = '80px';
  el.style.height = '100%';

  const label = document.createElement('div');
  label.className = 'swatch-label';
  const hex = document.createElement('span');
  hex.className = 'swatch-hex';
  hex.textContent = item.color || '#6366f1';
  label.appendChild(hex);

  if (item.color_data) {
    const cd = typeof item.color_data === 'string' ? JSON.parse(item.color_data) : item.color_data;
    if (cd.rgb) {
      const rgb = document.createElement('div');
      rgb.className = 'swatch-detail';
      rgb.textContent = 'R' + cd.rgb.r + ' G' + cd.rgb.g + ' B' + cd.rgb.b;
      label.appendChild(rgb);
    }
    if (cd.cmyk) {
      const cmyk = document.createElement('div');
      cmyk.className = 'swatch-detail';
      cmyk.textContent = 'C' + cd.cmyk.c + ' M' + cd.cmyk.m + ' Y' + cd.cmyk.y + ' K' + cd.cmyk.k;
      label.appendChild(cmyk);
    }
  }
  el.appendChild(label);
}

function renderTodoList(el, item) {
  if (item.title) {
    const t = document.createElement('p');
    t.className = 'todo-title';
    t.textContent = item.title;
    el.appendChild(t);
  }
  (item.todos || []).forEach(todo => {
    const lbl = document.createElement('label');
    const chk = document.createElement('span');
    chk.className = 'todo-check' + (todo.completed ? ' done' : '');
    if (todo.completed) chk.innerHTML = '<span class="msym" style="font-size:.75rem">check</span>';
    const txt = document.createElement('span');
    txt.className = 'todo-text' + (todo.completed ? ' done' : '');
    txt.textContent = todo.text || '';
    lbl.appendChild(chk);
    lbl.appendChild(txt);
    el.appendChild(lbl);
  });
}

function renderLink(el, item) {
  if (item.title) {
    const t = document.createElement('div');
    t.style.cssText = 'font-size:.875rem;font-weight:600;color:#111827;margin-bottom:.25rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
    t.textContent = item.title;
    el.appendChild(t);
  }
  if (item.url) {
    const u = document.createElement('div');
    u.className = 'link-url';
    u.textContent = item.url;
    el.appendChild(u);
  }
}

function renderFile(el, item) {
  const src = resolveAsset(item.image_url || item.thumbnail_url);
  const body = document.createElement('div');
  body.className = 'file-body';
  if (src) {
    const img = document.createElement('img');
    img.src = src;
    img.draggable = false;
    img.style.cssText = 'width:100%;height:100%;object-fit:cover';
    body.appendChild(img);
  } else {
    body.innerHTML = '<span class="msym" style="font-size:2.5rem;color:#9ca3af">description</span>';
  }
  el.appendChild(body);

  const footer = document.createElement('div');
  footer.className = 'file-footer';
  footer.textContent = item.title || 'File';
  el.appendChild(footer);
}

function renderImageSet(el, item) {
  const images = item.images || [];
  if (!images.length) return;

  const cols = images.length <= 1 ? 1 : images.length <= 4 ? 2 : 3;
  el.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';

  images.forEach(img => {
    const src = resolveAsset(img.image_url || img.thumbnail_url);
    if (src) {
      const imgEl = document.createElement('img');
      imgEl.src = src;
      imgEl.draggable = false;
      imgEl.decoding = 'async';
      el.appendChild(imgEl);
    }
  });
}

function renderVideo(el, item) {
  const src = resolveAsset(item.url || item.image_url);
  if (src) {
    const video = document.createElement('video');
    video.src = src;
    video.controls = true;
    video.preload = 'metadata';
    video.style.cssText = 'width:100%;height:100%;object-fit:cover';
    el.appendChild(video);
  } else {
    el.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#fff"><span class="msym" style="font-size:2rem">videocam</span></div>';
  }
}

function renderYoutube(el, item) {
  const videoId = extractYoutubeId(item.url);
  const thumbUrl = videoId ? 'https://img.youtube.com/vi/' + videoId + '/hqdefault.jpg' : '';

  if (thumbUrl) {
    const img = document.createElement('img');
    img.src = thumbUrl;
    img.style.cssText = 'width:100%;height:100%;object-fit:cover';
    el.appendChild(img);
  }

  const overlay = document.createElement('div');
  overlay.className = 'yt-overlay';
  overlay.innerHTML = '<div class="yt-play"><span class="msym" style="font-size:1.5rem;color:#fff">play_arrow</span></div><span>Requires internet</span>';
  el.appendChild(overlay);
}

function renderAudio(el, item) {
  const sd = item.style_data || {};
  if (item.title) {
    const t = document.createElement('div');
    t.className = 'audio-title';
    t.textContent = item.title;
    el.appendChild(t);
  }
  const src = resolveAsset(item.url || item.image_url);
  if (src) {
    const audio = document.createElement('audio');
    audio.src = src;
    audio.controls = true;
    audio.preload = 'metadata';
    el.appendChild(audio);
  }
}

function renderTable(el, item) {
  let tableData = null;
  try {
    tableData = typeof item.content === 'string' ? JSON.parse(item.content) : item.content;
  } catch(e) { return; }
  if (!tableData || !tableData.rows) return;

  const table = document.createElement('table');
  tableData.rows.forEach((row, ri) => {
    const tr = document.createElement('tr');
    (row.cells || row).forEach(cell => {
      const td = document.createElement(ri === 0 ? 'th' : 'td');
      td.textContent = typeof cell === 'object' ? (cell.value || cell.text || '') : (cell || '');
      tr.appendChild(td);
    });
    table.appendChild(tr);
  });
  el.appendChild(table);
}

function renderLine(el) {
  // Lines/connections are handled via the SVG overlay, not individual items
  el.style.display = 'none';
}

function renderFrame(el, item, allItems) {
  const sd = item.style_data || {};
  if (sd.frame_label !== false && item.title) {
    const label = document.createElement('div');
    label.style.cssText = 'position:absolute;top:-1.25rem;left:.25rem;font-size:.625rem;font-weight:600;color:#9ca3af;white-space:nowrap';
    label.textContent = item.title;
    el.appendChild(label);
  }

  // Render children inside the frame
  const children = (allItems || [])
    .filter(c => c.parent_id === item.id)
    .sort((a, b) => (a.z_index || 0) - (b.z_index || 0));

  const frameX = item.pos_x || 0;
  const frameY = item.pos_y || 0;

  children.forEach(child => {
    const childEl = createItemElement(child, allItems);
    if (!childEl) return;
    // Adjust position relative to frame
    const cx = (child.pos_x || 0) - frameX;
    const cy = (child.pos_y || 0) - frameY;
    const rot = child.rotation ? ' rotate(' + child.rotation + 'deg)' : '';
    const csd = child.style_data || {};
    const fX = csd.flip_x ? ' scaleX(-1)' : '';
    const fY = csd.flip_y ? ' scaleY(-1)' : '';
    childEl.style.transform = 'translate(' + cx + 'px, ' + cy + 'px)' + rot + fX + fY;
    el.appendChild(childEl);
  });
}

function renderColumn(el, item, allItems) {
  el.style.display = 'flex';
  el.style.flexDirection = 'column';
  el.style.gap = '8px';
  renderFrame(el, item, allItems);
}

function renderCalendarEvent(el, item) {
  const sd = item.style_data || {};
  const color = sd.event_color || '#3b82f6';
  el.innerHTML = '<div style="display:flex;align-items:start;gap:.5rem">' +
    '<div style="width:2rem;height:2rem;border-radius:.5rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:' + color + '20"><span class="msym" style="color:' + color + '">event</span></div>' +
    '<div style="min-width:0"><div style="font-size:.875rem;font-weight:600;color:#111827">' + escHtml(item.title || 'Event') + '</div>' +
    (item.content ? '<div style="font-size:.75rem;color:#6b7280;margin-top:.125rem">' + escHtml(item.content) + '</div>' : '') +
    (sd.event_location ? '<div style="font-size:.75rem;color:#9ca3af;margin-top:.25rem">' + escHtml(sd.event_location) + '</div>' : '') +
    '</div></div>';
}

function renderGeneric(el, item) {
  const icons = {board_link:'dashboard',folder:'folder',artboard:'crop_landscape',column:'view_column'};
  const icon = icons[item.type] || 'widgets';
  el.innerHTML = '<span class="msym">' + icon + '</span><span>' + escHtml(item.title || item.type) + '</span>';
}

// ============================================================
// CONNECTIONS (SVG)
// ============================================================
function renderConnections() {
  const connections = BOARD.connections || [];
  if (!connections.length) return;

  const svg = document.getElementById('connections-svg') || createConnectionsSvg();
  const items = BOARD.items || [];
  const itemMap = {};
  items.forEach(i => { itemMap[i.id] = i; });

  connections.forEach(conn => {
    const fromItem = itemMap[conn.from_item_id];
    const toItem = itemMap[conn.to_item_id];
    if (!fromItem || !toItem) return;

    const path = getConnectionPath(conn, fromItem, toItem);
    if (!path) return;

    const color = conn.color || '#94a3b8';
    const width = conn.line_width || 2;

    const pathEl = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    pathEl.setAttribute('d', path);
    pathEl.setAttribute('stroke', color);
    pathEl.setAttribute('stroke-width', width);
    pathEl.setAttribute('fill', 'none');
    pathEl.setAttribute('stroke-linecap', 'round');
    if (conn.line_style === 'dashed') {
      pathEl.setAttribute('stroke-dasharray', (width * 3) + ' ' + (width * 2));
    } else if (conn.line_style === 'dotted') {
      pathEl.setAttribute('stroke-dasharray', width + ' ' + (width * 2));
    }

    // Arrow markers
    if (conn.arrow_end || conn.arrow_start) {
      const markerId = 'arrow-' + conn.id;
      const defs = svg.querySelector('defs') || svg.insertBefore(document.createElementNS('http://www.w3.org/2000/svg', 'defs'), svg.firstChild);

      if (conn.arrow_end) {
        const marker = createArrowMarker(markerId + '-end', color, false);
        defs.appendChild(marker);
        pathEl.setAttribute('marker-end', 'url(#' + markerId + '-end)');
      }
      if (conn.arrow_start) {
        const marker = createArrowMarker(markerId + '-start', color, true);
        defs.appendChild(marker);
        pathEl.setAttribute('marker-start', 'url(#' + markerId + '-start)');
      }
    }

    svg.appendChild(pathEl);
  });
}

function createConnectionsSvg() {
  const canvas = document.getElementById('canvas-layer');
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.id = 'connections-svg';
  canvas.insertBefore(svg, canvas.firstChild);
  return svg;
}

function createArrowMarker(id, color, reverse) {
  const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
  marker.setAttribute('id', id);
  marker.setAttribute('markerWidth', '10');
  marker.setAttribute('markerHeight', '7');
  marker.setAttribute('refX', reverse ? '0' : '10');
  marker.setAttribute('refY', '3.5');
  marker.setAttribute('orient', 'auto');
  const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
  poly.setAttribute('points', reverse ? '10 0, 0 3.5, 10 7' : '0 0, 10 3.5, 0 7');
  poly.setAttribute('fill', color);
  marker.appendChild(poly);
  return marker;
}

function getConnectionPath(conn, fromItem, toItem) {
  const fromCX = (fromItem.pos_x || 0) + (fromItem.width || 240) / 2;
  const fromCY = (fromItem.pos_y || 0) + (fromItem.height || 200) / 2;
  const toCX = (toItem.pos_x || 0) + (toItem.width || 240) / 2;
  const toCY = (toItem.pos_y || 0) + (toItem.height || 200) / 2;

  // Resolve anchor points on item edges
  const x1 = conn.from_anchor_x != null ? (fromItem.pos_x || 0) + conn.from_anchor_x * (fromItem.width || 240) : fromCX;
  const y1 = conn.from_anchor_y != null ? (fromItem.pos_y || 0) + conn.from_anchor_y * (fromItem.height || 200) : fromCY;
  const x2 = conn.to_anchor_x != null ? (toItem.pos_x || 0) + conn.to_anchor_x * (toItem.width || 240) : toCX;
  const y2 = conn.to_anchor_y != null ? (toItem.pos_y || 0) + conn.to_anchor_y * (toItem.height || 200) : toCY;

  const hasBend1 = conn.bend_x != null && conn.bend_y != null;
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null;

  if (hasBend1 || hasBend2) {
    const cp1x = hasBend1 ? conn.bend_x : (x1 * 2 / 3 + x2 / 3);
    const cp1y = hasBend1 ? conn.bend_y : (y1 * 2 / 3 + y2 / 3);
    const cp2x = hasBend2 ? conn.bend2_x : (x1 / 3 + x2 * 2 / 3);
    const cp2y = hasBend2 ? conn.bend2_y : (y1 / 3 + y2 * 2 / 3);
    return 'M ' + x1 + ' ' + y1 + ' C ' + cp1x + ' ' + cp1y + ', ' + cp2x + ' ' + cp2y + ', ' + x2 + ' ' + y2;
  }

  const dx = x2 - x1, dy = y2 - y1;
  const dist = Math.sqrt(dx * dx + dy * dy);
  const curvature = Math.min(dist * 0.25, 80);
  const absDx = Math.abs(dx), absDy = Math.abs(dy);
  let cx1, cy1, cx2, cy2;
  if (absDx >= absDy) {
    cx1 = x1 + curvature * Math.sign(dx || 1); cy1 = y1;
    cx2 = x2 - curvature * Math.sign(dx || 1); cy2 = y2;
  } else {
    cx1 = x1; cy1 = y1 + curvature * Math.sign(dy || 1);
    cx2 = x2; cy2 = y2 - curvature * Math.sign(dy || 1);
  }
  return 'M ' + x1 + ' ' + y1 + ' C ' + cx1 + ' ' + cy1 + ', ' + cx2 + ' ' + cy2 + ', ' + x2 + ' ' + y2;
}

// ============================================================
// BACKGROUND AUDIO
// ============================================================
function setupBgAudio() {
  let config = BOARD.bg_audio;
  if (!config) return;
  if (typeof config === 'string') {
    try { config = JSON.parse(config); } catch(e) { return; }
  }
  if (!config || !config.url || config.type !== 'file') {
    if (config && config.type === 'youtube') {
      const ind = document.getElementById('bg-audio-indicator');
      ind.textContent = 'BG audio requires internet';
      ind.classList.add('visible');
      setTimeout(() => ind.classList.remove('visible'), 5000);
    }
    return;
  }

  const src = resolveAsset(config.url);
  if (!src) return;

  bgAudioEl = document.createElement('audio');
  bgAudioEl.src = src;
  bgAudioEl.preload = 'auto';
  bgAudioEl.loop = config.loop !== false;
  bgAudioEl.volume = Math.max(0, Math.min(1, config.volume != null ? config.volume / 100 : 0.7));
  bgAudioEl.style.cssText = 'position:fixed;width:0;height:0;overflow:hidden;pointer-events:none;z-index:-1';
  document.body.appendChild(bgAudioEl);

  // Loop range handling
  const loopStart = parseTime(config.loop_start);
  const loopEnd = parseTime(config.loop_end);
  if (loopStart != null || loopEnd != null) {
    bgAudioEl.loop = false;
    bgAudioEl.addEventListener('timeupdate', function() {
      if (loopEnd != null && bgAudioEl.currentTime >= loopEnd) {
        bgAudioEl.currentTime = loopStart || 0;
      }
    });
    bgAudioEl.addEventListener('ended', function() {
      if (config.loop !== false) {
        bgAudioEl.currentTime = loopStart || 0;
        bgAudioEl.play().catch(function(){});
      }
    });
    if (loopStart != null) {
      bgAudioEl.addEventListener('loadedmetadata', function() {
        bgAudioEl.currentTime = loopStart;
      }, { once: true });
    }
  }
}

function parseTime(str) {
  if (str == null || str === '') return null;
  if (!isNaN(Number(str))) return Number(str);
  const parts = String(str).split(':').map(Number);
  if (parts.some(isNaN)) return null;
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return parts[0];
}

// ============================================================
// CAMERA SYSTEM
// ============================================================
function setCamera(z, px, py) {
  zoom = z; panX = px; panY = py;
  document.getElementById('canvas-layer').style.transform =
    'translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + ')';
}

function animateToSlide(index, callback) {
  if (index < 0 || index >= slides.length) { if (callback) callback(); return; }

  const slide = slides[index];
  const transition = slide.transition_type || 'fly';
  const defaultDur = transition === 'instant' ? 0 : transition === 'fade' ? 400 : 600;
  const durSec = slide.transition_duration != null ? slide.transition_duration : (defaultDur / 1000);
  const duration = Math.round(durSec * 1000);

  const vpW = window.innerWidth;
  const vpH = window.innerHeight;
  const frameW = slide.width || 240;
  const frameH = slide.height || 135;

  const scaleX = vpW / frameW;
  const scaleY = vpH / frameH;
  const targetZoom = Math.max(0.005, Math.min(scaleX, scaleY));

  const targetCX = (slide.pos_x || 0) + frameW / 2;
  const targetCY = (slide.pos_y || 0) + frameH / 2;
  const targetPanX = (vpW / 2) - targetCX * targetZoom;
  const targetPanY = (vpH / 2) - targetCY * targetZoom;

  if (transition === 'instant' || duration <= 0) {
    setCamera(targetZoom, targetPanX, targetPanY);
    if (callback) callback();
    return;
  }

  if (transition === 'fade') {
    const fadeHalf = Math.round(duration / 2) || 200;
    const overlay = document.getElementById('fade-overlay');
    overlay.style.backgroundColor = BOARD.background_color || '#111';
    overlay.classList.add('active');
    overlay.style.transition = 'opacity ' + fadeHalf + 'ms';
    setTimeout(function() {
      setCamera(targetZoom, targetPanX, targetPanY);
      setTimeout(function() {
        overlay.classList.remove('active');
        if (callback) callback();
      }, 50);
    }, fadeHalf);
    return;
  }

  // Fly transition (default) -- world-space interpolation with log zoom
  if (animRaf) cancelAnimationFrame(animRaf);

  const startZoom = zoom;
  const startCX = (vpW / 2 - panX) / startZoom;
  const startCY = (vpH / 2 - panY) / startZoom;
  const startLogZ = Math.log(startZoom);
  const targetLogZ = Math.log(targetZoom);

  // Adaptive duration based on travel distance
  const zoomRatio = Math.abs(targetLogZ - startLogZ) / Math.LN2;
  const worldDist = Math.sqrt((targetCX - startCX) ** 2 + (targetCY - startCY) ** 2);
  const avgZoom = (startZoom + targetZoom) / 2;
  const screenDist = (worldDist * avgZoom) / Math.max(vpW, vpH);
  const travelFactor = Math.max(1, zoomRatio * 0.8, screenDist * 0.5);
  const effectiveDuration = Math.min(Math.round(duration * travelFactor), 2500);

  const startTime = performance.now();

  function easeInOutCubic(t) {
    return t < 0.5 ? 4*t*t*t : 1 - Math.pow(-2*t + 2, 3) / 2;
  }

  function step(now) {
    const elapsed = now - startTime;
    const progress = Math.min(1, elapsed / effectiveDuration);
    const eased = easeInOutCubic(progress);

    const cx = startCX + (targetCX - startCX) * eased;
    const cy = startCY + (targetCY - startCY) * eased;
    const z = Math.exp(startLogZ + (targetLogZ - startLogZ) * eased);

    setCamera(z, (vpW / 2) - cx * z, (vpH / 2) - cy * z);

    if (progress < 1) {
      animRaf = requestAnimationFrame(step);
    } else {
      animRaf = null;
      if (callback) callback();
    }
  }

  animRaf = requestAnimationFrame(step);
}

// ============================================================
// SLIDE NAVIGATION
// ============================================================
function goToSlide(index) {
  if (navigating || index < 0 || index >= slides.length || index === currentSlideIndex) return;
  navigating = true;
  currentSlideIndex = index;
  updateHud();
  animateToSlide(index, function() { navigating = false; });
}

function nextSlide() {
  if (currentSlideIndex < slides.length - 1) goToSlide(currentSlideIndex + 1);
}

function prevSlide() {
  if (currentSlideIndex > 0) goToSlide(currentSlideIndex - 1);
}

// ============================================================
// HUD
// ============================================================
function updateHud() {
  const slide = slides[currentSlideIndex];
  document.getElementById('hud-title').textContent = slide ? (slide.title || 'Slide ' + (currentSlideIndex + 1)) : '';
  document.getElementById('hud-counter').textContent = (currentSlideIndex + 1) + ' / ' + slides.length;
  document.getElementById('btn-prev').disabled = currentSlideIndex === 0;
  document.getElementById('btn-next').disabled = currentSlideIndex >= slides.length - 1;

  const pct = slides.length <= 1 ? 100 : (currentSlideIndex / (slides.length - 1)) * 100;
  document.getElementById('progress-fill').style.width = pct + '%';
}

function showHud() {
  hudHidden = false;
  document.getElementById('hud').classList.remove('hidden');
  clearTimeout(hudTimer);
  hudTimer = setTimeout(hideHud, 3000);
}

function hideHud() {
  hudHidden = true;
  document.getElementById('hud').classList.add('hidden');
}

// ============================================================
// INPUT HANDLING
// ============================================================
function onKeyDown(e) {
  if (!isPresenting) return;
  switch (e.key) {
    case 'ArrowRight': case 'PageDown': case ' ':
      e.preventDefault(); nextSlide(); break;
    case 'ArrowLeft': case 'PageUp':
      e.preventDefault(); prevSlide(); break;
    case 'Escape':
      e.preventDefault(); exitPresentation(); break;
    case 'Home':
      e.preventDefault(); goToSlide(0); break;
    case 'End':
      e.preventDefault(); goToSlide(slides.length - 1); break;
  }
  showHud();
}

function onWheel(e) {
  if (!isPresenting || scrollCooldown) return;
  e.preventDefault();
  scrollAccum += e.deltaY;
  if (Math.abs(scrollAccum) >= 60) {
    if (scrollAccum > 0) nextSlide(); else prevSlide();
    scrollAccum = 0;
    scrollCooldown = true;
    setTimeout(function() { scrollCooldown = false; }, 1200);
  }
}

function onTouchStart(e) {
  if (!isPresenting) return;
  showHud();
  if (e.touches.length !== 1) return;
  touchStartX = e.touches[0].clientX;
  touchStartY = e.touches[0].clientY;
  touchStartTime = Date.now();
}

function onTouchEnd(e) {
  if (!isPresenting) return;
  const touch = e.changedTouches[0];
  if (!touch) return;
  const dx = touch.clientX - touchStartX;
  const dy = touch.clientY - touchStartY;
  const dt = Date.now() - touchStartTime;
  const absDx = Math.abs(dx), absDy = Math.abs(dy);

  if (absDx > 50 && absDx > absDy * 1.5 && dt < 600) {
    if (dx < 0) nextSlide(); else prevSlide();
    return;
  }
  if (dt < 300 && absDx < 15 && absDy < 15) {
    const tapX = touch.clientX / window.innerWidth;
    if (tapX < 0.25) prevSlide(); else nextSlide();
  }
}

function onMouseMove() {
  if (!isPresenting) return;
  showHud();
}

function onCanvasClick(e) {
  if (!isPresenting) return;
  // Don't advance if clicking on interactive elements
  if (e.target.closest('video, audio, a, button')) return;
  showHud();
}

// ============================================================
// PRESENTATION LIFECYCLE
// ============================================================
function startPresentation() {
  if (slides.length === 0) return;
  isPresenting = true;
  currentSlideIndex = 0;

  // Hide landing
  document.getElementById('landing').classList.add('hidden');

  // Request fullscreen
  const el = document.documentElement;
  if (el.requestFullscreen) el.requestFullscreen().catch(function(){});
  else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();

  // Start bg audio
  if (bgAudioEl) bgAudioEl.play().catch(function(){});

  // Animate to first slide
  updateHud();
  animateToSlide(0, function() {
    showHud();
  });

  // Set initial camera instantly first to avoid flash
  const slide = slides[0];
  const vpW = window.innerWidth, vpH = window.innerHeight;
  const frameW = slide.width || 240, frameH = slide.height || 135;
  const scX = vpW / frameW, scY = vpH / frameH;
  const tZ = Math.max(0.005, Math.min(scX, scY));
  const tCX = (slide.pos_x || 0) + frameW / 2;
  const tCY = (slide.pos_y || 0) + frameH / 2;
  setCamera(tZ, (vpW / 2) - tCX * tZ, (vpH / 2) - tCY * tZ);
}

function exitPresentation() {
  isPresenting = false;
  if (bgAudioEl) bgAudioEl.pause();
  if (document.fullscreenElement) {
    document.exitFullscreen().catch(function(){});
  } else if (document.webkitFullscreenElement) {
    document.webkitExitFullscreen();
  }
  document.getElementById('landing').classList.remove('hidden');
  document.getElementById('hud').classList.add('hidden');
}

// Fullscreen change handler
function onFullscreenChange() {
  if (isPresenting && !document.fullscreenElement && !document.webkitFullscreenElement) {
    exitPresentation();
  }
}

// ============================================================
// HELPERS
// ============================================================
function escHtml(str) {
  if (!str) return '';
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function getContrastColor(hex) {
  hex = hex.replace('#', '');
  if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
  const r = parseInt(hex.substring(0,2), 16);
  const g = parseInt(hex.substring(2,4), 16);
  const b = parseInt(hex.substring(4,6), 16);
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.5 ? '#1f2937' : '#f9fafb';
}

function extractYoutubeId(url) {
  if (!url) return null;
  const m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  return m ? m[1] : null;
}

// ============================================================
// BOOT
// ============================================================
document.addEventListener('keydown', onKeyDown);
document.addEventListener('mousemove', onMouseMove);
document.addEventListener('wheel', onWheel, { passive: false });
document.addEventListener('touchstart', onTouchStart, { passive: true });
document.addEventListener('touchend', onTouchEnd, { passive: true });
document.getElementById('canvas-container').addEventListener('click', onCanvasClick);
document.addEventListener('fullscreenchange', onFullscreenChange);
document.addEventListener('webkitfullscreenchange', onFullscreenChange);

init();
</script>

</body>
</html>
