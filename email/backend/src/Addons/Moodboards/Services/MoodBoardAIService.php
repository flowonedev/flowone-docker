<?php

namespace Webmail\Addons\Moodboards\Services;

use Webmail\Addons\AIAssistant\Services\AIService;

class MoodBoardAIService
{
    private AIService $ai;

    // ───── MODULAR PROMPT SYSTEM ─────
    // BASE is always sent. Specialized modules are appended based on keyword detection.

    private const PROMPT_BASE = <<<'PROMPT'
You generate moodboard layouts as a JSON array. Return ONLY a raw JSON array — no markdown, no code fences, no explanation, no text before or after.

ITEM TYPES (only two):
- "text": content, pos_x, pos_y, width, height, style_data
- "shape": pos_x, pos_y, width, height, style_data

TEXT style_data: font_family, font_size, font_weight, text_color, text_align("left"|"center"|"right"), text_padding(number), line_height(number, default 1.5 for body/badge text, 1.1 for headings), text_fill_type, text_fill_gradient:{angle,stops:[{color,position}]}
SHAPE style_data: shape_fill, shape_border_color, shape_border_width, shape_border_radius(px), shape_opacity(0-100)

ICONS: text item with font_family:"Material Symbols Rounded", content = icon name (e.g. "mail", "task_alt").

CRITICAL POSITIONING RULES:
1. ALL pos_x/pos_y are ABSOLUTE canvas coordinates (no parent containers).
2. To place a child inside a card: child_pos_x = card_pos_x + offset, child_pos_y = card_pos_y + offset.
3. To CENTER a child of width CW in a parent of width PW: child_pos_x = parent_pos_x + (PW - CW) / 2.
4. ALWAYS compute and verify centering math for EVERY child element.

ICON-IN-CIRCLE PATTERN (very common):
When placing an icon with a background circle/rounded-square:
- Circle shape: width=56, height=56, shape_border_radius=999
- Icon text: width=56, height=56, font_size=32, text_align:"center", text_padding=12
- BOTH must have the EXACT SAME pos_x and pos_y so the icon is perfectly centered in its background.
- CRITICAL COLOR RULE: The circle background uses the accent color at LOW OPACITY (shape_opacity: 15-25). The icon text uses the accent color at FULL strength (text_color same hue, no transparency). This creates a soft glow behind a vivid icon.
- To left-align icon in card: icon_pos_x = card_pos_x + 20, icon_pos_y = card_pos_y + 20.

COLOR THEMES:
- Green/dark: fill #0d1f17, border #22c55e, icon_bg #22c55e opacity 20, icon_text #22c55e, text #ffffff, muted #94a3b8
- Red/dark: fill #1c1215, border #ef4444, icon_bg #ef4444 opacity 20, icon_text #ef4444, text #ffffff, muted #94a3b8
- Blue/dark: fill #111827, border #3b82f6, icon_bg #3b82f6 opacity 20, icon_text #3b82f6, text #ffffff, muted #94a3b8
- Purple/dark: fill #1a1625, border #8b5cf6, icon_bg #8b5cf6 opacity 20, icon_text #8b5cf6, text #ffffff, muted #94a3b8
- Yellow/dark: fill #1a1708, border #eab308, icon_bg #eab308 opacity 20, icon_text #eab308, text #ffffff, muted #94a3b8
- Cyan/dark: fill #0a1a1f, border #06b6d4, icon_bg #06b6d4 opacity 20, icon_text #06b6d4, text #ffffff, muted #94a3b8
- Pink/dark: fill #1f0d18, border #ec4899, icon_bg #ec4899 opacity 20, icon_text #ec4899, text #ffffff, muted #94a3b8
- Orange/dark: fill #1c1008, border #f97316, icon_bg #f97316 opacity 20, icon_text #f97316, text #ffffff, muted #94a3b8
- Pale green: fill #f0fdf4, border #22c55e, icon_bg #22c55e opacity 15, text #166534, muted #64748b
- Pale red: fill #fef2f2, border #ef4444, icon_bg #ef4444 opacity 15, text #991b1b, muted #64748b
- Light/white: fill #ffffff, border #e2e8f0, text #1e293b, muted #64748b
- Default dark: fill #1a1a2e, text #e2e8f0, muted #94a3b8

CRITICAL: icon_bg shape_opacity is ALWAYS 15-25 (soft/transparent). icon text_color and badge text_color are ALWAYS the full-strength accent color. NEVER use full opacity on icon backgrounds.

shape_border_radius: 0=sharp, 8-12=subtle, 16-24=rounded, 999=circle/pill.
For gradient text: text_fill_type:"gradient", text_fill_gradient:{angle:90,stops:[{color,position}]}.

SIZING RULE: NEVER let text overflow its container. Always leave at least 16px padding on each side. Estimate text lines: lines = ceil(char_count / ((container_width - 32) / 7)). Each line ~18px.
PROMPT;

