/**
 * HardPageBreak Extension for TipTap
 * 
 * Creates a user-insertable page break node that persists in the document.
 * This is a "hard" page break (explicit) as opposed to "soft" page breaks
 * which are calculated based on content height.
 * 
 * Keyboard shortcut: Ctrl+Enter
 * Toolbar: Insert > Page Break
 */

import { Node, mergeAttributes } from '@tiptap/core'

export const HardPageBreak = Node.create({
  name: 'hardPageBreak',

  // Block-level node that stands alone
  group: 'block',

  // Cannot contain any content
  atom: true,

  // Not selectable as text
  selectable: true,

  // Can be dragged
  draggable: true,

  addOptions() {
    return {
      HTMLAttributes: {
        class: 'hard-page-break',
      },
    }
  },

  // No attributes needed - it's just a marker
  addAttributes() {
    return {}
  },

  // Parse from HTML
  parseHTML() {
    return [
      // Match <hr class="hard-page-break">
      {
        tag: 'hr.hard-page-break',
      },
      // Match <div data-page-break="true">
      {
        tag: 'div[data-page-break="true"]',
      },
      // Match legacy format if any
      {
        tag: 'div.hard-page-break',
      },
    ]
  },

  // Render to HTML
  renderHTML({ HTMLAttributes }) {
    // Use a div with data attribute for better styling control
    return [
      'div',
      mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
        'data-page-break': 'true',
        'contenteditable': 'false',
      }),
      [
        'div',
        { class: 'hard-page-break__line' },
      ],
      [
        'span',
        { class: 'hard-page-break__label' },
        'Page Break',
      ],
      [
        'div',
        { class: 'hard-page-break__line' },
      ],
    ]
  },

  // Keyboard shortcuts
  addKeyboardShortcuts() {
    return {
      // Ctrl+Enter to insert page break
      'Mod-Enter': () => this.editor.commands.insertHardPageBreak(),
    }
  },

  // Commands
  addCommands() {
    return {
      /**
       * Insert a hard page break at the current cursor position
       */
      insertHardPageBreak: () => ({ chain, state }) => {
        const { selection } = state
        const { $from } = selection

        // Insert the page break node
        return chain()
          .insertContentAt($from.pos, {
            type: this.name,
          })
          .run()
      },

      /**
       * Remove a hard page break
       */
      removeHardPageBreak: () => ({ commands }) => {
        return commands.deleteNode(this.name)
      },

      /**
       * Set a hard page break (replace current node)
       */
      setHardPageBreak: () => ({ commands }) => {
        return commands.insertContent({
          type: this.name,
        })
      },
    }
  },

  // Node view for better interactivity (optional enhancement)
  addNodeView() {
    return ({ node, HTMLAttributes, getPos, editor }) => {
      const dom = document.createElement('div')
      dom.className = 'hard-page-break'
      dom.setAttribute('data-page-break', 'true')
      dom.setAttribute('contenteditable', 'false')

      // Left line
      const lineLeft = document.createElement('div')
      lineLeft.className = 'hard-page-break__line'

      // Label
      const label = document.createElement('span')
      label.className = 'hard-page-break__label'
      label.textContent = 'Page Break'

      // Right line
      const lineRight = document.createElement('div')
      lineRight.className = 'hard-page-break__line'

      dom.appendChild(lineLeft)
      dom.appendChild(label)
      dom.appendChild(lineRight)

      // Make it deletable with backspace/delete when selected
      dom.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' || e.key === 'Delete') {
          const pos = getPos()
          if (typeof pos === 'number') {
            editor.commands.deleteRange({
              from: pos,
              to: pos + node.nodeSize,
            })
          }
        }
      })

      return {
        dom,
        contentDOM: null, // No editable content
        ignoreMutation: () => true,
        update: (updatedNode) => {
          return updatedNode.type.name === this.name
        },
      }
    }
  },
})

export default HardPageBreak

