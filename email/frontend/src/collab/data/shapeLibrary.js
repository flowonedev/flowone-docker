/**
 * Shape Library
 * 
 * Extended shape definitions for the presentation editor.
 * Includes basic shapes, arrows, stars, callouts, and flowchart shapes.
 */

/**
 * Shape categories for organized UI display
 */
export const shapeCategories = [
  {
    id: 'icons',
    name: 'Icons',
    icon: 'interests',
    shapes: [
      // Common UI icons
      'icon_check_circle', 'icon_cancel', 'icon_add_circle', 'icon_remove_circle',
      'icon_info', 'icon_warning', 'icon_error', 'icon_help',
      // Actions
      'icon_thumb_up', 'icon_thumb_down', 'icon_favorite', 'icon_bookmark',
      'icon_lightbulb', 'icon_flag', 'icon_push_pin', 'icon_verified',
      // Objects
      'icon_rocket_launch', 'icon_target', 'icon_trophy', 'icon_emoji_events',
      'icon_workspace_premium', 'icon_military_tech', 'icon_diamond', 'icon_bolt',
      // Communication
      'icon_mail', 'icon_chat', 'icon_forum', 'icon_notifications',
      'icon_call', 'icon_videocam', 'icon_share', 'icon_link',
      // Business
      'icon_business', 'icon_work', 'icon_attach_money', 'icon_trending_up',
      'icon_analytics', 'icon_pie_chart', 'icon_bar_chart', 'icon_show_chart',
      // People
      'icon_person', 'icon_group', 'icon_diversity_3', 'icon_handshake',
      'icon_support_agent', 'icon_school', 'icon_psychology', 'icon_face',
      // Tech
      'icon_computer', 'icon_smartphone', 'icon_cloud', 'icon_settings',
      'icon_security', 'icon_lock', 'icon_key', 'icon_wifi',
      // Nature
      'icon_eco', 'icon_park', 'icon_water_drop', 'icon_sunny',
      'icon_globe', 'icon_public', 'icon_language', 'icon_explore',
    ],
  },
  {
    id: 'basic',
    name: 'Basic Shapes',
    icon: 'shapes',
    shapes: ['rectangle', 'roundedRectangle', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon'],
  },
  {
    id: 'lines',
    name: 'Lines & Connectors',
    icon: 'horizontal_rule',
    shapes: ['line', 'arrow', 'doubleArrow', 'curvedArrow'],
  },
  {
    id: 'arrows',
    name: 'Block Arrows',
    icon: 'arrow_forward',
    shapes: ['arrowRight', 'arrowLeft', 'arrowUp', 'arrowDown', 'arrowLeftRight', 'arrowUpDown', 'chevronRight'],
  },
  {
    id: 'stars',
    name: 'Stars & Banners',
    icon: 'star',
    shapes: ['star4', 'star5', 'star6', 'star8', 'burst', 'ribbon'],
  },
  {
    id: 'callouts',
    name: 'Callouts',
    icon: 'chat_bubble',
    shapes: ['calloutRectangle', 'calloutRounded', 'calloutCloud'],
  },
  {
    id: 'flowchart',
    name: 'Flowchart',
    icon: 'account_tree',
    shapes: ['process', 'decision', 'terminator', 'document', 'data'],
  },
]

/**
 * Shape definitions with metadata and SVG path generators
 */
export const shapeDefinitions = {
  // ============================================================
  // BASIC SHAPES
  // ============================================================
  
  rectangle: {
    id: 'rectangle',
    name: 'Rectangle',
    icon: 'square',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 150,
    getSvgPath: (w, h) => `M 0 0 L ${w} 0 L ${w} ${h} L 0 ${h} Z`,
    svgElement: 'path',
  },
  
  roundedRectangle: {
    id: 'roundedRectangle',
    name: 'Rounded Rectangle',
    icon: 'rounded_corner',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 150,
    getSvgPath: (w, h) => {
      const r = Math.min(w, h) * 0.15
      return `M ${r} 0 L ${w - r} 0 Q ${w} 0 ${w} ${r} L ${w} ${h - r} Q ${w} ${h} ${w - r} ${h} L ${r} ${h} Q 0 ${h} 0 ${h - r} L 0 ${r} Q 0 0 ${r} 0 Z`
    },
    svgElement: 'path',
  },
  
  ellipse: {
    id: 'ellipse',
    name: 'Ellipse',
    icon: 'circle',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 150,
    svgElement: 'ellipse',
    getAttrs: (w, h) => ({ cx: w/2, cy: h/2, rx: w/2, ry: h/2 }),
  },
  
  triangle: {
    id: 'triangle',
    name: 'Triangle',
    icon: 'change_history',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 175,
    getSvgPath: (w, h) => `M ${w/2} 0 L ${w} ${h} L 0 ${h} Z`,
    svgElement: 'path',
  },
  
  diamond: {
    id: 'diamond',
    name: 'Diamond',
    icon: 'diamond',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 200,
    getSvgPath: (w, h) => `M ${w/2} 0 L ${w} ${h/2} L ${w/2} ${h} L 0 ${h/2} Z`,
    svgElement: 'path',
  },
  
  pentagon: {
    id: 'pentagon',
    name: 'Pentagon',
    icon: 'pentagon',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 190,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const r = Math.min(w, h) / 2
      const points = []
      for (let i = 0; i < 5; i++) {
        const angle = (i * 2 * Math.PI / 5) - Math.PI / 2
        points.push(`${cx + r * Math.cos(angle)} ${cy + r * Math.sin(angle)}`)
      }
      return `M ${points.join(' L ')} Z`
    },
    svgElement: 'path',
  },
  
  hexagon: {
    id: 'hexagon',
    name: 'Hexagon',
    icon: 'hexagon',
    category: 'basic',
    defaultWidth: 200,
    defaultHeight: 175,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const r = Math.min(w, h) / 2
      const points = []
      for (let i = 0; i < 6; i++) {
        const angle = (i * 2 * Math.PI / 6) - Math.PI / 2
        points.push(`${cx + r * Math.cos(angle)} ${cy + r * Math.sin(angle)}`)
      }
      return `M ${points.join(' L ')} Z`
    },
    svgElement: 'path',
  },

  // ============================================================
  // LINES & CONNECTORS
  // ============================================================
  
  line: {
    id: 'line',
    name: 'Line',
    icon: 'horizontal_rule',
    category: 'lines',
    defaultWidth: 200,
    defaultHeight: 4,
    svgElement: 'line',
    getAttrs: (w, h) => ({ x1: 0, y1: h/2, x2: w, y2: h/2 }),
    noFill: true,
  },
  
  arrow: {
    id: 'arrow',
    name: 'Arrow',
    icon: 'arrow_forward',
    category: 'lines',
    defaultWidth: 200,
    defaultHeight: 30,
    getSvgPath: (w, h) => {
      const headSize = Math.min(h, 20)
      const shaft = h / 3
      return `M 0 ${h/2} L ${w - headSize} ${h/2} M ${w - headSize} ${h/2 - headSize/2} L ${w} ${h/2} L ${w - headSize} ${h/2 + headSize/2}`
    },
    svgElement: 'path',
    noFill: true,
  },
  
  doubleArrow: {
    id: 'doubleArrow',
    name: 'Double Arrow',
    icon: 'swap_horiz',
    category: 'lines',
    defaultWidth: 200,
    defaultHeight: 30,
    getSvgPath: (w, h) => {
      const headSize = Math.min(h, 20)
      return `M ${headSize} ${h/2 - headSize/2} L 0 ${h/2} L ${headSize} ${h/2 + headSize/2} M 0 ${h/2} L ${w} ${h/2} M ${w - headSize} ${h/2 - headSize/2} L ${w} ${h/2} L ${w - headSize} ${h/2 + headSize/2}`
    },
    svgElement: 'path',
    noFill: true,
  },
  
  curvedArrow: {
    id: 'curvedArrow',
    name: 'Curved Arrow',
    icon: 'redo',
    category: 'lines',
    defaultWidth: 200,
    defaultHeight: 100,
    getSvgPath: (w, h) => {
      const headSize = 15
      return `M 0 ${h} Q ${w/2} 0 ${w - headSize} ${h/4} M ${w - headSize - 10} ${h/4 - 10} L ${w - headSize} ${h/4} L ${w - headSize - 10} ${h/4 + 10}`
    },
    svgElement: 'path',
    noFill: true,
  },

  // ============================================================
  // BLOCK ARROWS
  // ============================================================
  
  arrowRight: {
    id: 'arrowRight',
    name: 'Arrow Right',
    icon: 'arrow_right_alt',
    category: 'arrows',
    defaultWidth: 200,
    defaultHeight: 100,
    getSvgPath: (w, h) => {
      const headWidth = w * 0.35
      const shaftHeight = h * 0.4
      const topY = (h - shaftHeight) / 2
      const bottomY = topY + shaftHeight
      return `M 0 ${topY} L ${w - headWidth} ${topY} L ${w - headWidth} 0 L ${w} ${h/2} L ${w - headWidth} ${h} L ${w - headWidth} ${bottomY} L 0 ${bottomY} Z`
    },
    svgElement: 'path',
  },
  
  arrowLeft: {
    id: 'arrowLeft',
    name: 'Arrow Left',
    icon: 'arrow_back',
    category: 'arrows',
    defaultWidth: 200,
    defaultHeight: 100,
    getSvgPath: (w, h) => {
      const headWidth = w * 0.35
      const shaftHeight = h * 0.4
      const topY = (h - shaftHeight) / 2
      const bottomY = topY + shaftHeight
      return `M ${w} ${topY} L ${headWidth} ${topY} L ${headWidth} 0 L 0 ${h/2} L ${headWidth} ${h} L ${headWidth} ${bottomY} L ${w} ${bottomY} Z`
    },
    svgElement: 'path',
  },
  
  arrowUp: {
    id: 'arrowUp',
    name: 'Arrow Up',
    icon: 'arrow_upward',
    category: 'arrows',
    defaultWidth: 100,
    defaultHeight: 200,
    getSvgPath: (w, h) => {
      const headHeight = h * 0.35
      const shaftWidth = w * 0.4
      const leftX = (w - shaftWidth) / 2
      const rightX = leftX + shaftWidth
      return `M ${leftX} ${h} L ${leftX} ${headHeight} L 0 ${headHeight} L ${w/2} 0 L ${w} ${headHeight} L ${rightX} ${headHeight} L ${rightX} ${h} Z`
    },
    svgElement: 'path',
  },
  
  arrowDown: {
    id: 'arrowDown',
    name: 'Arrow Down',
    icon: 'arrow_downward',
    category: 'arrows',
    defaultWidth: 100,
    defaultHeight: 200,
    getSvgPath: (w, h) => {
      const headHeight = h * 0.35
      const shaftWidth = w * 0.4
      const leftX = (w - shaftWidth) / 2
      const rightX = leftX + shaftWidth
      return `M ${leftX} 0 L ${rightX} 0 L ${rightX} ${h - headHeight} L ${w} ${h - headHeight} L ${w/2} ${h} L 0 ${h - headHeight} L ${leftX} ${h - headHeight} Z`
    },
    svgElement: 'path',
  },
  
  arrowLeftRight: {
    id: 'arrowLeftRight',
    name: 'Arrow Left-Right',
    icon: 'swap_horiz',
    category: 'arrows',
    defaultWidth: 250,
    defaultHeight: 100,
    getSvgPath: (w, h) => {
      const headWidth = w * 0.2
      const shaftHeight = h * 0.4
      const topY = (h - shaftHeight) / 2
      const bottomY = topY + shaftHeight
      return `M ${headWidth} 0 L 0 ${h/2} L ${headWidth} ${h} L ${headWidth} ${bottomY} L ${w - headWidth} ${bottomY} L ${w - headWidth} ${h} L ${w} ${h/2} L ${w - headWidth} 0 L ${w - headWidth} ${topY} L ${headWidth} ${topY} Z`
    },
    svgElement: 'path',
  },
  
  arrowUpDown: {
    id: 'arrowUpDown',
    name: 'Arrow Up-Down',
    icon: 'swap_vert',
    category: 'arrows',
    defaultWidth: 100,
    defaultHeight: 250,
    getSvgPath: (w, h) => {
      const headHeight = h * 0.2
      const shaftWidth = w * 0.4
      const leftX = (w - shaftWidth) / 2
      const rightX = leftX + shaftWidth
      return `M ${w/2} 0 L ${w} ${headHeight} L ${rightX} ${headHeight} L ${rightX} ${h - headHeight} L ${w} ${h - headHeight} L ${w/2} ${h} L 0 ${h - headHeight} L ${leftX} ${h - headHeight} L ${leftX} ${headHeight} L 0 ${headHeight} Z`
    },
    svgElement: 'path',
  },
  
  chevronRight: {
    id: 'chevronRight',
    name: 'Chevron',
    icon: 'chevron_right',
    category: 'arrows',
    defaultWidth: 200,
    defaultHeight: 100,
    getSvgPath: (w, h) => {
      const indent = w * 0.3
      return `M 0 0 L ${w - indent} 0 L ${w} ${h/2} L ${w - indent} ${h} L 0 ${h} L ${indent} ${h/2} Z`
    },
    svgElement: 'path',
  },

  // ============================================================
  // STARS & BANNERS
  // ============================================================
  
  star4: {
    id: 'star4',
    name: '4-Point Star',
    icon: 'star_half',
    category: 'stars',
    defaultWidth: 200,
    defaultHeight: 200,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const outerR = Math.min(w, h) / 2
      const innerR = outerR * 0.4
      return `M ${cx} 0 L ${cx + innerR * 0.7} ${cy - innerR * 0.7} L ${w} ${cy} L ${cx + innerR * 0.7} ${cy + innerR * 0.7} L ${cx} ${h} L ${cx - innerR * 0.7} ${cy + innerR * 0.7} L 0 ${cy} L ${cx - innerR * 0.7} ${cy - innerR * 0.7} Z`
    },
    svgElement: 'path',
  },
  
  star5: {
    id: 'star5',
    name: '5-Point Star',
    icon: 'star',
    category: 'stars',
    defaultWidth: 200,
    defaultHeight: 190,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const outerR = Math.min(w, h) / 2
      const innerR = outerR * 0.4
      const points = []
      for (let i = 0; i < 5; i++) {
        const outerAngle = (i * 2 * Math.PI / 5) - Math.PI / 2
        const innerAngle = outerAngle + Math.PI / 5
        points.push(`${cx + outerR * Math.cos(outerAngle)} ${cy + outerR * Math.sin(outerAngle)}`)
        points.push(`${cx + innerR * Math.cos(innerAngle)} ${cy + innerR * Math.sin(innerAngle)}`)
      }
      return `M ${points.join(' L ')} Z`
    },
    svgElement: 'path',
  },
  
  star6: {
    id: 'star6',
    name: '6-Point Star',
    icon: 'star_outline',
    category: 'stars',
    defaultWidth: 200,
    defaultHeight: 175,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const outerR = Math.min(w, h) / 2
      const innerR = outerR * 0.5
      const points = []
      for (let i = 0; i < 6; i++) {
        const outerAngle = (i * 2 * Math.PI / 6) - Math.PI / 2
        const innerAngle = outerAngle + Math.PI / 6
        points.push(`${cx + outerR * Math.cos(outerAngle)} ${cy + outerR * Math.sin(outerAngle)}`)
        points.push(`${cx + innerR * Math.cos(innerAngle)} ${cy + innerR * Math.sin(innerAngle)}`)
      }
      return `M ${points.join(' L ')} Z`
    },
    svgElement: 'path',
  },
  
  star8: {
    id: 'star8',
    name: '8-Point Star',
    icon: 'grade',
    category: 'stars',
    defaultWidth: 200,
    defaultHeight: 200,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const outerR = Math.min(w, h) / 2
      const innerR = outerR * 0.5
      const points = []
      for (let i = 0; i < 8; i++) {
        const outerAngle = (i * 2 * Math.PI / 8) - Math.PI / 2
        const innerAngle = outerAngle + Math.PI / 8
        points.push(`${cx + outerR * Math.cos(outerAngle)} ${cy + outerR * Math.sin(outerAngle)}`)
        points.push(`${cx + innerR * Math.cos(innerAngle)} ${cy + innerR * Math.sin(innerAngle)}`)
      }
      return `M ${points.join(' L ')} Z`
    },
    svgElement: 'path',
  },
  
  burst: {
    id: 'burst',
    name: 'Burst',
    icon: 'sunny',
    category: 'stars',
    defaultWidth: 200,
    defaultHeight: 200,
    getSvgPath: (w, h) => {
      const cx = w / 2, cy = h / 2
      const outerR = Math.min(w, h) / 2
      const innerR = outerR * 0.6
      const points = []
      const n = 12
      for (let i = 0; i < n; i++) {
        const outerAngle = (i * 2 * Math.PI / n) - Math.PI / 2
        const innerAngle = outerAngle + Math.PI / n
        points.push(`${cx + outerR * Math.cos(outerAngle)} ${cy + outerR * Math.sin(outerAngle)}`)
        points.push(`${cx + innerR * Math.cos(innerAngle)} ${cy + innerR * Math.sin(innerAngle)}`)
      }
      return `M ${points.join(' L ')} Z`
    },
    svgElement: 'path',
  },
  
  ribbon: {
    id: 'ribbon',
    name: 'Ribbon',
    icon: 'bookmark',
    category: 'stars',
    defaultWidth: 200,
    defaultHeight: 80,
    getSvgPath: (w, h) => {
      const indent = w * 0.1
      const fold = h * 0.3
      return `M 0 ${fold} L ${indent} 0 L ${w - indent} 0 L ${w} ${fold} L ${w} ${h} L ${w/2} ${h - fold} L 0 ${h} Z`
    },
    svgElement: 'path',
  },

  // ============================================================
  // CALLOUTS
  // ============================================================
  
  calloutRectangle: {
    id: 'calloutRectangle',
    name: 'Rectangle Callout',
    icon: 'chat_bubble',
    category: 'callouts',
    defaultWidth: 200,
    defaultHeight: 150,
    getSvgPath: (w, h) => {
      const tailHeight = h * 0.2
      const tailWidth = w * 0.15
      const tailX = w * 0.2
      const bodyHeight = h - tailHeight
      return `M 0 0 L ${w} 0 L ${w} ${bodyHeight} L ${tailX + tailWidth} ${bodyHeight} L ${tailX + tailWidth/2} ${h} L ${tailX} ${bodyHeight} L 0 ${bodyHeight} Z`
    },
    svgElement: 'path',
  },
  
  calloutRounded: {
    id: 'calloutRounded',
    name: 'Rounded Callout',
    icon: 'sms',
    category: 'callouts',
    defaultWidth: 200,
    defaultHeight: 150,
    getSvgPath: (w, h) => {
      const r = 15
      const tailHeight = h * 0.2
      const tailWidth = w * 0.15
      const tailX = w * 0.2
      const bodyHeight = h - tailHeight
      return `M ${r} 0 L ${w - r} 0 Q ${w} 0 ${w} ${r} L ${w} ${bodyHeight - r} Q ${w} ${bodyHeight} ${w - r} ${bodyHeight} L ${tailX + tailWidth} ${bodyHeight} L ${tailX + tailWidth/2} ${h} L ${tailX} ${bodyHeight} L ${r} ${bodyHeight} Q 0 ${bodyHeight} 0 ${bodyHeight - r} L 0 ${r} Q 0 0 ${r} 0 Z`
    },
    svgElement: 'path',
  },
  
  calloutCloud: {
    id: 'calloutCloud',
    name: 'Cloud Callout',
    icon: 'cloud',
    category: 'callouts',
    defaultWidth: 200,
    defaultHeight: 150,
    getSvgPath: (w, h) => {
      // Simplified cloud shape
      const bodyH = h * 0.75
      return `M ${w * 0.2} ${bodyH} Q 0 ${bodyH} 0 ${bodyH * 0.6} Q 0 ${bodyH * 0.2} ${w * 0.25} ${bodyH * 0.2} Q ${w * 0.35} 0 ${w * 0.55} ${bodyH * 0.15} Q ${w * 0.75} 0 ${w * 0.85} ${bodyH * 0.25} Q ${w} ${bodyH * 0.3} ${w} ${bodyH * 0.55} Q ${w} ${bodyH * 0.85} ${w * 0.8} ${bodyH} L ${w * 0.35} ${bodyH} L ${w * 0.25} ${h} L ${w * 0.2} ${bodyH} Z`
    },
    svgElement: 'path',
  },

  // ============================================================
  // FLOWCHART SHAPES
  // ============================================================
  
  process: {
    id: 'process',
    name: 'Process',
    icon: 'crop_square',
    category: 'flowchart',
    defaultWidth: 180,
    defaultHeight: 100,
    getSvgPath: (w, h) => `M 0 0 L ${w} 0 L ${w} ${h} L 0 ${h} Z`,
    svgElement: 'path',
  },
  
  decision: {
    id: 'decision',
    name: 'Decision',
    icon: 'diamond',
    category: 'flowchart',
    defaultWidth: 180,
    defaultHeight: 120,
    getSvgPath: (w, h) => `M ${w/2} 0 L ${w} ${h/2} L ${w/2} ${h} L 0 ${h/2} Z`,
    svgElement: 'path',
  },
  
  terminator: {
    id: 'terminator',
    name: 'Terminator',
    icon: 'radio_button_unchecked',
    category: 'flowchart',
    defaultWidth: 180,
    defaultHeight: 80,
    getSvgPath: (w, h) => {
      const r = h / 2
      return `M ${r} 0 L ${w - r} 0 A ${r} ${r} 0 0 1 ${w - r} ${h} L ${r} ${h} A ${r} ${r} 0 0 1 ${r} 0 Z`
    },
    svgElement: 'path',
  },
  
  document: {
    id: 'document',
    name: 'Document',
    icon: 'description',
    category: 'flowchart',
    defaultWidth: 180,
    defaultHeight: 120,
    getSvgPath: (w, h) => {
      const wave = h * 0.15
      return `M 0 0 L ${w} 0 L ${w} ${h - wave} Q ${w * 0.75} ${h} ${w/2} ${h - wave} Q ${w * 0.25} ${h - wave * 2} 0 ${h - wave} Z`
    },
    svgElement: 'path',
  },
  
  data: {
    id: 'data',
    name: 'Data / IO',
    icon: 'storage',
    category: 'flowchart',
    defaultWidth: 180,
    defaultHeight: 100,
    getSvgPath: (w, h) => {
      const slant = w * 0.2
      return `M ${slant} 0 L ${w} 0 L ${w - slant} ${h} L 0 ${h} Z`
    },
    svgElement: 'path',
  },

  // ============================================================
  // GOOGLE MATERIAL SYMBOLS / ICONS
  // These render as text using the Material Symbols font
  // ============================================================
  
  // Common UI icons
  icon_check_circle: { id: 'icon_check_circle', name: 'Check', icon: 'check_circle', category: 'icons', isIcon: true, iconName: 'check_circle', defaultWidth: 120, defaultHeight: 120 },
  icon_cancel: { id: 'icon_cancel', name: 'Cancel', icon: 'cancel', category: 'icons', isIcon: true, iconName: 'cancel', defaultWidth: 120, defaultHeight: 120 },
  icon_add_circle: { id: 'icon_add_circle', name: 'Add', icon: 'add_circle', category: 'icons', isIcon: true, iconName: 'add_circle', defaultWidth: 120, defaultHeight: 120 },
  icon_remove_circle: { id: 'icon_remove_circle', name: 'Remove', icon: 'remove_circle', category: 'icons', isIcon: true, iconName: 'remove_circle', defaultWidth: 120, defaultHeight: 120 },
  icon_info: { id: 'icon_info', name: 'Info', icon: 'info', category: 'icons', isIcon: true, iconName: 'info', defaultWidth: 120, defaultHeight: 120 },
  icon_warning: { id: 'icon_warning', name: 'Warning', icon: 'warning', category: 'icons', isIcon: true, iconName: 'warning', defaultWidth: 120, defaultHeight: 120 },
  icon_error: { id: 'icon_error', name: 'Error', icon: 'error', category: 'icons', isIcon: true, iconName: 'error', defaultWidth: 120, defaultHeight: 120 },
  icon_help: { id: 'icon_help', name: 'Help', icon: 'help', category: 'icons', isIcon: true, iconName: 'help', defaultWidth: 120, defaultHeight: 120 },
  
  // Actions
  icon_thumb_up: { id: 'icon_thumb_up', name: 'Thumbs Up', icon: 'thumb_up', category: 'icons', isIcon: true, iconName: 'thumb_up', defaultWidth: 120, defaultHeight: 120 },
  icon_thumb_down: { id: 'icon_thumb_down', name: 'Thumbs Down', icon: 'thumb_down', category: 'icons', isIcon: true, iconName: 'thumb_down', defaultWidth: 120, defaultHeight: 120 },
  icon_favorite: { id: 'icon_favorite', name: 'Heart', icon: 'favorite', category: 'icons', isIcon: true, iconName: 'favorite', defaultWidth: 120, defaultHeight: 120 },
  icon_bookmark: { id: 'icon_bookmark', name: 'Bookmark', icon: 'bookmark', category: 'icons', isIcon: true, iconName: 'bookmark', defaultWidth: 120, defaultHeight: 120 },
  icon_lightbulb: { id: 'icon_lightbulb', name: 'Lightbulb', icon: 'lightbulb', category: 'icons', isIcon: true, iconName: 'lightbulb', defaultWidth: 120, defaultHeight: 120 },
  icon_flag: { id: 'icon_flag', name: 'Flag', icon: 'flag', category: 'icons', isIcon: true, iconName: 'flag', defaultWidth: 120, defaultHeight: 120 },
  icon_push_pin: { id: 'icon_push_pin', name: 'Pin', icon: 'push_pin', category: 'icons', isIcon: true, iconName: 'push_pin', defaultWidth: 120, defaultHeight: 120 },
  icon_verified: { id: 'icon_verified', name: 'Verified', icon: 'verified', category: 'icons', isIcon: true, iconName: 'verified', defaultWidth: 120, defaultHeight: 120 },
  
  // Objects
  icon_rocket_launch: { id: 'icon_rocket_launch', name: 'Rocket', icon: 'rocket_launch', category: 'icons', isIcon: true, iconName: 'rocket_launch', defaultWidth: 120, defaultHeight: 120 },
  icon_target: { id: 'icon_target', name: 'Target', icon: 'my_location', category: 'icons', isIcon: true, iconName: 'my_location', defaultWidth: 120, defaultHeight: 120 },
  icon_trophy: { id: 'icon_trophy', name: 'Trophy', icon: 'emoji_events', category: 'icons', isIcon: true, iconName: 'emoji_events', defaultWidth: 120, defaultHeight: 120 },
  icon_emoji_events: { id: 'icon_emoji_events', name: 'Award', icon: 'military_tech', category: 'icons', isIcon: true, iconName: 'military_tech', defaultWidth: 120, defaultHeight: 120 },
  icon_workspace_premium: { id: 'icon_workspace_premium', name: 'Premium', icon: 'workspace_premium', category: 'icons', isIcon: true, iconName: 'workspace_premium', defaultWidth: 120, defaultHeight: 120 },
  icon_military_tech: { id: 'icon_military_tech', name: 'Medal', icon: 'military_tech', category: 'icons', isIcon: true, iconName: 'military_tech', defaultWidth: 120, defaultHeight: 120 },
  icon_diamond: { id: 'icon_diamond', name: 'Diamond', icon: 'diamond', category: 'icons', isIcon: true, iconName: 'diamond', defaultWidth: 120, defaultHeight: 120 },
  icon_bolt: { id: 'icon_bolt', name: 'Lightning', icon: 'bolt', category: 'icons', isIcon: true, iconName: 'bolt', defaultWidth: 120, defaultHeight: 120 },
  
  // Communication
  icon_mail: { id: 'icon_mail', name: 'Email', icon: 'mail', category: 'icons', isIcon: true, iconName: 'mail', defaultWidth: 120, defaultHeight: 120 },
  icon_chat: { id: 'icon_chat', name: 'Chat', icon: 'chat', category: 'icons', isIcon: true, iconName: 'chat', defaultWidth: 120, defaultHeight: 120 },
  icon_forum: { id: 'icon_forum', name: 'Forum', icon: 'forum', category: 'icons', isIcon: true, iconName: 'forum', defaultWidth: 120, defaultHeight: 120 },
  icon_notifications: { id: 'icon_notifications', name: 'Notification', icon: 'notifications', category: 'icons', isIcon: true, iconName: 'notifications', defaultWidth: 120, defaultHeight: 120 },
  icon_call: { id: 'icon_call', name: 'Phone', icon: 'call', category: 'icons', isIcon: true, iconName: 'call', defaultWidth: 120, defaultHeight: 120 },
  icon_videocam: { id: 'icon_videocam', name: 'Video', icon: 'videocam', category: 'icons', isIcon: true, iconName: 'videocam', defaultWidth: 120, defaultHeight: 120 },
  icon_share: { id: 'icon_share', name: 'Share', icon: 'share', category: 'icons', isIcon: true, iconName: 'share', defaultWidth: 120, defaultHeight: 120 },
  icon_link: { id: 'icon_link', name: 'Link', icon: 'link', category: 'icons', isIcon: true, iconName: 'link', defaultWidth: 120, defaultHeight: 120 },
  
  // Business
  icon_business: { id: 'icon_business', name: 'Building', icon: 'business', category: 'icons', isIcon: true, iconName: 'business', defaultWidth: 120, defaultHeight: 120 },
  icon_work: { id: 'icon_work', name: 'Briefcase', icon: 'work', category: 'icons', isIcon: true, iconName: 'work', defaultWidth: 120, defaultHeight: 120 },
  icon_attach_money: { id: 'icon_attach_money', name: 'Money', icon: 'attach_money', category: 'icons', isIcon: true, iconName: 'attach_money', defaultWidth: 120, defaultHeight: 120 },
  icon_trending_up: { id: 'icon_trending_up', name: 'Trending Up', icon: 'trending_up', category: 'icons', isIcon: true, iconName: 'trending_up', defaultWidth: 120, defaultHeight: 120 },
  icon_analytics: { id: 'icon_analytics', name: 'Analytics', icon: 'analytics', category: 'icons', isIcon: true, iconName: 'analytics', defaultWidth: 120, defaultHeight: 120 },
  icon_pie_chart: { id: 'icon_pie_chart', name: 'Pie Chart', icon: 'pie_chart', category: 'icons', isIcon: true, iconName: 'pie_chart', defaultWidth: 120, defaultHeight: 120 },
  icon_bar_chart: { id: 'icon_bar_chart', name: 'Bar Chart', icon: 'bar_chart', category: 'icons', isIcon: true, iconName: 'bar_chart', defaultWidth: 120, defaultHeight: 120 },
  icon_show_chart: { id: 'icon_show_chart', name: 'Line Chart', icon: 'show_chart', category: 'icons', isIcon: true, iconName: 'show_chart', defaultWidth: 120, defaultHeight: 120 },
  
  // People
  icon_person: { id: 'icon_person', name: 'Person', icon: 'person', category: 'icons', isIcon: true, iconName: 'person', defaultWidth: 120, defaultHeight: 120 },
  icon_group: { id: 'icon_group', name: 'Group', icon: 'group', category: 'icons', isIcon: true, iconName: 'group', defaultWidth: 120, defaultHeight: 120 },
  icon_diversity_3: { id: 'icon_diversity_3', name: 'Team', icon: 'diversity_3', category: 'icons', isIcon: true, iconName: 'diversity_3', defaultWidth: 120, defaultHeight: 120 },
  icon_handshake: { id: 'icon_handshake', name: 'Handshake', icon: 'handshake', category: 'icons', isIcon: true, iconName: 'handshake', defaultWidth: 120, defaultHeight: 120 },
  icon_support_agent: { id: 'icon_support_agent', name: 'Support', icon: 'support_agent', category: 'icons', isIcon: true, iconName: 'support_agent', defaultWidth: 120, defaultHeight: 120 },
  icon_school: { id: 'icon_school', name: 'Education', icon: 'school', category: 'icons', isIcon: true, iconName: 'school', defaultWidth: 120, defaultHeight: 120 },
  icon_psychology: { id: 'icon_psychology', name: 'Mind', icon: 'psychology', category: 'icons', isIcon: true, iconName: 'psychology', defaultWidth: 120, defaultHeight: 120 },
  icon_face: { id: 'icon_face', name: 'Face', icon: 'face', category: 'icons', isIcon: true, iconName: 'face', defaultWidth: 120, defaultHeight: 120 },
  
  // Tech
  icon_computer: { id: 'icon_computer', name: 'Computer', icon: 'computer', category: 'icons', isIcon: true, iconName: 'computer', defaultWidth: 120, defaultHeight: 120 },
  icon_smartphone: { id: 'icon_smartphone', name: 'Phone', icon: 'smartphone', category: 'icons', isIcon: true, iconName: 'smartphone', defaultWidth: 120, defaultHeight: 120 },
  icon_cloud: { id: 'icon_cloud', name: 'Cloud', icon: 'cloud', category: 'icons', isIcon: true, iconName: 'cloud', defaultWidth: 120, defaultHeight: 120 },
  icon_settings: { id: 'icon_settings', name: 'Settings', icon: 'settings', category: 'icons', isIcon: true, iconName: 'settings', defaultWidth: 120, defaultHeight: 120 },
  icon_security: { id: 'icon_security', name: 'Security', icon: 'security', category: 'icons', isIcon: true, iconName: 'security', defaultWidth: 120, defaultHeight: 120 },
  icon_lock: { id: 'icon_lock', name: 'Lock', icon: 'lock', category: 'icons', isIcon: true, iconName: 'lock', defaultWidth: 120, defaultHeight: 120 },
  icon_key: { id: 'icon_key', name: 'Key', icon: 'key', category: 'icons', isIcon: true, iconName: 'key', defaultWidth: 120, defaultHeight: 120 },
  icon_wifi: { id: 'icon_wifi', name: 'WiFi', icon: 'wifi', category: 'icons', isIcon: true, iconName: 'wifi', defaultWidth: 120, defaultHeight: 120 },
  
  // Nature
  icon_eco: { id: 'icon_eco', name: 'Eco', icon: 'eco', category: 'icons', isIcon: true, iconName: 'eco', defaultWidth: 120, defaultHeight: 120 },
  icon_park: { id: 'icon_park', name: 'Nature', icon: 'park', category: 'icons', isIcon: true, iconName: 'park', defaultWidth: 120, defaultHeight: 120 },
  icon_water_drop: { id: 'icon_water_drop', name: 'Water', icon: 'water_drop', category: 'icons', isIcon: true, iconName: 'water_drop', defaultWidth: 120, defaultHeight: 120 },
  icon_sunny: { id: 'icon_sunny', name: 'Sun', icon: 'sunny', category: 'icons', isIcon: true, iconName: 'sunny', defaultWidth: 120, defaultHeight: 120 },
  icon_globe: { id: 'icon_globe', name: 'Globe', icon: 'public', category: 'icons', isIcon: true, iconName: 'public', defaultWidth: 120, defaultHeight: 120 },
  icon_public: { id: 'icon_public', name: 'World', icon: 'language', category: 'icons', isIcon: true, iconName: 'language', defaultWidth: 120, defaultHeight: 120 },
  icon_language: { id: 'icon_language', name: 'Language', icon: 'translate', category: 'icons', isIcon: true, iconName: 'translate', defaultWidth: 120, defaultHeight: 120 },
  icon_explore: { id: 'icon_explore', name: 'Compass', icon: 'explore', category: 'icons', isIcon: true, iconName: 'explore', defaultWidth: 120, defaultHeight: 120 },
}

/**
 * Get all shapes in a flat list for menus
 */
export function getAllShapes() {
  return Object.values(shapeDefinitions)
}

/**
 * Get shapes grouped by category
 */
export function getShapesByCategory() {
  return shapeCategories.map(cat => ({
    ...cat,
    shapes: cat.shapes.map(id => shapeDefinitions[id]).filter(Boolean),
  }))
}

/**
 * Get shape definition by ID
 */
export function getShapeDefinition(shapeId) {
  return shapeDefinitions[shapeId] || shapeDefinitions.rectangle
}

/**
 * Generate SVG path for a shape
 */
export function getShapeSvgPath(shapeId, width, height) {
  const def = shapeDefinitions[shapeId]
  if (!def) return ''
  
  if (def.getSvgPath) {
    return def.getSvgPath(width, height)
  }
  
  return ''
}

/**
 * Get SVG attributes for shapes that use elements other than path
 */
export function getShapeSvgAttrs(shapeId, width, height) {
  const def = shapeDefinitions[shapeId]
  if (!def || !def.getAttrs) return null
  
  return def.getAttrs(width, height)
}

export default shapeDefinitions

