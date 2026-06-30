import { describe, it, expect } from 'vitest'
import { isWriteAction, guardAction } from '@/addons/moodboards/canvas/interaction/ReadonlyGuard.js'

describe('ReadonlyGuard', () => {
  describe('isWriteAction', () => {
    it('identifies write actions', () => {
      const writeActions = [
        'drag', 'resize', 'rotate', 'edit', 'delete', 'drop',
        'paste', 'duplicate', 'group', 'ungroup', 'nudge',
        'layer-order', 'boolean-op', 'add-item', 'connect',
        'align', 'flip', 'lock', 'unlock',
      ]
      for (const action of writeActions) {
        expect(isWriteAction(action)).toBe(true)
      }
    })

    it('identifies read-only actions', () => {
      const readActions = ['pan', 'zoom', 'select', 'present', 'follow']
      for (const action of readActions) {
        expect(isWriteAction(action)).toBe(false)
      }
    })
  })

  describe('guardAction', () => {
    it('allows all actions when not readonly', () => {
      expect(guardAction('delete', false)).toBe(true)
      expect(guardAction('drag', false)).toBe(true)
      expect(guardAction('pan', false)).toBe(true)
    })

    it('blocks write actions when readonly', () => {
      expect(guardAction('delete', true)).toBe(false)
      expect(guardAction('drag', true)).toBe(false)
      expect(guardAction('resize', true)).toBe(false)
      expect(guardAction('paste', true)).toBe(false)
    })

    it('allows read actions when readonly', () => {
      expect(guardAction('pan', true)).toBe(true)
      expect(guardAction('zoom', true)).toBe(true)
      expect(guardAction('select', true)).toBe(true)
    })
  })
})
