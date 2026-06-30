import { Node, mergeAttributes } from '@tiptap/core'

/**
 * Signature block node for the compose editor.
 *
 * The compose store wraps an applied signature in
 * `<div data-signature="true">...</div>`. StarterKit has no generic <div>
 * node, so without this extension TipTap would unwrap the container and we
 * would lose the marker on the very first render. By registering a real node
 * that parses/serialises `div[data-signature]`, the wrapper survives editing
 * round-trips, which lets the compose UI collapse/expand the signature purely
 * with CSS (`.compose-signature-collapsed [data-signature] { display:none }`).
 *
 * It only matches `div[data-signature]`, so no other content in the app is
 * affected.
 */
export const SignatureExtension = Node.create({
  name: 'signature',
  group: 'block',
  content: 'block+',
  defining: true,

  parseHTML() {
    return [{ tag: 'div[data-signature]' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, { 'data-signature': 'true' }), 0]
  },
})