    private const PROMPT_CARDS = <<<'PROMPT'
TAG/BADGE PILLS (small labels at the bottom of cards):
- Each badge is a shape + text pair, placed side by side horizontally.
- Badge shape: height=24, shape_border_radius=999 (pill), shape_fill = accent color at LOW OPACITY (shape_opacity: 15-20), shape_border_width=0.
- Badge text: same pos_x/pos_y, same width/height, font_size=10, font_weight=600, text_color = accent color at FULL strength, text_align:"center", text_padding=4, line_height=1.5.
- Badge width: estimate ~8px per character + 16px padding. E.g. "IMAP" (4 chars) = 48px, "WebSocket" (9 chars) = 88px.
- Horizontal gap between badges: 8px.
- Badges sit at the bottom of the card: badge_pos_y = card_pos_y + card_height - 24 - 16 (16px bottom padding).

CARD SIZING — MEASURE YOUR CONTENT:
Before choosing card width/height, count the content that must fit inside:
- Title: 1 line, ~28px tall
- Description: estimate lines = ceil(char_count / chars_per_line). chars_per_line ~ (card_width - 40) / 7. Each line ~ 18px. Add 8px buffer.
- Badges row: 24px + 16px bottom padding = 40px
- Icon area: 56px icon + 20px top padding = 76px
- Gap between sections: 8-12px each

Formula: card_height = 20(top) + 56(icon) + 12(gap) + 28(title) + 8(gap) + desc_height + 12(gap) + 40(badges) = minimum required.
If content is long, use WIDER cards (240-280px) so text wraps less, and TALLER cards to fit everything.
Default recommended: width=240, height=300 for cards with icon + title + 2-3 line description + badges.

CARD STRUCTURE TEMPLATE (use this vertical spacing):
Given card at (cx, cy) with width CW=240, height CH=300:
1. Icon bg shape:   pos_x = cx + 20,           pos_y = cy + 20,   w=56, h=56, radius=999, shape_opacity=20
2. Icon text:       pos_x = cx + 20,           pos_y = cy + 20,   w=56, h=56  (SAME pos as bg)
3. Title text:      pos_x = cx + 20,           pos_y = cy + 88,   w=CW-40, h=28
4. Description:     pos_x = cx + 20,           pos_y = cy + 120,  w=CW-40, h=auto(fit content)
5. Badges row:      pos_y = cy + CH - 40,      each badge shape+text pair laid out horizontally from cx + 20

FULL EXAMPLE — title + subtitle + 2 cards (green/dark theme, with badges):
[
{"type":"text","pos_x":0,"pos_y":0,"width":520,"height":52,"content":"Section Title","style_data":{"font_size":40,"font_weight":"800","text_color":"#ffffff","text_align":"center","text_padding":0}},
{"type":"text","pos_x":20,"pos_y":56,"width":480,"height":28,"content":"A short subtitle.","style_data":{"font_size":16,"text_color":"#94a3b8","text_align":"center","text_padding":0}},
{"type":"shape","pos_x":0,"pos_y":110,"width":250,"height":300,"style_data":{"shape_fill":"#0d1f17","shape_border_color":"#22c55e","shape_border_width":1,"shape_border_radius":16}},
{"type":"shape","pos_x":20,"pos_y":130,"width":56,"height":56,"style_data":{"shape_fill":"#22c55e","shape_opacity":20,"shape_border_radius":999}},
{"type":"text","pos_x":20,"pos_y":130,"width":56,"height":56,"content":"key","style_data":{"font_family":"Material Symbols Rounded","font_size":32,"text_color":"#22c55e","text_align":"center","text_padding":12}},
{"type":"text","pos_x":20,"pos_y":198,"width":210,"height":28,"content":"Card Title","style_data":{"font_size":16,"font_weight":"700","text_color":"#ffffff","text_align":"left","text_padding":0}},
{"type":"text","pos_x":20,"pos_y":230,"width":210,"height":54,"content":"Card description text goes here with enough room to fit.","style_data":{"font_size":13,"text_color":"#94a3b8","text_align":"left","text_padding":0}},
{"type":"shape","pos_x":20,"pos_y":370,"width":64,"height":24,"style_data":{"shape_fill":"#22c55e","shape_opacity":18,"shape_border_radius":999}},
{"type":"text","pos_x":20,"pos_y":370,"width":64,"height":24,"content":"Admin","style_data":{"font_size":10,"font_weight":"600","text_color":"#22c55e","text_align":"center","text_padding":4,"line_height":1.5}},
{"type":"shape","pos_x":92,"pos_y":370,"width":56,"height":24,"style_data":{"shape_fill":"#22c55e","shape_opacity":18,"shape_border_radius":999}},
{"type":"text","pos_x":92,"pos_y":370,"width":56,"height":24,"content":"Root","style_data":{"font_size":10,"font_weight":"600","text_color":"#22c55e","text_align":"center","text_padding":4,"line_height":1.5}}
]

LAYOUT RULES:
- Cards in rows (default 4), gap = 20px.
- Card N in row: pos_x = N * (card_width + gap).
- New row: pos_y += card_height + gap, pos_x resets.
- Section title: full row width, placed above cards, font_size 36-48, font_weight 800.
- Subtitle: full row width, below title, font_size 14-16, text_color #94a3b8.
- Title width = total_cards * card_width + (total_cards - 1) * gap.
- When cards have DIFFERENT accent colors per card, each card's icon bg, icon text, badges, and border should all use that card's own accent color.
PROMPT;

