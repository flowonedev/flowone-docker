import TextStyle from '@tiptap/extension-text-style'
import { Color } from '@tiptap/extension-color'
import FontFamily from '@tiptap/extension-font-family'
import Highlight from '@tiptap/extension-highlight'
import { mergeAttributes } from '@tiptap/core'

// TextStyle that also matches legacy <font> tags so their color/face survive
// the schema round-trip when an original email is loaded for forward/reply.
export const EmailTextStyle = TextStyle.extend({
  parseHTML() {
    return [
      {
        tag: 'span',
        getAttrs: (el) => (el.hasAttribute('style') ? {} : false),
      },
      { tag: 'font' },
    ]
  },
})

// Color that reads the inline style AND the legacy color="" attribute.
export const EmailColor = Color.extend({
  addGlobalAttributes() {
    return [
      {
        types: ['textStyle'],
        attributes: {
          color: {
            parseHTML: (el) =>
              el.style?.color?.replace(/['"]+/g, '') ||
              el.getAttribute?.('color') ||
              null,
            renderHTML: (attrs) =>
              attrs.color ? { style: `color: ${attrs.color}` } : {},
          },
        },
      },
    ]
  },
})

export const EmailFontFamily = FontFamily

// Highlight (text background color) that renders as a styled <span> instead of
// <mark>. Our backend HTMLPurifier allows span[style] + background-color but not
// <mark>, and a styled span renders consistently across email clients. parseHTML
// also matches incoming <mark> and any <span> carrying a background-color so the
// highlight survives the forward/reply round-trip.
export const EmailHighlight = Highlight.extend({
  // A <span> can only match ONE tag parse rule, and EmailTextStyle already
  // claims `span`. So background-color is parsed via a ProseMirror *style* rule
  // (which stacks on top of tag rules) — this lets a single
  // <span style="color:..;background-color:..."> carry BOTH text color and
  // highlight. <mark> (and legacy markup) is still matched by tag.
  parseHTML() {
    return [
      { tag: 'mark' },
      {
        style: 'background-color',
        getAttrs: (value) =>
          value && value !== 'transparent' ? { color: value } : false,
      },
    ]
  },
  renderHTML({ HTMLAttributes }) {
    return ['span', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes), 0]
  },
  addAttributes() {
    return {
      color: {
        default: null,
        parseHTML: (el) =>
          el.getAttribute?.('data-color') || el.style?.backgroundColor || null,
        renderHTML: (attrs) =>
          attrs.color ? { style: `background-color: ${attrs.color}` } : {},
      },
    }
  },
})
