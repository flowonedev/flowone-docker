/**
 * globalStylesApi.js
 *
 * API layer for global colors (design tokens) and global text styles.
 * Keeps networking out of the store per the modularity rules.
 */
import api from '@/services/api'

// ── Global Colors (design tokens) ──

export async function fetchGlobalColors(boardId) {
  const res = await api.get(`/mood-boards/${boardId}/design-tokens`)
  return res.data?.data || []
}

export async function saveGlobalColors(boardId, tokens) {
  const res = await api.put(`/mood-boards/${boardId}/design-tokens`, { tokens })
  return res.data?.success
}

export async function propagateColorByToken(boardId, tokenId, newColor) {
  const res = await api.post(`/mood-boards/${boardId}/globals/propagate-color`, {
    token_id: tokenId,
    new_color: newColor,
  })
  return res.data
}

// ── Global Text Styles ──

export async function fetchGlobalTextStyles(boardId) {
  const res = await api.get(`/mood-boards/${boardId}/global-text-styles`)
  return res.data?.data || []
}

export async function saveGlobalTextStyles(boardId, styles) {
  const res = await api.put(`/mood-boards/${boardId}/global-text-styles`, { styles })
  return res.data?.success
}

export async function propagateTextStyle(boardId, styleId, props) {
  const res = await api.post(`/mood-boards/${boardId}/globals/propagate-text-style`, {
    style_id: styleId,
    props,
  })
  return res.data
}

// ── Global CSS Classes ──

export async function fetchGlobalCssClasses(boardId) {
  const res = await api.get(`/mood-boards/${boardId}/global-css-classes`)
  return res.data?.data || []
}

export async function saveGlobalCssClasses(boardId, classes) {
  const res = await api.put(`/mood-boards/${boardId}/global-css-classes`, { classes })
  return res.data?.success
}
