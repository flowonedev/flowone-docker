/**
 * useNodeStyle.js
 *
 * Reactive Vue composable that wraps the style adapter.
 * Provides Figma-native fills/strokes/effects/opacity/text as computed properties,
 * regardless of whether the underlying item uses legacy or new format.
 *
 * Usage:
 *   const ns = useNodeStyle(item, emit)
 *   // Read: ns.fills, ns.strokes, ns.effects, ns.opacity, ns.text, etc.
 *   // Write: ns.setFill(0, paint), ns.addFill(paint), ns.setOpacity(0.5), etc.
 */

import { computed } from 'vue'
import { normalizeSd, isLegacyFormat, figmaToLegacy } from '../utils/styleAdapter'
import { isFigmaFormat, PaintType, EffectType, solidPaint, dropShadow } from '../utils/figmaStyleSchema'
import { hexToFigma } from '../utils/colorConvert'
import {
  fillsToCssBackground, strokesToCssBorder, effectsToBoxShadow,
  effectsToTextShadow, effectsToFilters, cornerRadiusToCss,
  opacityToCss, blendModeToCss, textToCssStyle,
} from '../utils/cssPaintUtils'

/**
 * @param {import('vue').Ref|import('vue').ComputedRef} itemRef - reactive item object
 * @param {Function} emit - emit function for 'update' events, signature: emit('update', patch)
 */
