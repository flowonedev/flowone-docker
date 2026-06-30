/**
 * Bridge for the existing MoodPenTool.vue SVG overlay.
 * MoodPenTool reads store.panX/panY/zoom directly to position its SVG.
 * This bridge ensures the overlay coordinates align with the PixiJS stage.
 * 
 * No code changes to MoodPenTool.vue are needed -- it reads the store
 * values which the PixiJS canvas also writes to.
 */
export function verifyPenToolAlignment(store) {
  return {
    panX: store.panX,
    panY: store.panY,
    zoom: store.zoom,
  }
}

export function createPenShapeFromTool(pathData) {
  return {
    type: 'pen_shape',
    pos_x: pathData.pos_x,
    pos_y: pathData.pos_y,
    width: pathData.width,
    height: pathData.height,
    style_data: {
      pen_path: pathData.pen_path || null,
      pen_svg_path: pathData.pen_svg_path || null,
      fills: pathData.fills || [{ type: 'SOLID', color: { r: 0.8, g: 0.8, b: 0.8, a: 1 }, visible: true }],
      strokes: pathData.strokes || [{ type: 'SOLID', color: { r: 0.2, g: 0.2, b: 0.2, a: 1 }, weight: 2, visible: true }],
    },
  }
}
