/**
 * Slide Templates
 * 
 * Predefined slide layouts matching typical PowerPoint/Google Slides templates.
 * Each template defines the structure and default content for a new slide.
 */

import { v4 as uuidv4 } from 'uuid'

// Base slide dimensions (16:9)
const SLIDE_WIDTH = 1920
const SLIDE_HEIGHT = 1080

/**
 * Template definitions - Standard PPT layouts
 */
export const slideTemplates = {
  // ============================================================
  // BLANK
  // ============================================================
  blank: {
    id: 'blank',
    name: 'Blank',
    description: 'Empty slide',
    icon: 'crop_square',
    objects: [],
  },

  // ============================================================
  // TITLE SLIDE - Centered title with subtitle
  // ============================================================
  title: {
    id: 'title',
    name: 'Title slide',
    description: 'Centered title with subtitle',
    icon: 'title',
    objects: [
      {
        type: 'text',
        x: 120,
        y: 380,
        width: 1680,
        height: 150,
        content: '<p style="text-align: center;">Click to add title</p>',
        fontSize: 60,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'center',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 240,
        y: 550,
        width: 1440,
        height: 80,
        content: '<p style="text-align: center;">Click to add subtitle</p>',
        fontSize: 28,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#6b7280',
        textAlign: 'center',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // SECTION HEADER - Simple section divider
  // ============================================================
  sectionHeader: {
    id: 'sectionHeader',
    name: 'Section header',
    description: 'Section title centered',
    icon: 'view_headline',
    objects: [
      {
        type: 'text',
        x: 120,
        y: 460,
        width: 1680,
        height: 120,
        content: '<p style="text-align: center;">Click to add title</p>',
        fontSize: 48,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'center',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // TITLE AND BODY - Standard content slide
  // ============================================================
  titleBody: {
    id: 'titleBody',
    name: 'Title and body',
    description: 'Title with content area below',
    icon: 'view_agenda',
    objects: [
      {
        type: 'text',
        x: 80,
        y: 60,
        width: 1760,
        height: 90,
        content: '<p>Click to add title</p>',
        fontSize: 40,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 80,
        y: 180,
        width: 1760,
        height: 820,
        content: '<p>Click to add text</p>',
        fontSize: 24,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#374151',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // TITLE AND TWO COLUMNS
  // ============================================================
  twoColumn: {
    id: 'twoColumn',
    name: 'Title and two columns',
    description: 'Title with two content columns',
    icon: 'view_column',
    objects: [
      {
        type: 'text',
        x: 80,
        y: 60,
        width: 1760,
        height: 90,
        content: '<p>Click to add title</p>',
        fontSize: 40,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 80,
        y: 180,
        width: 840,
        height: 820,
        content: '<p>Click to add text</p>',
        fontSize: 24,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#374151',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 1000,
        y: 180,
        width: 840,
        height: 820,
        content: '<p>Click to add text</p>',
        fontSize: 24,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#374151',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // TITLE ONLY
  // ============================================================
  titleOnly: {
    id: 'titleOnly',
    name: 'Title only',
    description: 'Just a title at the top',
    icon: 'short_text',
    objects: [
      {
        type: 'text',
        x: 80,
        y: 60,
        width: 1760,
        height: 90,
        content: '<p>Click to add title</p>',
        fontSize: 40,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // ONE COLUMN TEXT
  // ============================================================
  oneColumn: {
    id: 'oneColumn',
    name: 'One-column text',
    description: 'Single text area',
    icon: 'subject',
    objects: [
      {
        type: 'text',
        x: 80,
        y: 60,
        width: 1760,
        height: 90,
        content: '<p>Click to add title</p>',
        fontSize: 40,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'shape',
        shapeType: 'rectangle',
        x: 80,
        y: 180,
        width: 600,
        height: 600,
        fill: '#e5e7eb',
        stroke: 'transparent',
        strokeWidth: 0,
      },
      {
        type: 'text',
        x: 760,
        y: 180,
        width: 1080,
        height: 820,
        content: '<p>Click to add text</p>',
        fontSize: 24,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#374151',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // MAIN POINT
  // ============================================================
  mainPoint: {
    id: 'mainPoint',
    name: 'Main point',
    description: 'Large centered text for key message',
    icon: 'format_size',
    objects: [
      {
        type: 'text',
        x: 120,
        y: 350,
        width: 1680,
        height: 200,
        content: '<p>Click to add title</p>',
        fontSize: 56,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 120,
        y: 570,
        width: 1680,
        height: 100,
        content: '<p>Click to add subtitle</p>',
        fontSize: 24,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#6b7280',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // SECTION TITLE AND DESCRIPTION
  // ============================================================
  sectionDesc: {
    id: 'sectionDesc',
    name: 'Section title and description',
    description: 'Section header with description',
    icon: 'article',
    objects: [
      {
        type: 'text',
        x: 120,
        y: 300,
        width: 800,
        height: 120,
        content: '<p>Click to add title</p>',
        fontSize: 44,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 120,
        y: 440,
        width: 800,
        height: 300,
        content: '<p>Click to add text</p>',
        fontSize: 20,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#6b7280',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'shape',
        shapeType: 'rectangle',
        x: 1020,
        y: 200,
        width: 780,
        height: 680,
        fill: '#e5e7eb',
        stroke: 'transparent',
        strokeWidth: 0,
      },
    ],
  },

  // ============================================================
  // CAPTION
  // ============================================================
  caption: {
    id: 'caption',
    name: 'Caption',
    description: 'Image area with caption below',
    icon: 'image',
    objects: [
      {
        type: 'shape',
        shapeType: 'rectangle',
        x: 80,
        y: 60,
        width: 1760,
        height: 800,
        fill: '#e5e7eb',
        stroke: 'transparent',
        strokeWidth: 0,
      },
      {
        type: 'text',
        x: 80,
        y: 900,
        width: 1760,
        height: 120,
        content: '<p>Click to add text</p>',
        fontSize: 20,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#6b7280',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },

  // ============================================================
  // BIG NUMBER
  // ============================================================
  bigNumber: {
    id: 'bigNumber',
    name: 'Big number',
    description: 'Large statistic with description',
    icon: 'pin',
    objects: [
      {
        type: 'text',
        x: 120,
        y: 300,
        width: 1680,
        height: 250,
        content: '<p>xx%</p>',
        fontSize: 144,
        fontFamily: 'Inter',
        fontWeight: 'bold',
        color: '#1f2937',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
      {
        type: 'text',
        x: 120,
        y: 560,
        width: 1200,
        height: 100,
        content: '<p>Click to add text</p>',
        fontSize: 24,
        fontFamily: 'Inter',
        fontWeight: 'normal',
        color: '#6b7280',
        textAlign: 'left',
        backgroundColor: 'transparent',
      },
    ],
  },
}

/**
 * Get template list for UI display
 */
export function getTemplateList() {
  return Object.values(slideTemplates).map(t => ({
    id: t.id,
    name: t.name,
    description: t.description,
    icon: t.icon,
  }))
}

/**
 * Create slide objects from a template
 * @param {string} templateId - Template ID
 * @returns {Array} Array of object data with unique IDs
 */
export function createObjectsFromTemplate(templateId) {
  const template = slideTemplates[templateId]
  if (!template) {
    console.warn(`Template "${templateId}" not found, using blank`)
    return []
  }

  // Deep clone and assign unique IDs
  return template.objects.map((obj, index) => ({
    ...obj,
    id: uuidv4(),
    zIndex: index,
  }))
}

/**
 * Get template thumbnail preview (simplified objects for rendering)
 */
export function getTemplateThumbnail(templateId) {
  const template = slideTemplates[templateId]
  if (!template) return null
  
  return {
    id: template.id,
    name: template.name,
    objects: template.objects,
  }
}

// ============================================================
// CUSTOM TEMPLATES (localStorage-based)
// ============================================================

const CUSTOM_TEMPLATES_KEY = 'collab_custom_slide_templates'

/**
 * Get all custom templates from localStorage
 */
export function getCustomTemplates() {
  try {
    const stored = localStorage.getItem(CUSTOM_TEMPLATES_KEY)
    if (!stored) return []
    return JSON.parse(stored)
  } catch (e) {
    console.error('Failed to load custom templates:', e)
    return []
  }
}

/**
 * Save a slide as a custom template
 * @param {string} name - Template name
 * @param {Array} objects - Slide objects to save
 * @param {Object} options - Additional options (icon, description, background)
 * @returns {Object} The saved template
 */
export function saveCustomTemplate(name, objects, options = {}) {
  const templates = getCustomTemplates()
  
  const newTemplate = {
    id: `custom_${uuidv4()}`,
    name: name || 'Custom Template',
    description: options.description || 'Custom slide template',
    icon: options.icon || 'style',
    isCustom: true,
    createdAt: new Date().toISOString(),
    // Store background if provided
    background: options.background || null,
    // Deep clone objects and strip IDs (new IDs will be generated when used)
    objects: objects.map(obj => {
      const { id, ...rest } = obj
      return rest
    }),
  }
  
  templates.push(newTemplate)
  
  try {
    localStorage.setItem(CUSTOM_TEMPLATES_KEY, JSON.stringify(templates))
  } catch (e) {
    console.error('Failed to save custom template:', e)
    throw new Error('Failed to save template')
  }
  
  return newTemplate
}

/**
 * Delete a custom template
 * @param {string} templateId - Template ID to delete
 */
export function deleteCustomTemplate(templateId) {
  const templates = getCustomTemplates()
  const filtered = templates.filter(t => t.id !== templateId)
  
  try {
    localStorage.setItem(CUSTOM_TEMPLATES_KEY, JSON.stringify(filtered))
    return true
  } catch (e) {
    console.error('Failed to delete custom template:', e)
    return false
  }
}

/**
 * Get combined list of built-in and custom templates
 */
export function getAllTemplates() {
  const builtIn = getTemplateList()
  const custom = getCustomTemplates().map(t => ({
    id: t.id,
    name: t.name,
    description: t.description,
    icon: t.icon,
    isCustom: true,
  }))
  
  return { builtIn, custom }
}

/**
 * Create objects from a custom template
 * @param {string} templateId - Custom template ID
 * @returns {Object} Object containing { objects: Array, background: Object|null }
 */
export function createObjectsFromCustomTemplate(templateId) {
  const templates = getCustomTemplates()
  const template = templates.find(t => t.id === templateId)
  
  if (!template) {
    console.warn(`Custom template "${templateId}" not found`)
    return { objects: [], background: null }
  }
  
  // Deep clone and assign unique IDs
  const objects = template.objects.map((obj, index) => ({
    ...obj,
    id: uuidv4(),
    zIndex: index,
  }))
  
  return {
    objects,
    background: template.background || null,
  }
}

export default slideTemplates
