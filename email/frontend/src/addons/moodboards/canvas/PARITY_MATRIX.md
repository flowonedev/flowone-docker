# Pixi Parity Matrix

Tracks feature parity between the legacy DOM/SVG engine (MoodCanvas.vue + MoodCanvasItem.vue)
and the new Pixi engine (PixiCanvas.vue + canvas/*).

| # | Capability | Legacy | Pixi | Status |
|---|-----------|--------|------|--------|
| 1 | Pan (space-drag, touch) | Yes | Yes | Done |
| 2 | Zoom (wheel, pinch) | Yes | Yes | Done |
| 3 | Dot grid + board bg image/color | Yes | Yes | Done |
| 4 | Background effects (grain, gradient, vignette, blur) | Yes | Yes | Done |
| 5 | Selection marquee + multi-select | Yes | Yes | Done |
| 6 | Drag with smart guides + distance measurements | Yes | Yes | Done |
| 7 | Grid snap + center snap | Yes | Yes | Done |
| 8 | Resize (8 handles) | Yes | Yes | Done |
| 9 | Rotate via handle | Yes | Yes | Done |
| 10 | Lock / duplicate / delete (UI + shortcuts) | Yes | Yes | Done |
| 11 | Z-order: bring forward/back, front/back | Yes | Yes | Done |
| 12 | Keyboard nudge (arrows, Shift=10px) | Yes | Yes | Done |
| 13 | Undo/redo | Yes | Yes | Done |
| 14 | Copy/paste + clipboard images + internal clipboard | Yes | Yes | Done |
| 15 | Line tool | Yes | Yes | Done |
| 16 | Comment pins + collaborator cursors | Yes | Yes | Done |
| 17 | Presentation mode + toggles | Yes | Yes | Done |
| 18 | Sketch .sketch import | Yes | Yes | Done |
| 19 | Context menus (canvas, connection, item) | Yes | Yes | Done |
| 20 | Alt+drag duplicate | Yes | Yes | Done |
| 21 | Measure tool (interactive) | Yes | Yes | Done |
| 22 | Freehand draw mode (perfect-freehand) | Yes | Yes | Done |
| 23 | Pen tool overlay (MoodPenTool) | Yes | Yes | Done |
| 24 | Shape child masking (mask_parent_id) | Yes | Yes | Done |
| 25 | Shape image mask (mask_image_url) | Yes | Yes | Done |
| 26 | Container family: slide + artboard in child tree | Yes | Yes | Done |
| 27 | Repeat grid: clones, gap/cell handles | Yes | Yes | Done |
| 28 | Connection labels | Yes | Yes | Done |
| 29 | Connection draw-on reveal | Yes | Yes | Done |
| 30 | Connection focus dimming | Yes | Yes | Done |
| 31 | Connection wave filter | Yes | Yes | Done |
| 32 | Drop/paste upload pipeline | Yes | Yes | Done |
| 33 | Rich card UX (todo, table, video, audio, etc.) | Yes | Yes | Done |
| 34 | Legacy canvas_strokes display | Yes | Yes | Done |
| 35 | Box-model overlay (DevTools-style) | Yes | Yes | Done |
| 36 | component_id badge | Yes | Yes | Done |
| 37 | Focus mode dimming (items) | Yes | Yes | Done |
| 38 | Corner radius drag handles on shapes | Yes | Yes | Done |
| 39 | Iframe pointer-events suppression during drag | Yes | N/A | N/A (WebGL) |
| 40 | LOD / zoom-tier simplification | Yes | Yes | Done |
| 41 | Boolean ops hotkeys (Alt+Shift+U/S/I/E/F) | Yes | Yes | Done |
| 42 | Group/ungroup (Ctrl+G) | Yes | Yes | Done |
| 43 | Enter/exit group (double-click) | Yes | Yes | Done |
| 44 | Frame auto-layout children | Yes | Yes | Done |
| 45 | Group flex/grid layout modes | Yes | Yes | Done |
| 46 | Legacy group_id multi-select bbox | Yes | Yes | Done |

## Rendering fidelity

Tracks visual output parity (styleToPixi.js + renderer types vs the DOM/CSS pipeline).

| # | Capability | Legacy | Pixi | Status |
|---|-----------|--------|------|--------|
| R1 | Multiple stacked fills (bottom-to-top, like CSS backgrounds) | Yes | Yes | Done |
| R2 | Solid fill + opacity multiply | Yes | Yes | Done |
| R3 | Linear gradient fill | Yes | Yes | Done |
| R4 | Radial gradient fill | Yes | Yes | Done |
| R5 | Angular (conic) gradient fill | Yes | Yes | Done (canvas2d-rasterized texture) |
| R6 | Diamond gradient fill | Linear fallback | Linear fallback | Done (same fallback both engines) |
| R7 | Image fills on shapes (masked to shape path) | Yes | Yes | Done |
| R8 | Drop shadow follows shape geometry (ellipse/star/polygon) | Yes | Yes | Done (perimeter-sampled passes) |
| R9 | Inner shadow | Yes | Yes | Done (masked inset rings) |
| R10 | Layer blur / backdrop blur | Yes | No | Deferred (needs filter pass; DOM overlay covers text) |
| R11 | Dashed/dotted strokes on shapes + frames | Yes | Yes | Done |
| R12 | Dashed/dotted standalone lines | Yes | Yes | Done |
| R13 | `style_data._hidden` items excluded from render + hit-test | Yes | Yes | Done |
| R14 | Shape label text color/weight/size (`shape_text_color`) | Yes | Yes | Done |
| R15 | Per-corner radii on images (mask + border) | Yes | Yes | Done |
| R16 | Text decoration (underline/strikethrough) in text overlay | Yes | Yes | Done |
| R17 | Blend modes | Yes | Yes | Done |
| R18 | Video/audio/YouTube playback | Yes | Yes | Done (MediaOverlay.vue, selection-gated controls) |
| R19 | Table cell content | Yes | Yes | Done (title bar + header + cells in CardRenderer) |
| R20 | Motion animations (wobble/float) | Yes | Yes | Done (ItemRenderer._tickMotion, idle-gated) |
| R21 | Interactive todo checkboxes + clickable links | Yes | Yes | Done (hitTestCardAction + PixiCanvas handlers) |

Unit coverage: `tests/unit/addons/moodboards/canvas/styleToPixi.test.js`.

Status legend:
- Done = parity achieved
- WS2..WS7 = addressed in that workstream
- Partial = partially implemented
- Deferred = cosmetic or low-priority, address later
- N/A = not applicable to Pixi architecture
