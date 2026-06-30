/**
 * Collab System - Shared Constants
 * 
 * All prefixed configuration values are defined here.
 * This file is the SINGLE SOURCE OF TRUTH for naming conventions.
 */

// Configurable prefix - ALL database tables, routes, and namespaces use this
export const COLLAB_PREFIX = process.env.COLLAB_PREFIX || 'collab_'

// WebSocket server configuration
export const COLLAB_WS_HOST = process.env.COLLAB_WS_HOST || 'localhost'
export const COLLAB_WS_PORT = parseInt(process.env.COLLAB_WS_PORT || '1234', 10)
export const COLLAB_WS_PATH = `/${COLLAB_PREFIX}ws`

// API configuration
export const COLLAB_API_PREFIX = `/api/${COLLAB_PREFIX.replace(/_$/, '')}`

// Database table names (all prefixed)
export const COLLAB_TABLES = {
  DOCUMENTS: `${COLLAB_PREFIX}documents`,
  SLIDES: `${COLLAB_PREFIX}slides`,
  PERMISSIONS: `${COLLAB_PREFIX}permissions`,
  SESSIONS: `${COLLAB_PREFIX}sessions`,
  VERSIONS: `${COLLAB_PREFIX}versions`,
  COMMENTS: `${COLLAB_PREFIX}comments`,
}

// Document types
export const COLLAB_DOC_TYPES = {
  DOCUMENT: 'document',
  PRESENTATION: 'presentation',
}

// Permission roles
export const COLLAB_ROLES = {
  OWNER: 'owner',
  EDITOR: 'editor',
  VIEWER: 'viewer',
}

// Role capabilities
export const COLLAB_ROLE_CAPABILITIES = {
  [COLLAB_ROLES.OWNER]: {
    canView: true,
    canEdit: true,
    canShare: true,
    canDelete: true,
  },
  [COLLAB_ROLES.EDITOR]: {
    canView: true,
    canEdit: true,
    canShare: false,
    canDelete: false,
  },
  [COLLAB_ROLES.VIEWER]: {
    canView: true,
    canEdit: false,
    canShare: false,
    canDelete: false,
  },
}

// Presence colors for cursor/avatar
export const COLLAB_COLORS = [
  '#F44336', // Red
  '#E91E63', // Pink
  '#9C27B0', // Purple
  '#673AB7', // Deep Purple
  '#3F51B5', // Indigo
  '#2196F3', // Blue
  '#03A9F4', // Light Blue
  '#00BCD4', // Cyan
  '#009688', // Teal
  '#4CAF50', // Green
  '#8BC34A', // Light Green
  '#FF9800', // Orange
  '#FF5722', // Deep Orange
]

// Get a deterministic color based on user email
export function getCollabUserColor(email) {
  let hash = 0
  for (let i = 0; i < email.length; i++) {
    hash = email.charCodeAt(i) + ((hash << 5) - hash)
  }
  return COLLAB_COLORS[Math.abs(hash) % COLLAB_COLORS.length]
}

// Debounce intervals (ms)
export const COLLAB_DEBOUNCE = {
  PERSIST: 2000,        // How often to persist to database
  AWARENESS: 100,       // How often to broadcast cursor position
  RECONNECT_MIN: 1000,  // Minimum reconnect delay
  RECONNECT_MAX: 30000, // Maximum reconnect delay
}

// Y.js document field names
export const COLLAB_YDOC_FIELDS = {
  // For documents
  CONTENT: 'content',           // Y.XmlFragment for TipTap
  
  // For presentations
  META: 'meta',                 // Y.Map for title, theme, etc.
  SLIDES: 'slides',             // Y.Array of slide Y.Maps
  SLIDE_OBJECTS: 'objects',     // Y.Array within each slide
}

// Presentation defaults
export const COLLAB_PRESENTATION_DEFAULTS = {
  ASPECT_RATIO: '16:9',
  SLIDE_WIDTH: 1920,
  SLIDE_HEIGHT: 1080,
  DEFAULT_THEME: 'light',
}

// Object types in presentations
export const COLLAB_OBJECT_TYPES = {
  TEXT: 'text',
  SHAPE: 'shape',
  IMAGE: 'image',
}

// Shape types
export const COLLAB_SHAPE_TYPES = {
  RECTANGLE: 'rectangle',
  ELLIPSE: 'ellipse',
  LINE: 'line',
  ARROW: 'arrow',
  TRIANGLE: 'triangle',
}
