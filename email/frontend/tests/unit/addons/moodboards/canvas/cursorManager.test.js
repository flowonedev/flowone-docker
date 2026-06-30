import { describe, it, expect, beforeEach } from 'vitest'
import CursorManager from '@/addons/moodboards/canvas/utils/cursorManager.js'

describe('CursorManager', () => {
  let container
  let mgr

  beforeEach(() => {
    container = { style: { cursor: '' } }
    mgr = new CursorManager(container)
  })

  describe('set', () => {
    it('sets cursor on the container', () => {
      mgr.set('pointer')
      expect(container.style.cursor).toBe('pointer')
    })
  })

  describe('push / pop', () => {
    it('pushes a cursor and pops back', () => {
      mgr.set('default')
      mgr.push('grab')
      expect(container.style.cursor).toBe('grab')

      mgr.push('crosshair')
      expect(container.style.cursor).toBe('crosshair')

      mgr.pop()
      expect(container.style.cursor).toBe('grab')

      mgr.pop()
      expect(container.style.cursor).toBe('default')
    })

    it('pop on empty stack resets to default', () => {
      mgr.pop()
      expect(container.style.cursor).toBe('default')
    })
  })

  describe('reset', () => {
    it('clears the stack and sets default', () => {
      mgr.push('grab')
      mgr.push('move')
      mgr.reset()
      expect(container.style.cursor).toBe('default')
    })
  })

  describe('getCursorForState', () => {
    it('returns grabbing when panning', () => {
      expect(mgr.getCursorForState({ isPanning: true, spaceHeld: true })).toBe('grabbing')
    })

    it('returns grab when space is held but not panning yet', () => {
      expect(mgr.getCursorForState({ isPanning: false, spaceHeld: true })).toBe('grab')
    })

    it('returns crosshair for connection mode', () => {
      expect(mgr.getCursorForState({ connectionMode: true })).toBe('crosshair')
    })

    it('returns crosshair for line mode', () => {
      expect(mgr.getCursorForState({ lineMode: true })).toBe('crosshair')
    })

    it('returns crosshair for measure mode', () => {
      expect(mgr.getCursorForState({ measureMode: true })).toBe('crosshair')
    })

    it('returns crosshair for pen mode', () => {
      expect(mgr.getCursorForState({ penMode: true })).toBe('crosshair')
    })

    it('returns move when over an unlocked item', () => {
      expect(mgr.getCursorForState({ overItem: true, itemLocked: false })).toBe('move')
    })

    it('returns default when over a locked item', () => {
      expect(mgr.getCursorForState({ overItem: true, itemLocked: true })).toBe('default')
    })

    it('returns alias when rotating', () => {
      expect(mgr.getCursorForState({ rotating: true })).toBe('alias')
    })

    it('returns resize cursor for handles', () => {
      const cursor = mgr.getCursorForState({ resizeHandle: 'se', rotation: 0 })
      expect(cursor).toBe('nwse-resize')
    })

    it('adjusts resize cursor based on rotation', () => {
      const at0 = mgr.getCursorForState({ resizeHandle: 'n', rotation: 0 })
      const at90 = mgr.getCursorForState({ resizeHandle: 'n', rotation: 90 })
      expect(at0).toBe('ns-resize')
      expect(at90).toBe('ew-resize')
    })

    it('returns default for empty state', () => {
      expect(mgr.getCursorForState({})).toBe('default')
    })
  })
})
