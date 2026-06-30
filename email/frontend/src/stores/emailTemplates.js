import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useEmailTemplatesStore = defineStore('emailTemplates', () => {
  const templates = ref([])
  const loading = ref(false)
  const saving = ref(false)

  // Built-in blocks (always available, no backend needed)
  const builtinBlocks = [
    {
      id: 'builtin-text',
      name: 'Text Block',
      description: 'Simple paragraph text',
      category: 'text',
      icon: 'notes',
      is_builtin: true,
      html_content: '<p>Type your content here...</p>',
    },
    {
      id: 'builtin-heading-text',
      name: 'Heading + Text',
      description: 'Title with paragraph text below',
      category: 'text',
      icon: 'title',
      is_builtin: true,
      html_content: '<h2>Section Title</h2><p>Add your content below the heading. This block is great for introducing a new section of your email.</p>',
    },
    {
      id: 'builtin-image-left',
      name: 'Image Left + Text',
      description: 'Image on left, text on right',
      category: 'media',
      icon: 'image',
      is_builtin: true,
      html_content: `<table class="editor-table" style="width:100%;border:none;border-collapse:collapse"><tbody><tr><td style="width:40%;vertical-align:top;padding:8px;border:none"><p><img src="https://placehold.co/280x200/e2e8f0/64748b?text=Image" alt="Image" style="max-width:100%;border-radius:8px" /></p></td><td style="width:60%;vertical-align:top;padding:8px;border:none"><h3>Your Heading</h3><p>Describe the image or add supporting text here. This layout works well for product features, team bios, or announcements.</p></td></tr></tbody></table>`,
    },
    {
      id: 'builtin-image-right',
      name: 'Text + Image Right',
      description: 'Text on left, image on right',
      category: 'media',
      icon: 'image',
      is_builtin: true,
      html_content: `<table class="editor-table" style="width:100%;border:none;border-collapse:collapse"><tbody><tr><td style="width:60%;vertical-align:top;padding:8px;border:none"><h3>Your Heading</h3><p>Describe the image or add supporting text here. This layout works well for product features, team bios, or announcements.</p></td><td style="width:40%;vertical-align:top;padding:8px;border:none"><p><img src="https://placehold.co/280x200/e2e8f0/64748b?text=Image" alt="Image" style="max-width:100%;border-radius:8px" /></p></td></tr></tbody></table>`,
    },
    {
      id: 'builtin-full-image',
      name: 'Full Width Image',
      description: 'Full-width image with optional caption',
      category: 'media',
      icon: 'panorama',
      is_builtin: true,
      html_content: `<p style="text-align:center"><img src="https://placehold.co/600x300/e2e8f0/64748b?text=Full+Width+Image" alt="Full width image" style="max-width:100%;border-radius:8px" /></p><p style="text-align:center"><em>Add your caption here</em></p>`,
    },
    {
      id: 'builtin-cta',
      name: 'Call to Action',
      description: 'Centered button with optional text',
      category: 'cta',
      icon: 'ads_click',
      is_builtin: true,
      html_content: `<div style="text-align:center;padding:24px 16px"><h2 style="margin-bottom:8px">Ready to get started?</h2><p style="margin-bottom:16px;color:#64748b">Take the next step and explore what we have to offer.</p><a href="https://example.com" style="display:inline-block;padding:12px 32px;background:#6366f1;color:#ffffff;text-decoration:none;border-radius:9999px;font-weight:600;font-size:16px">Get Started</a></div>`,
    },
    {
      id: 'builtin-two-columns',
      name: 'Two Columns',
      description: 'Side-by-side text columns',
      category: 'layout',
      icon: 'view_column',
      is_builtin: true,
      html_content: `<table class="editor-table" style="width:100%;border:none;border-collapse:collapse"><tbody><tr><td style="width:50%;vertical-align:top;padding:8px;border:none"><h3>Column One</h3><p>Add content for the left column. Great for comparisons, features, or side-by-side information.</p></td><td style="width:50%;vertical-align:top;padding:8px;border:none"><h3>Column Two</h3><p>Add content for the right column. Keep both columns roughly the same length for the best visual result.</p></td></tr></tbody></table>`,
    },
    {
      id: 'builtin-three-columns',
      name: 'Three Columns',
      description: 'Three equal columns',
      category: 'layout',
      icon: 'view_week',
      is_builtin: true,
      html_content: `<table class="editor-table" style="width:100%;border:none;border-collapse:collapse"><tbody><tr><td style="width:33%;vertical-align:top;padding:8px;border:none"><h3>Column 1</h3><p>First column content.</p></td><td style="width:33%;vertical-align:top;padding:8px;border:none"><h3>Column 2</h3><p>Second column content.</p></td><td style="width:34%;vertical-align:top;padding:8px;border:none"><h3>Column 3</h3><p>Third column content.</p></td></tr></tbody></table>`,
    },
    {
      id: 'builtin-divider',
      name: 'Divider',
      description: 'Horizontal line separator',
      category: 'layout',
      icon: 'horizontal_rule',
      is_builtin: true,
      html_content: '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0" />',
    },
    {
      id: 'builtin-quote',
      name: 'Quote / Testimonial',
      description: 'Styled blockquote with attribution',
      category: 'text',
      icon: 'format_quote',
      is_builtin: true,
      html_content: `<blockquote style="border-left:4px solid #6366f1;padding:12px 16px;margin:16px 0;background:#f8fafc;border-radius:0 8px 8px 0"><p style="font-size:16px;font-style:italic;margin-bottom:8px">"This is a great product that has transformed our workflow completely."</p><p style="font-size:14px;color:#64748b;margin:0"><strong>Jane Doe</strong> - CEO, Example Corp</p></blockquote>`,
    },
    {
      id: 'builtin-feature-grid',
      name: 'Feature Grid (2x2)',
      description: 'Four features in a grid layout',
      category: 'layout',
      icon: 'grid_view',
      is_builtin: true,
      html_content: `<table class="editor-table" style="width:100%;border:none;border-collapse:collapse"><tbody><tr><td style="width:50%;vertical-align:top;padding:12px;border:none;text-align:center"><p style="font-size:32px;margin-bottom:4px">&#9889;</p><h3 style="margin:0 0 4px">Fast</h3><p style="color:#64748b;font-size:14px">Lightning-quick performance</p></td><td style="width:50%;vertical-align:top;padding:12px;border:none;text-align:center"><p style="font-size:32px;margin-bottom:4px">&#128274;</p><h3 style="margin:0 0 4px">Secure</h3><p style="color:#64748b;font-size:14px">Enterprise-grade security</p></td></tr><tr><td style="width:50%;vertical-align:top;padding:12px;border:none;text-align:center"><p style="font-size:32px;margin-bottom:4px">&#128640;</p><h3 style="margin:0 0 4px">Scalable</h3><p style="color:#64748b;font-size:14px">Grows with your team</p></td><td style="width:50%;vertical-align:top;padding:12px;border:none;text-align:center"><p style="font-size:32px;margin-bottom:4px">&#10024;</p><h3 style="margin:0 0 4px">Simple</h3><p style="color:#64748b;font-size:14px">Easy to get started</p></td></tr></tbody></table>`,
    },
  ]

  // All blocks: builtin + custom
  const allBlocks = computed(() => {
    return [...builtinBlocks, ...templates.value]
  })

  // Grouped by category
  const groupedBlocks = computed(() => {
    const groups = {}
    const categoryLabels = {
      text: 'Text',
      media: 'Media',
      layout: 'Layout',
      cta: 'Call to Action',
      custom: 'Custom Templates',
    }

    for (const block of allBlocks.value) {
      const cat = block.category || 'custom'
      if (!groups[cat]) {
        groups[cat] = {
          label: categoryLabels[cat] || cat,
          blocks: [],
        }
      }
      groups[cat].blocks.push(block)
    }

    return groups
  })

  async function fetchTemplates() {
    loading.value = true
    try {
      const response = await api.get('/email-templates')
      if (response.data.success) {
        templates.value = response.data.data.templates || []
      }
    } catch (e) {
      console.error('Failed to fetch email templates:', e)
    } finally {
      loading.value = false
    }
  }

  async function createTemplate(data) {
    saving.value = true
    try {
      const response = await api.post('/email-templates', data)
      if (response.data.success) {
        const newTemplate = response.data.data.template
        templates.value.push(newTemplate)
        return { success: true, template: newTemplate }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('Failed to create email template:', e)
      return { success: false, error: e.response?.data?.error || e.message }
    } finally {
      saving.value = false
    }
  }

  async function updateTemplate(id, data) {
    saving.value = true
    try {
      const response = await api.put(`/email-templates/${id}`, data)
      if (response.data.success) {
        const updated = response.data.data.template
        const idx = templates.value.findIndex(t => t.id === id)
        if (idx !== -1) templates.value[idx] = updated
        return { success: true, template: updated }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('Failed to update email template:', e)
      return { success: false, error: e.response?.data?.error || e.message }
    } finally {
      saving.value = false
    }
  }

  async function deleteTemplate(id) {
    try {
      const response = await api.delete(`/email-templates/${id}`)
      if (response.data.success) {
        templates.value = templates.value.filter(t => t.id !== id)
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('Failed to delete email template:', e)
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function reorderTemplates(order) {
    try {
      await api.post('/email-templates/reorder', { order })
      return { success: true }
    } catch (e) {
      console.error('Failed to reorder templates:', e)
      return { success: false }
    }
  }

  return {
    templates,
    loading,
    saving,
    builtinBlocks,
    allBlocks,
    groupedBlocks,
    fetchTemplates,
    createTemplate,
    updateTemplate,
    deleteTemplate,
    reorderTemplates,
  }
})

