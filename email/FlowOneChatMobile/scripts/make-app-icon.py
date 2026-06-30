#!/usr/bin/env python3
"""
Generate the app icons from chatapplogo.png.

App icons (esp. iOS / apple-touch-icon) MUST be opaque (no alpha channel) and
shouldn't float in a big transparent margin, so for each target this:
  1. trims the fully-transparent border off the source artwork,
  2. scales it to fill ~86% of the canvas (a little breathing room),
  3. flattens it onto an opaque background, centered,
  4. writes a square PNG with NO alpha channel.

Outputs:
  - iOS:    ios/.../AppIcon.appiconset/AppIcon-512@2x.png  (1024px, OPAQUE white;
            Xcode derives every smaller size from this single source)
  - web:    src/public/app-icon.png                        (512px, OPAQUE white;
            favicon / PWA / apple-touch-icon, copied to dist/ by Vite)
  - in-app: src/assets/chat-logo.png                        (256px, TRANSPARENT;
            the brand mark rendered inside the app header — no white box, so it
            blends with the light/dark header background)

Run: python3 scripts/make-app-icon.py
"""
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "chatapplogo.png"

WHITE = (255, 255, 255)       # opaque background — iOS rejects icons with alpha

# (path, size, background, content_ratio)
#   background = None -> keep transparency (in-app use)
TARGETS = [
    (ROOT / "ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png", 1024, WHITE, 0.86),
    (ROOT / "src/public/app-icon.png", 512, WHITE, 0.86),
    (ROOT / "src/assets/chat-logo.png", 256, None, 0.96),
]

img = Image.open(SRC).convert("RGBA")

# Trim fully-transparent borders once (bbox of non-zero alpha).
bbox = img.getchannel("A").getbbox()
trimmed = img.crop(bbox) if bbox else img
print(f"source bbox      : {bbox}")


def render(out_path: Path, size: int, bg, content_ratio: float) -> None:
    content = int(size * content_ratio)
    w, h = trimmed.size
    scale = min(content / w, content / h)
    new_size = (max(1, round(w * scale)), max(1, round(h * scale)))
    art = trimmed.resize(new_size, Image.LANCZOS)

    x = (size - new_size[0]) // 2
    y = (size - new_size[1]) // 2
    if bg is None:
        # Transparent square (RGBA) — for in-app rendering over any background.
        canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        canvas.paste(art, (x, y), art)
    else:
        # Flatten onto an opaque, centered background; drop the alpha channel.
        canvas = Image.new("RGB", (size, size), bg)
        canvas.paste(art, (x, y), art)  # art's alpha used only as the paste mask
    out_path.parent.mkdir(parents=True, exist_ok=True)
    canvas.save(out_path, "PNG")
    print(f"wrote            : {out_path.relative_to(ROOT)} ({size}x{size}, mode={canvas.mode})")


for out_path, size, bg, ratio in TARGETS:
    render(out_path, size, bg, ratio)
