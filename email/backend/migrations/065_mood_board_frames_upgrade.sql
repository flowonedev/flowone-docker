-- Migration 065: Upgrade frames to smart layout containers
-- Artboard type is deprecated — convert existing artboards to frames.
-- Frame style_data schema (stored in the existing JSON column):
--
-- {
--   "fill_type":       "solid" | "linear" | "radial",       -- fill mode
--   "fill_color":      "#ffffff",                            -- solid fill color
--   "fill_opacity":    1,                                    -- fill opacity 0-1
--   "fill_gradient":   { "angle": 0, "stops": [{"color": "#fff", "position": 0}, ...] },
--   "stroke_color":    "#cbd5e1",                            -- stroke color
--   "stroke_width":    1,                                    -- stroke width in px
--   "stroke_style":    "solid" | "dashed" | "dotted",       -- stroke line style
--   "stroke_position": "inside" | "center" | "outside",     -- stroke alignment
--   "radius":          0,                                    -- corner radius (uniform)
--   "radius_tl":       null,                                 -- per-corner overrides (null = use uniform)
--   "radius_tr":       null,
--   "radius_bl":       null,
--   "radius_br":       null,
--   "clip_content":    true,                                 -- overflow hidden
--   "padding":         0,                                    -- uniform padding (px)
--   "padding_top":     null,                                 -- per-side overrides
--   "padding_right":   null,
--   "padding_bottom":  null,
--   "padding_left":    null,
--   "auto_layout":     false,                                -- enable flexbox layout
--   "layout_direction": "column" | "row",                    -- flex-direction
--   "layout_gap":      0,                                    -- gap between children (px)
--   "layout_align":    "start" | "center" | "end" | "stretch", -- align-items
--   "layout_justify":  "start" | "center" | "end" | "space-between", -- justify-content
--   "layout_wrap":     false,                                -- flex-wrap
--
--   -- Frame-level sizing (auto-layout only):
--   "sizing_h":        "fixed" | "hug",                      -- frame width: fixed or hug-contents
--   "sizing_v":        "fixed" | "hug",                      -- frame height: fixed or hug-contents
--
--   -- Child-level layout props (stored on the CHILD item's style_data):
--   -- For children in auto-layout frames:
--   "sizing_h":        "fixed" | "fill" | "hug",             -- child width sizing mode
--   "sizing_v":        "fixed" | "fill" | "hug",             -- child height sizing mode
--   "align_self":      "auto" | "start" | "center" | "end" | "stretch",
--
--   -- For children in static (non-auto-layout) frames:
--   "constraint_h":    "left" | "right" | "center" | "left_right" | "scale",
--   "constraint_v":    "top" | "bottom" | "center" | "top_bottom" | "scale",
--
--   -- Universal min/max bounds (any child):
--   "min_w":           null,                                 -- minimum width (px, null = no limit)
--   "max_w":           null,                                 -- maximum width (px, null = no limit)
--   "min_h":           null,                                 -- minimum height (px, null = no limit)
--   "max_h":           null                                  -- maximum height (px, null = no limit)
-- }

-- Convert existing artboard items to frame type, preserving their style_data
UPDATE mood_board_items
SET type = 'frame',
    style_data = JSON_SET(
        COALESCE(style_data, '{}'),
        '$.fill_type', 'solid',
        '$.fill_color', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(style_data, '$.artboard_bg')), '#ffffff'),
        '$.clip_content', COALESCE(JSON_EXTRACT(style_data, '$.clip_content'), true),
        '$.legacy_artboard', true
    )
WHERE type = 'artboard';

