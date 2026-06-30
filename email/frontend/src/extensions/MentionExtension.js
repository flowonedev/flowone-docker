import Mention from '@tiptap/extension-mention'
import { VueRenderer } from '@tiptap/vue-3'
import tippy from 'tippy.js'
import MentionSuggestionList from '@/components/compose/MentionSuggestionList.vue'
import { useMentionsStore } from '@/stores/mentions'

/**
 * Outlook-style @mention extension for the TipTap editor.
 *
 * Trigger:   '@'
 * Backed by: GET /mentions/suggest (via mentions store, with 60s cache)
 *
 * On commit:
 *   - TipTap inserts a `<mention>` node serialised as
 *     <span data-type="mention" data-id="email@domain" data-label="Name">@Name</span>
 *     — this is the exact shape the backend MentionParser looks for.
 *   - We also dispatch a custom DOM event `mention:committed` from the
 *     editor root so the surrounding ComposeModal can hear it and (if the
 *     user has `auto_add_mentions_to_recipients` ON) add the mailbox to
 *     the To: field. Using a DOM event (not a TipTap event) keeps the
 *     extension free of cross-component imports.
 *
 * The popup itself is a tiny Vue component (MentionSuggestionList.vue)
 * mounted inside a tippy.js singleton positioned anchored to the @-token.
 * We use tippy because it's the same library every TipTap mention demo
 * uses; it handles flip/arrow/inertia and respects window edges out of
 * the box.
 */

export function buildMentionExtension() {
  return Mention.configure({
    HTMLAttributes: {
      class: 'mention-chip',
    },
    renderHTML({ options, node }) {
      // Render as a span — that's what the backend parser matches against.
      // We intentionally do NOT use a custom tag because Outlook copies HTML
      // through MUAs that strip unknown tags, and span is universally safe.
      const id = node.attrs.id
      const label = node.attrs.label || id
      return [
        'span',
        {
          'data-type': 'mention',
          'data-id': id,
          'data-label': label,
          class: options.HTMLAttributes.class || 'mention-chip',
        },
        `@${label}`,
      ]
    },
    suggestion: {
      char: '@',

      // Returning a Promise here is supported; debounce is intrinsic to the
      // store (60s cache + AbortController-friendly).
      items: async ({ query }) => {
        const store = useMentionsStore()
        return store.suggest(query, { limit: 8 })
      },

      command: ({ editor, range, props }) => {
        // Insert the mention node, then dispatch the DOM event for the
        // compose modal. We compose-after-insert so the editor DOM is
        // already updated when ComposeModal reads it.
        editor
          .chain()
          .focus()
          .insertContentAt(range, [
            {
              type: 'mention',
              attrs: {
                id: props.email,
                label: props.name || props.email,
              },
            },
            { type: 'text', text: ' ' },
          ])
          .run()

        const root = editor.options.element
        if (root && typeof CustomEvent === 'function') {
          root.dispatchEvent(new CustomEvent('mention:committed', {
            bubbles: true,
            detail: {
              email: props.email,
              name: props.name || '',
            },
          }))
        }
      },

      render: () => {
        let component
        let popup

        return {
          onStart: (props) => {
            component = new VueRenderer(MentionSuggestionList, {
              props,
              editor: props.editor,
            })

            if (!props.clientRect) return

            popup = tippy('body', {
              getReferenceClientRect: props.clientRect,
              appendTo: () => document.body,
              content: component.element,
              showOnCreate: true,
              interactive: true,
              trigger: 'manual',
              placement: 'bottom-start',
              theme: 'flowone-mention',
            })
          },

          onUpdate(props) {
            component?.updateProps(props)
            if (!props.clientRect) return
            popup?.[0]?.setProps({ getReferenceClientRect: props.clientRect })
          },

          onKeyDown(props) {
            if (props.event.key === 'Escape') {
              popup?.[0]?.hide()
              return true
            }
            // Forward arrow keys + enter to the component so it can drive
            // the highlighted-item state.
            return component?.ref?.onKeyDown?.(props) ?? false
          },

          onExit() {
            popup?.[0]?.destroy()
            component?.destroy()
            popup = null
            component = null
          },
        }
      },
    },
  })
}
