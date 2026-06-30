import Image from '@tiptap/extension-image'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import ResizableImage from '@/components/ResizableImage.vue'

export const ResizableImageExtension = Image.extend({
  name: 'resizableImage',

  addAttributes() {
    return {
      ...this.parent?.(),
      width: {
        default: null,
        parseHTML: element => {
          const width = element.getAttribute('width') || element.style.width
          return width ? parseInt(width, 10) : null
        },
        renderHTML: attributes => {
          if (!attributes.width || attributes.sizing === 'auto') {
            return {}
          }
          return {
            width: attributes.width,
          }
        },
      },
      height: {
        default: null,
        parseHTML: element => {
          const height = element.getAttribute('height') || element.style.height
          return height ? parseInt(height, 10) : null
        },
        renderHTML: attributes => {
          if (!attributes.height || attributes.sizing === 'auto') {
            return {}
          }
          return {
            height: attributes.height,
          }
        },
      },
      sizing: {
        default: 'auto',
        parseHTML: element => {
          // Detect sizing mode from existing HTML:
          // If explicit pixel width is set and no max-width:100% → fixed
          const hasExplicitWidth = element.getAttribute('width') || 
            (element.style.width && element.style.width.endsWith('px'))
          const hasMaxWidth = element.style.maxWidth === '100%'
          
          if (hasExplicitWidth && !hasMaxWidth) return 'fixed'
          return element.getAttribute('data-sizing') || 'auto'
        },
        renderHTML: attributes => {
          if (attributes.sizing === 'fixed' && attributes.width) {
            return {
              'data-sizing': 'fixed',
              style: `width:${attributes.width}px${attributes.height ? ';height:' + attributes.height + 'px' : ''}`,
            }
          }
          // Auto/responsive: constrain to container
          return {
            'data-sizing': 'auto',
            style: 'max-width:100%;height:auto;width:100%;',
          }
        },
      },
    }
  },

  addNodeView() {
    return VueNodeViewRenderer(ResizableImage)
  },
})

export default ResizableImageExtension
