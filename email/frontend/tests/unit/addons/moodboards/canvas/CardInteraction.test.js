import { describe, it, expect, vi } from 'vitest'

vi.mock('pixi.js', () => {
  const makeGraphics = () => ({
    moveTo: vi.fn().mockReturnThis(),
    lineTo: vi.fn().mockReturnThis(),
    arcTo: vi.fn().mockReturnThis(),
    closePath: vi.fn().mockReturnThis(),
    rect: vi.fn().mockReturnThis(),
    roundRect: vi.fn().mockReturnThis(),
    fill: vi.fn().mockReturnThis(),
    stroke: vi.fn().mockReturnThis(),
    addChild: vi.fn(),
    children: [],
    mask: null,
    label: '',
  })
  return {
    Graphics: vi.fn().mockImplementation(makeGraphics),
    Container: vi.fn().mockImplementation(() => ({
      addChild: vi.fn(), children: [], mask: null, label: '',
      position: { set: vi.fn() },
    })),
    Text: vi.fn().mockImplementation((opts) => ({
      ...opts, width: 50, height: 12,
      anchor: { set: vi.fn() }, position: { set: vi.fn() }, style: opts?.style || {},
    })),
    TextStyle: vi.fn().mockImplementation((opts) => opts),
    Sprite: vi.fn().mockImplementation(() => ({
      width: 0, height: 0, alpha: 1, position: { set: vi.fn() }, anchor: { set: vi.fn() },
    })),
    Texture: { from: vi.fn() },
  }
})

import { hitTestCardAction } from '@/addons/moodboards/canvas/renderer/types/CardRenderer.js'

describe('hitTestCardAction', () => {
  describe('todo_list checkboxes', () => {
    const item = {
      type: 'todo_list',
      width: 220,
      height: 160,
      title: 'My todos',
      todos: [
        { id: 1, text: 'First', completed: 0 },
        { id: 2, text: 'Second', completed: 1 },
        { id: 3, text: 'Third', completed: 0 },
      ],
    }

    it('hits the first checkbox', () => {
      // Rows start at y=32, 24px tall, checkbox at x=12..28
      const result = hitTestCardAction(item, 18, 38)
      expect(result).toEqual({ action: 'toggle-todo', todo: item.todos[0] })
    })

    it('hits the third checkbox', () => {
      const result = hitTestCardAction(item, 20, 32 + 2 * 24 + 8)
      expect(result?.todo?.id).toBe(3)
    })

    it('misses when clicking the todo text (not the checkbox)', () => {
      expect(hitTestCardAction(item, 100, 38)).toBeNull()
    })

    it('misses above the first row', () => {
      expect(hitTestCardAction(item, 18, 10)).toBeNull()
    })

    it('misses below the last row', () => {
      expect(hitTestCardAction(item, 18, 32 + 3 * 24 + 30)).toBeNull()
    })

    it('reads legacy style_data.todos as fallback', () => {
      const legacy = {
        type: 'todo_list', width: 220,
        style_data: { todos: [{ id: 9, text: 'Legacy', done: false }] },
      }
      const result = hitTestCardAction(legacy, 18, 38)
      expect(result?.todo?.id).toBe(9)
    })
  })

  describe('link URL row', () => {
    const item = {
      type: 'link',
      width: 240,
      height: 80,
      title: 'Example',
      url: 'https://example.com',
    }

    it('hits the URL row under the title', () => {
      const result = hitTestCardAction(item, 100, 38)
      expect(result).toEqual({ action: 'open-link', url: 'https://example.com' })
    })

    it('misses the icon column', () => {
      expect(hitTestCardAction(item, 20, 38)).toBeNull()
    })

    it('returns null for links without a url', () => {
      expect(hitTestCardAction({ type: 'link', width: 240, title: 'x' }, 100, 38)).toBeNull()
    })
  })

  it('returns null for non-interactive card types', () => {
    expect(hitTestCardAction({ type: 'file', width: 160 }, 20, 40)).toBeNull()
  })
})
