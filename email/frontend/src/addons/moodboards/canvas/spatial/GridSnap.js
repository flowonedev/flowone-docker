/**
 * Grid snapping for item placement after drag ends.
 */

const DEFAULT_GRID_SIZE = 10

export function snapPositionToGrid(x, y, gridSize = DEFAULT_GRID_SIZE) {
  return {
    x: Math.round(x / gridSize) * gridSize,
    y: Math.round(y / gridSize) * gridSize,
  }
}

export function snapDimensionToGrid(value, gridSize = DEFAULT_GRID_SIZE) {
  return Math.round(value / gridSize) * gridSize
}

export function shouldSnapToGrid(snapGrid) {
  return !!snapGrid
}
