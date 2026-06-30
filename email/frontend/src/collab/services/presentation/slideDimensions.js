/**
 * Slide dimension helpers
 */

export const DEFAULT_ASPECT_RATIO = '16:9'
export const DEFAULT_SLIDE_WIDTH = 1920
export const DEFAULT_SLIDE_HEIGHT = 1080

const ASPECT_RATIO_DIMENSIONS = {
  '16:9': { width: 1920, height: 1080 },
  '4:3': { width: 1440, height: 1080 }
}

export function getSlideDimensions(meta = {}) {
  const aspectRatio = meta.aspectRatio || DEFAULT_ASPECT_RATIO
  const fromRatio = ASPECT_RATIO_DIMENSIONS[aspectRatio]

  if (meta.slideWidth && meta.slideHeight) {
    return {
      aspectRatio,
      width: meta.slideWidth,
      height: meta.slideHeight
    }
  }

  return {
    aspectRatio,
    width: fromRatio?.width || DEFAULT_SLIDE_WIDTH,
    height: fromRatio?.height || DEFAULT_SLIDE_HEIGHT
  }
}

