/**
 * Tests for the DOM-based email content processor.
 *
 * Regression focus: the Instagram rendering bug where serialized-HTML regex
 * post-processing captured `&amp;` entities from attribute values and baked
 * `%26amp%3B` into proxied image URLs, breaking CDN signature validation and
 * shredding the email layout into vertical alt-text strips.
 */
import { describe, it, expect } from 'vitest'
import { processEmailContent } from '@/services/emailContentProcessor'

describe('processEmailContent', () => {
  describe('image proxying', () => {
    it('proxies remote images with correctly decoded query strings (no %26amp%3B)', () => {
      const src = 'https://scontent.cdninstagram.com/v/photo.jpg?stp=dst-jpg_s206x206&_nc_cat=1&oh=00_Af-sig&oe=6A301385'
      const html = `<img src="https://scontent.cdninstagram.com/v/photo.jpg?stp=dst-jpg_s206x206&amp;_nc_cat=1&amp;oh=00_Af-sig&amp;oe=6A301385" width="64" height="64">`

      const out = processEmailContent(html)

      expect(out).toContain('/api/mailbox/image-proxy?url=')
      // The entity-corruption signature must never appear.
      expect(out).not.toContain('%26amp%3B')
      expect(out).not.toContain('%26amp;')
      // The proxied URL must round-trip to the original decoded URL.
      const m = out.match(/image-proxy\?url=([^"&]+)/)
      expect(m).toBeTruthy()
      expect(decodeURIComponent(m[1])).toBe(src)
    })

    it('keeps width/height and other attributes when proxying', () => {
      const html = '<img src="https://example.com/a.jpg?x=1&amp;y=2" width="64" height="64" alt="pic">'
      const out = processEmailContent(html)
      expect(out).toContain('width="64"')
      expect(out).toContain('height="64"')
      expect(out).toContain('alt="pic"')
    })

    it('does not proxy cid: or data: images', () => {
      const cid = '<img src="cid:part1.abc@example.com">'
      const data = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">'
      expect(processEmailContent(cid)).not.toContain('image-proxy')
      expect(processEmailContent(data)).not.toContain('image-proxy')
    })
  })

  describe('image blocking (privacy mode)', () => {
    it('replaces remote images with a placeholder carrying the decoded original src', () => {
      const src = 'https://example.com/a.jpg?x=1&y=2'
      const html = '<img src="https://example.com/a.jpg?x=1&amp;y=2">'
      const out = processEmailContent(html, { blockRemoteImages: true })

      expect(out).toContain('blocked-image')
      expect(out).not.toContain('<img')
      const m = out.match(/data-original-src="([^"]+)"/)
      expect(m).toBeTruthy()
      expect(decodeURIComponent(m[1])).toBe(src)
    })

    it('leaves cid:/data: images visible when blocking', () => {
      const html = '<img src="cid:inline-logo">'
      const out = processEmailContent(html, { blockRemoteImages: true })
      expect(out).toContain('cid:inline-logo')
      expect(out).not.toContain('blocked-image')
    })
  })

  describe('tracking pixel stripping', () => {
    it('removes our own open-tracking pixels', () => {
      const html = '<p>Hi</p><img src="/api/track/0123456789abcdef.gif" width="1" height="1">'
      const out = processEmailContent(html)
      expect(out).not.toContain('/api/track/')
      expect(out).toContain('Hi')
    })
  })

  describe('video embeds', () => {
    it('converts a text-only YouTube anchor into an embed', () => {
      const html = '<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">Watch this</a>'
      const out = processEmailContent(html)
      expect(out).toContain('youtube.com/embed/dQw4w9WgXcQ')
      expect(out).toContain('youtube-embed')
    })

    it('converts a text-only Vimeo anchor into an embed', () => {
      const html = '<a href="https://vimeo.com/123456">Watch the film</a>'
      const out = processEmailContent(html)
      expect(out).toContain('player.vimeo.com/video/123456')
    })

    it('does NOT corrupt anchors whose href merely CONTAINS a video URL', () => {
      // Old string-regex pipeline spliced an entire <div><iframe> into the
      // href attribute for URLs like this.
      const html = '<a href="https://example.com/redirect?to=vimeo.com/999">click</a>'
      const out = processEmailContent(html)
      expect(out).not.toContain('player.vimeo.com/video/999')
      expect(out).toContain('https://example.com/redirect?to=vimeo.com/999')
    })

    it('leaves image-wrapping video links as links (marketing mail pattern)', () => {
      const html = '<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"><img src="https://example.com/thumb.jpg"></a>'
      const out = processEmailContent(html)
      expect(out).not.toContain('youtube.com/embed/')
      expect(out).toContain('image-proxy')
    })

    it('embeds bare video URLs found in plain text', () => {
      const html = '<p>Check this out: https://youtu.be/dQw4w9WgXcQ and reply</p>'
      const out = processEmailContent(html)
      expect(out).toContain('youtube.com/embed/dQw4w9WgXcQ')
      expect(out).toContain('Check this out:')
      expect(out).toContain('and reply')
    })

    it('escapes anchor text used as the embed title', () => {
      const html = '<a href="https://youtu.be/dQw4w9WgXcQ">"quoted" &amp; titled</a>'
      const out = processEmailContent(html)
      expect(out).toContain('youtube.com/embed/dQw4w9WgXcQ')
      // Must not break out of the title="" attribute.
      expect(out).toMatch(/title="[^"]*quoted[^"]*"/)
    })
  })

  describe('document flattening', () => {
    it('hoists <head><style> blocks in front of the body content', () => {
      const html = '<html><head><style>.x{color:red}</style></head><body><p class="x">hi</p></body></html>'
      const out = processEmailContent(html)
      expect(out.indexOf('<style>')).toBe(0)
      expect(out).toContain('.x{color:red}')
      expect(out).toContain('<p class="x">hi</p>')
      // No duplicated styles, no leftover html/body wrapper.
      expect(out.match(/<style>/g)).toHaveLength(1)
      expect(out).not.toContain('<body')
    })
  })

  describe('sanitization', () => {
    it('strips scripts and event handlers', () => {
      const html = '<p onclick="evil()">hi</p><script>evil()</script>'
      const out = processEmailContent(html)
      expect(out).not.toContain('script')
      expect(out).not.toContain('onclick')
      expect(out).toContain('hi')
    })

    it('converts newlines of text-like html parts to <br>', () => {
      const html = 'line one\nline two\nline three'
      const out = processEmailContent(html)
      expect((out.match(/<br>/g) || []).length).toBeGreaterThanOrEqual(2)
    })

    it('returns falsy input unchanged', () => {
      expect(processEmailContent('')).toBe('')
      expect(processEmailContent(null)).toBe(null)
    })
  })
})