export function useNodeStyle(itemRef, emit) {

  const normalized = computed(() => {
    const item = itemRef.value
    if (!item) return null
    return normalizeSd(item.type, item.style_data)
  })

  const fills = computed(() => normalized.value?.fills || [])
  const strokes = computed(() => normalized.value?.strokes || [])
  const effects = computed(() => normalized.value?.effects || [])
  const opacity = computed(() => normalized.value?.opacity ?? 1.0)
  const blendMode = computed(() => normalized.value?.blendMode || 'NORMAL')
  const cornerRadius = computed(() => normalized.value?.cornerRadius ?? 0)
  const rectangleCornerRadii = computed(() => normalized.value?.rectangleCornerRadii || null)
  const text = computed(() => normalized.value?.text || null)
  const strokeWeight = computed(() => normalized.value?.strokeWeight ?? 0)
  const strokeAlign = computed(() => normalized.value?.strokeAlign || 'INSIDE')
  const shapeType = computed(() => normalized.value?.shapeType || null)
  const vectorPaths = computed(() => normalized.value?.vectorPaths || null)
  const flowone = computed(() => normalized.value?._flowone || {})
  const globals = computed(() => normalized.value?._globals || {})

  const dropShadows = computed(() => effects.value.filter(e => e.type === EffectType.DROP_SHADOW))
  const textShadows = computed(() => effects.value.filter(e => e.type === EffectType.TEXT_SHADOW))
  const blurEffects = computed(() => effects.value.filter(e => e.type === EffectType.LAYER_BLUR))
  const backdropBlurs = computed(() => effects.value.filter(e => e.type === EffectType.BACKGROUND_BLUR))

  const primaryFill = computed(() => fills.value[0] || null)
  const primaryStroke = computed(() => strokes.value[0] || null)
  const primaryShadow = computed(() => dropShadows.value[0] || null)

  // ── Write helpers ──

  function commitStyleData(newSd) {
    if (!emit) return
    const item = itemRef.value
    if (!item) return

    if (isLegacyFormat(item.style_data) && !isFigmaFormat(newSd)) {
      emit('update', { style_data: newSd })
    } else if (isLegacyFormat(item.style_data)) {
      emit('update', { style_data: figmaToLegacy(item.type, newSd) })
    } else {
      emit('update', { style_data: newSd })
    }
  }

  function patchNormalized(patch) {
    const current = normalized.value
    if (!current) return
    commitStyleData({ ...current, ...patch })
  }

  function setFill(index, paint) {
    const newFills = [...fills.value]
    newFills[index] = { ...newFills[index], ...paint }
    patchNormalized({ fills: newFills })
  }

  function addFill(paint) {
    patchNormalized({ fills: [...fills.value, paint || solidPaint(0.8, 0.8, 0.8)] })
  }

  function removeFill(index) {
    const newFills = fills.value.filter((_, i) => i !== index)
    patchNormalized({ fills: newFills })
  }

  function toggleFillVisibility(index) {
    const newFills = [...fills.value]
    newFills[index] = { ...newFills[index], visible: !newFills[index].visible }
    patchNormalized({ fills: newFills })
  }

  function setStroke(index, paint) {
    const newStrokes = [...strokes.value]
    newStrokes[index] = { ...newStrokes[index], ...paint }
    patchNormalized({ strokes: newStrokes })
  }

  function addStroke(paint) {
    patchNormalized({
      strokes: [...strokes.value, paint || solidPaint(0, 0, 0)],
      strokeWeight: strokeWeight.value || 1,
    })
  }

  function removeStroke(index) {
    const newStrokes = strokes.value.filter((_, i) => i !== index)
    patchNormalized({ strokes: newStrokes })
  }

  function setStrokeWeight(weight) {
    patchNormalized({ strokeWeight: weight })
  }

  function setStrokeAlign(align) {
    patchNormalized({ strokeAlign: align })
  }

  function addEffect(effect) {
    patchNormalized({ effects: [...effects.value, effect] })
  }

  function removeEffect(index) {
    const newEffects = effects.value.filter((_, i) => i !== index)
    patchNormalized({ effects: newEffects })
  }

  function setEffect(index, effect) {
    const newEffects = [...effects.value]
    newEffects[index] = { ...newEffects[index], ...effect }
    patchNormalized({ effects: newEffects })
  }

  function toggleEffectVisibility(index) {
    const newEffects = [...effects.value]
    newEffects[index] = { ...newEffects[index], visible: !newEffects[index].visible }
    patchNormalized({ effects: newEffects })
  }

  function setOpacity(value) {
    patchNormalized({ opacity: value })
  }

  function setBlendMode(mode) {
    patchNormalized({ blendMode: mode })
  }

  function setCornerRadius(value) {
    patchNormalized({ cornerRadius: value, rectangleCornerRadii: null })
  }

  function setRectangleCornerRadii(radii) {
    patchNormalized({ rectangleCornerRadii: radii })
  }

  function setText(partial) {
    patchNormalized({ text: { ...(text.value || {}), ...partial } })
  }

  // ── CSS convenience methods ──

  function toCssVars(globalsMap, options) {
    const gm = globalsMap || globals.value
    return {
      background: fillsToCssBackground(fills.value, gm, options),
      border: strokesToCssBorder(strokes.value, strokeWeight.value, strokeAlign.value, gm, options),
      boxShadow: effectsToBoxShadow(effects.value, gm, options),
      textShadow: effectsToTextShadow(effects.value),
      ...effectsToFilters(effects.value),
      borderRadius: cornerRadiusToCss(cornerRadius.value, rectangleCornerRadii.value),
      opacity: opacityToCss(opacity.value),
      mixBlendMode: blendModeToCss(blendMode.value),
      ...textToCssStyle(text.value),
    }
  }

  return {
    normalized,
    fills, strokes, effects, opacity, blendMode,
    cornerRadius, rectangleCornerRadii,
    text, strokeWeight, strokeAlign,
    shapeType, vectorPaths, flowone, globals,

    dropShadows, textShadows, blurEffects, backdropBlurs,
    primaryFill, primaryStroke, primaryShadow,

    setFill, addFill, removeFill, toggleFillVisibility,
    setStroke, addStroke, removeStroke, setStrokeWeight, setStrokeAlign,
    addEffect, removeEffect, setEffect, toggleEffectVisibility,
    setOpacity, setBlendMode,
    setCornerRadius, setRectangleCornerRadii,
    setText,

    toCssVars,
    patchNormalized,
    commitStyleData,
  }
}