    private const PROMPT_DASHBOARD = <<<'PROMPT'
DASHBOARD LAYOUT PATTERN:
Build a complete app UI mockup using absolute-positioned shapes and text.

CANVAS SIZE: total width ~1200, total height ~800.

SIDEBAR (left navigation):
- Background shape: pos_x=0, pos_y=0, width=220, height=800, shape_fill dark (e.g. #111827), shape_border_radius=0.
- App logo/title: text at pos_x=20, pos_y=20, font_size=18, font_weight=700.
- Nav items: stacked vertically starting at pos_y=80, each item is 44px tall, 20px gap.
  Each nav item = icon (w=24,h=24, font_size=20, Material Symbols Rounded) + label text (font_size=13) side by side.
  Icon pos_x=24, label pos_x=56, both same pos_y, label width=140.
  Active item: add a highlight shape behind it (width=190, height=40, pos_x=12, shape_fill accent at opacity 15, radius=10) and use accent color for icon+text.
  Inactive items: text_color #94a3b8, icon color #64748b.
- Bottom section (pos_y~720): user avatar circle (w=36,h=36, radius=999) + name text + settings icon.

TOPBAR (horizontal header):
- Background shape: pos_x=220, pos_y=0, width=980, height=56, shape_fill slightly lighter than page bg (e.g. #1e293b), border-bottom via shape_border_color.
- Page title: text at pos_x=244, pos_y=16, font_size=16, font_weight=600.
- Search bar: shape at pos_x=500, pos_y=12, width=300, height=32, radius=8, fill #0f172a. Placeholder text "Search..." inside.
- Right side: notification icon + user avatar at pos_x~1140.

CONTENT AREA:
- Starts at pos_x=244 (sidebar + 24px padding), pos_y=80 (topbar + 24px padding).
- Available width: ~932px.

TABLE/LIST PATTERN:
- Table container shape: full content width, radius=12, fill slightly elevated (e.g. #1e293b).
- Header row: shape height=44, slightly different fill. Column labels as text items, font_size=11, font_weight=600, text_color #64748b, uppercase.
- Data rows: height=48 each. Alternate row fills optional. Cell text font_size=13, text_color #e2e8f0.
- Columns spaced by fixed widths (e.g. 200, 150, 120, 100...).
- Row separator: thin shape (height=1, fill #334155) between rows.

TOOLBAR PATTERN:
- Horizontal bar shape: full content width, height=48, radius=12, fill elevated bg.
- Contains: filter buttons (pill shapes with text), search input shape, action button (accent-filled pill).
- Items spaced horizontally with 8-12px gaps.

EXAMPLE (sidebar + topbar + 2 stat cards):
[
{"type":"shape","pos_x":0,"pos_y":0,"width":220,"height":800,"style_data":{"shape_fill":"#111827","shape_border_radius":0}},
{"type":"text","pos_x":20,"pos_y":24,"width":180,"height":24,"content":"Dashboard","style_data":{"font_size":18,"font_weight":"700","text_color":"#ffffff","text_align":"left","text_padding":0}},
{"type":"shape","pos_x":12,"pos_y":76,"width":196,"height":40,"style_data":{"shape_fill":"#3b82f6","shape_opacity":15,"shape_border_radius":10}},
{"type":"text","pos_x":24,"pos_y":84,"width":24,"height":24,"content":"dashboard","style_data":{"font_family":"Material Symbols Rounded","font_size":20,"text_color":"#3b82f6","text_align":"center","text_padding":2}},
{"type":"text","pos_x":56,"pos_y":84,"width":140,"height":24,"content":"Overview","style_data":{"font_size":13,"font_weight":"600","text_color":"#3b82f6","text_align":"left","text_padding":0}},
{"type":"text","pos_x":24,"pos_y":128,"width":24,"height":24,"content":"people","style_data":{"font_family":"Material Symbols Rounded","font_size":20,"text_color":"#64748b","text_align":"center","text_padding":2}},
{"type":"text","pos_x":56,"pos_y":128,"width":140,"height":24,"content":"Users","style_data":{"font_size":13,"text_color":"#94a3b8","text_align":"left","text_padding":0}},
{"type":"shape","pos_x":220,"pos_y":0,"width":980,"height":56,"style_data":{"shape_fill":"#1e293b","shape_border_radius":0}},
{"type":"text","pos_x":244,"pos_y":16,"width":200,"height":24,"content":"Overview","style_data":{"font_size":16,"font_weight":"600","text_color":"#ffffff","text_align":"left","text_padding":0}},
{"type":"shape","pos_x":244,"pos_y":80,"width":220,"height":120,"style_data":{"shape_fill":"#1e293b","shape_border_radius":12}},
{"type":"text","pos_x":264,"pos_y":100,"width":180,"height":36,"content":"1,247","style_data":{"font_size":32,"font_weight":"700","text_color":"#ffffff","text_align":"left","text_padding":0}},
{"type":"text","pos_x":264,"pos_y":140,"width":180,"height":20,"content":"Total Users","style_data":{"font_size":12,"text_color":"#94a3b8","text_align":"left","text_padding":0}},
{"type":"text","pos_x":264,"pos_y":164,"width":60,"height":20,"content":"+12.5%","style_data":{"font_size":11,"font_weight":"600","text_color":"#22c55e","text_align":"left","text_padding":0}},
{"type":"shape","pos_x":484,"pos_y":80,"width":220,"height":120,"style_data":{"shape_fill":"#1e293b","shape_border_radius":12}},
{"type":"text","pos_x":504,"pos_y":100,"width":180,"height":36,"content":"$48.2K","style_data":{"font_size":32,"font_weight":"700","text_color":"#ffffff","text_align":"left","text_padding":0}},
{"type":"text","pos_x":504,"pos_y":140,"width":180,"height":20,"content":"Revenue","style_data":{"font_size":12,"text_color":"#94a3b8","text_align":"left","text_padding":0}},
{"type":"text","pos_x":504,"pos_y":164,"width":60,"height":20,"content":"+8.3%","style_data":{"font_size":11,"font_weight":"600","text_color":"#22c55e","text_align":"left","text_padding":0}}
]
PROMPT;

    private const PROMPT_LANDING = <<<'PROMPT'
LANDING PAGE / MARKETING LAYOUT PATTERNS:

HERO SECTION:
- Full width (800-1100px).
- Small eyebrow text: font_size=12, font_weight=600, uppercase, accent color, pos_y top.
- Main headline: font_size=48-64, font_weight=800, text_color white (dark theme) or #1e293b (light theme).
  For emphasis, use gradient text on the key phrase: text_fill_type:"gradient", text_fill_gradient:{angle:90,stops:[{color:"#22c55e",position:0},{color:"#06b6d4",position:100}]}.
- Subtitle: font_size=18-20, text_color muted (#94a3b8 or #64748b), width ~600px, centered below headline.
- CTA button: shape (width=200, height=48, radius=999, accent fill) + centered text (font_size=15, font_weight=700, white).
  Second CTA (ghost): shape (same size, radius=999, fill transparent, border=2 accent) + text in accent color.
- CTAs placed side by side with 16px gap, centered below subtitle.

FEATURE ROW PATTERN (alternating icon-left / icon-right):
- Row height ~200px, full content width.
- One side: large icon in circle (w=80, h=80, radius=999) or illustration placeholder shape.
- Other side: title (font_size=24, bold) + description (font_size=14, muted) + optional bullet points.
- Alternate left/right placement for visual rhythm.
- Vertical gap between rows: 60-80px.

STATS BAR (social proof / key numbers):
- Horizontal row of 3-4 stat blocks, evenly spaced.
- Each block: large number text (font_size=36-48, font_weight=800, accent color) + small label below (font_size=12, muted).
- Optional: separator lines (shape w=1, height=40) between blocks.

SECTION DIVIDER:
- Thin horizontal line shape (height=1, full width, fill #334155 dark or #e2e8f0 light) with 40-60px vertical margin.

TESTIMONIAL BLOCK:
- Quote shape background (width=400, rounded, slightly elevated fill).
- Large format_quote icon (font_size=32, accent color, opacity=40).
- Quote text: font_size=15, italic style via content, text_color white.
- Author: font_size=13, font_weight=700, below quote. Company: font_size=12, muted.

FOOTER:
- Full-width shape background (darker than page bg).
- Columns of links: 3-4 columns, each with a bold heading (font_size=12, uppercase) and stacked link texts below (font_size=13, muted color).
- Bottom bar: copyright text centered, font_size=11, muted.
PROMPT;

    private const PROMPT_STATS = <<<'PROMPT'
STAT & METRICS PATTERNS:

STAT CARD:
- Shape: width=200-240, height=120-140, rounded (radius=12), elevated fill.
- Layout inside (top-to-bottom, left-aligned with 20px padding):
  1. Label text: font_size=12, text_color muted, uppercase, font_weight=600. pos_y = card_y + 20.
  2. Big number: font_size=32-40, font_weight=700, text_color white. pos_y = card_y + 44.
  3. Trend indicator: font_size=11, font_weight=600. Green (#22c55e) for positive with "trending_up" icon, red (#ef4444) for negative with "trending_down" icon. pos_y = card_y + 88.
- Stat cards in a row: 4 across with 20px gaps.

PROGRESS BAR:
- Background shape: width=full, height=8, radius=999, fill muted (e.g. #334155).
- Foreground shape: same pos_x/pos_y, height=8, radius=999, fill accent color, width = percentage * total_width.
- Label above: "Storage Usage" font_size=12, muted.
- Value: "67%" font_size=12, font_weight=600, positioned to the right.

METRIC ROW (compact list):
- Each row: height=48, full width.
- Left: icon (w=20,h=20) + label text (font_size=13).
- Right: value text (font_size=14, font_weight=600) + optional small trend text.
- Thin separator (height=1, fill #334155) between rows.

MINI CHART PLACEHOLDER:
- Shape: width=120, height=40, radius=4, fill accent at opacity=10.
- Small text inside: "chart" to indicate sparkline placeholder.
PROMPT;

    private const MODIFY_PROMPT = <<<'PROMPT'
You modify existing moodboard items based on a user instruction. You receive the current items as JSON and must return the FULL modified array.

Return ONLY a raw JSON array — no markdown, no code fences, no explanation.

Each item has: id, type, pos_x, pos_y, width, height, content, style_data.
You MUST preserve every item's "id" field exactly as given. The id is used to map updates back.

WHAT YOU CAN CHANGE:
- style_data: any property (colors, fonts, borders, radius, opacity, etc.)
- pos_x, pos_y: reposition items
- width, height: resize items
- content: change text content

WHAT YOU MUST NOT CHANGE:
- id: never change or omit this
- type: keep original type

COMMON OPERATIONS:
- "make smaller by 20%": multiply width and height by 0.8, recalculate pos_x/pos_y to keep the group centered
- "change color to blue": update shape_fill, shape_border_color, text_color as appropriate for the type
- "increase font size": update font_size in style_data
- "add border": set shape_border_width and shape_border_color
- "make rounded": set shape_border_radius (and _tl/_tr/_bl/_br)
- "change background": update shape_fill for shapes
- "align horizontally": set all items to the same pos_y
- "space evenly": distribute items with equal gaps
- "translate to [language]": change the content of text items to the target language. NEVER translate items where font_family is "Material Symbols Rounded" — those are icon names, not text. Only translate human-readable content.

For resize operations, scale BOTH the items AND their absolute positions proportionally so the group stays together.
When resizing a group: find the group's top-left corner (minX, minY), then for each item: new_pos = minX + (old_pos - minX) * scale, new_size = old_size * scale.

SHAPE style_data keys: shape_fill, shape_border_color, shape_border_width, shape_border_radius, shape_opacity
TEXT style_data keys: font_family, font_size, font_weight, text_color, text_align, text_padding, line_height
PROMPT;

    private const VARIATIONS_PROMPT = <<<'PROMPT'
You create color variations of existing moodboard items. You receive the original items as JSON and must return N copies, each with a DIFFERENT harmonizing color scheme.

Return ONLY a raw JSON array — no markdown, no code fences, no explanation.

RULES:
- Return a flat JSON array containing ALL items across ALL variations (not nested arrays).
- Each variation is a complete copy of all input items but with different colors.
- STRIP the "id" field from every item — these are NEW items to be added.
- Keep type, width, height, content, font_family, font_size, font_weight, text_align, text_padding unchanged.
- Change ONLY color-related properties: shape_fill, shape_border_color, text_color, shape_opacity.
- Keep shape_border_width, shape_border_radius and other structural properties identical.
- NEVER change items where font_family is "Material Symbols Rounded" — icon names must stay identical, only change their text_color.
- Each variation must feel cohesive: card background, border, icon color, title color, and subtitle color should harmonize.

POSITIONING:
- All variations use the SAME pos_x/pos_y as the original items (the server will offset them).

COLOR HARMONY GUIDELINES:
- Use complementary, analogous, triadic, or split-complementary color schemes.
- Keep sufficient contrast between background and text.
- Make each variation visually distinct from the others.
- If originals are dark-themed, keep variations dark-themed (and vice versa for light).

Example palettes: blue (#3b82f6/#1e3a5f), green (#22c55e/#0d1f17), purple (#8b5cf6/#1a1625), amber (#f59e0b/#1f1508), teal (#14b8a6/#0d1f1e), pink (#ec4899/#1f0d18), indigo (#6366f1/#141438).
PROMPT;

    private const MODULE_KEYWORDS = [
        'CARDS'     => ['card', 'feature', 'grid', 'pricing', 'comparison', 'showcase', 'testimonial', 'badge', 'app'],
        'DASHBOARD' => ['dashboard', 'sidebar', 'topbar', 'navigation', 'admin', 'panel', 'toolbar', 'table', 'menu'],
        'LANDING'   => ['landing', 'hero', 'cta', 'call to action', 'website', 'page', 'section', 'headline', 'footer'],
        'STATS'     => ['stat', 'metric', 'kpi', 'number', 'chart', 'progress', 'analytics', 'trend'],
    ];

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    private function detectModules(string $userPrompt): array
    {
        $lower = mb_strtolower($userPrompt);
        $matched = [];

        foreach (self::MODULE_KEYWORDS as $module => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $matched[$module] = true;
                    break;
                }
            }
        }

        if (empty($matched)) {
            $matched['CARDS'] = true;
        }

        return array_keys($matched);
    }

    private function buildSystemPrompt(string $userPrompt): string
    {
        $modules = $this->detectModules($userPrompt);
        $prompt = self::PROMPT_BASE;

        $map = [
            'CARDS'     => self::PROMPT_CARDS,
            'DASHBOARD' => self::PROMPT_DASHBOARD,
            'LANDING'   => self::PROMPT_LANDING,
            'STATS'     => self::PROMPT_STATS,
        ];

        foreach ($modules as $mod) {
            if (isset($map[$mod])) {
                $prompt .= "\n\n" . $map[$mod];
            }
        }

        return $prompt;
    }

    public function variations(string $userPrompt, array $existingItems, int $count = 5): array
    {
        // Strip IDs from input so GPT doesn't copy them
        $stripped = array_map(function ($item) {
            unset($item['id']);
            return $item;
        }, $existingItems);

        $itemsJson = json_encode($stripped, JSON_UNESCAPED_UNICODE);
        $fullPrompt = "ORIGINAL ITEMS ({$count} variations requested):\n{$itemsJson}\n\nINSTRUCTION: {$userPrompt}\n\nReturn exactly " . (count($stripped) * $count) . " items ({$count} copies of " . count($stripped) . " items, each copy with a different color scheme).";

        $result = $this->ai->chat(self::VARIATIONS_PROMPT, $fullPrompt, [
            'max_completion_tokens' => 16000,
            'temperature' => 0.8,
            'timeout' => 120,
        ]);

        if (!$result['success']) {
            return $result;
        }

        $content = $result['content'] ?? '';
        $parsed = $this->parseJsonArray($content);

        if ($parsed === null) {
            return [
                'success' => false,
                'error' => 'AI returned invalid JSON: ' . json_last_error_msg(),
            ];
        }

        // Remove any IDs GPT may have included and normalize radius
        $items = [];
        foreach ($parsed as $item) {
            if (!is_array($item)) continue;
            unset($item['id']);
            if (!empty($item['style_data']) && is_array($item['style_data'])) {
                $item['style_data'] = $this->normalizeRadius($item['style_data']);
            }
            $items[] = $item;
        }

        if (empty($items)) {
            return ['success' => false, 'error' => 'AI returned no valid items'];
        }

        return [
            'success' => true,
            'items' => $items,
            'count' => $count,
            'items_per_set' => count($stripped),
            'usage' => $result['usage'] ?? [],
        ];
    }

    public function modify(string $userPrompt, array $existingItems, ?string $referenceImage = null): array
    {
        $itemsJson = json_encode($existingItems, JSON_UNESCAPED_UNICODE);
        $fullPrompt = "CURRENT ITEMS:\n{$itemsJson}\n\nINSTRUCTION: {$userPrompt}";

        $hasImage = !empty($referenceImage);
        $opts = [
            'max_completion_tokens' => 16000,
            'temperature' => 0.3,
            'timeout' => $hasImage ? 240 : 120,
        ];

        if ($hasImage) {
            $opts['images'] = [$referenceImage];
            $opts['image_detail'] = 'auto';
            $fullPrompt .= "\n\nI've attached a reference image. Use its colors, style, spacing, and design direction to guide the modifications.";
        }

        $systemPrompt = self::MODIFY_PROMPT . "\n\n" . $this->buildSystemPrompt($userPrompt);
        $result = $this->ai->chat($systemPrompt, $fullPrompt, $opts);

        if (!$result['success']) {
            return $result;
        }

        $content = $result['content'] ?? '';
        $parsed = $this->parseJsonArray($content);

        if ($parsed === null) {
            return [
                'success' => false,
                'error' => 'AI returned invalid JSON: ' . json_last_error_msg(),
            ];
        }

        // Validate that IDs are preserved
        $updates = [];
        foreach ($parsed as $item) {
            if (!is_array($item) || empty($item['id'])) continue;
            $updates[] = $item;
        }

        if (empty($updates)) {
            return ['success' => false, 'error' => 'AI returned no valid items with IDs'];
        }

        // Expand shape_border_radius to individual corners
        foreach ($updates as &$item) {
            if (!empty($item['style_data']) && is_array($item['style_data'])) {
                $item['style_data'] = $this->normalizeRadius($item['style_data']);
            }
        }
        unset($item);

        return [
            'success' => true,
            'items' => $updates,
            'usage' => $result['usage'] ?? [],
        ];
    }

    public function generate(string $userPrompt, ?string $referenceImage = null): array
    {
        $hasImage = !empty($referenceImage);
        $opts = [
            'max_completion_tokens' => 16000,
            'temperature' => 0.7,
            'timeout' => $hasImage ? 240 : 120,
        ];

        if ($hasImage) {
            $opts['images'] = [$referenceImage];
            $opts['image_detail'] = 'auto';
            $userPrompt .= "\n\nI've attached a reference image. Analyse its layout structure, colors, spacing, and style — then generate a moodboard layout that closely follows the same design direction.";
        }

        $systemPrompt = $this->buildSystemPrompt($userPrompt);
        $result = $this->ai->chat($systemPrompt, $userPrompt, $opts);

        if (!$result['success']) {
            return $result;
        }

        $content = $result['content'] ?? '';
        $items = $this->parseJsonArray($content);

        if (!is_array($items)) {
            return [
                'success' => false,
                'error' => 'AI returned invalid JSON: ' . json_last_error_msg(),
                'raw' => substr(trim($content), 0, 500),
            ];
        }

        $validTypes = ['text', 'shape', 'note', 'color_swatch'];
        $defaultSizes = [
            'text'  => ['w' => 300, 'h' => 40],
            'shape' => ['w' => 260, 'h' => 200],
            'note'  => ['w' => 240, 'h' => null],
            'color_swatch' => ['w' => 100, 'h' => 100],
        ];
        $sanitized = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $type = $item['type'] ?? 'text';
            if (!in_array($type, $validTypes)) $type = 'text';

            $defaults = $defaultSizes[$type] ?? ['w' => 240, 'h' => null];
            $clean = [
                'type' => $type,
                'pos_x' => (int)($item['pos_x'] ?? 0),
                'pos_y' => (int)($item['pos_y'] ?? 0),
                'width' => (int)($item['width'] ?? $defaults['w']),
            ];
            $h = $item['height'] ?? $defaults['h'];
            if ($h !== null) $clean['height'] = (int)$h;

            if (!empty($item['title'])) $clean['title'] = (string)$item['title'];
            if (!empty($item['content'])) $clean['content'] = (string)$item['content'];
            if (!empty($item['color'])) $clean['color'] = (string)$item['color'];
            if (!empty($item['style_data']) && is_array($item['style_data'])) {
                $clean['style_data'] = $this->normalizeRadius($item['style_data']);
            }

            $sanitized[] = $clean;
        }

        if (empty($sanitized)) {
            return ['success' => false, 'error' => 'AI generated no valid items'];
        }

        return [
            'success' => true,
            'items' => $sanitized,
            'usage' => $result['usage'] ?? [],
        ];
    }

    private function parseJsonArray(string $raw): ?array
    {
        $content = trim($raw);

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);
        $content = trim($content);

        $firstBracket = strpos($content, '[');
        $lastBracket = strrpos($content, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $content = substr($content, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        $items = json_decode($content, true);

        if (!is_array($items) && json_last_error() !== JSON_ERROR_NONE) {
            $fixed = preg_replace('/,\s*([\]\}])/', '$1', $content);
            $items = json_decode($fixed, true);
        }

        if (!is_array($items)) {
            error_log('[MoodBoardAI] Invalid JSON. Error: ' . json_last_error_msg() . '. Raw (first 600): ' . substr($content, 0, 600));
            return null;
        }

        return $items;
    }

    private function normalizeRadius(array $sd): array
    {
        if (isset($sd['radius_all']) && !isset($sd['shape_border_radius'])) {
            $r = (int)$sd['radius_all'];
            $sd['shape_border_radius'] = $r;
            unset($sd['radius_all']);
        }
        if (isset($sd['shape_border_radius']) && !isset($sd['shape_border_radius_tl'])) {
            $r = (int)$sd['shape_border_radius'];
            $sd['shape_border_radius_tl'] = $r;
            $sd['shape_border_radius_tr'] = $r;
            $sd['shape_border_radius_bl'] = $r;
            $sd['shape_border_radius_br'] = $r;
        }
        return $sd;
    }
}
