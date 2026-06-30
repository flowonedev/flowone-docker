# Collab Print QA Checklist

## DOC (Tiptap)
- Multi-page text with headings, lists, and blockquotes.
- Single long paragraph that crosses multiple pages.
- Mixed content with images, tables, code blocks.
- Page breaks at boundary conditions (near margin limits).
- Print preview matches on-screen preview in Chrome and Edge.

## PPT (Slides)
- Multi-slide deck with text, shapes, and images.
- Slides with scaled objects near edges.
- Print preview matches on-screen slide layout.
- Colors and backgrounds preserved in print.

## Diagnostics
- Enable diagnostics by setting `window.__COLLAB_PRINT_DEBUG__ = true`.
- Confirm logged values: `totalPages`, `pageBreakCount`, `blockCount`, `largestBlockHeight`.

