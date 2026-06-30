/**
 * Premade website component templates for the Mood Board Component Library.
 * All items have titles for CSS class generation.
 * All colors from shared palette for global consistency.
 */

import { C } from './templatePalette'

// ── Shared helpers ──

const n = (title, obj) => ({ ...obj, title })

const heading = (text, x, y, w, fontSize = 32, color = C.textPrimary) => ({
  type: 'text', pos_x: x, pos_y: y, width: w, height: Math.round(fontSize * 1.6),
  content: text,
  style_data: { font_size: fontSize, font_weight: '700', font_family: 'Inter', text_color: color, text_align: 'left' },
})

const sub = (text, x, y, w, fontSize = 16, color = C.textSecondary) => ({
  type: 'text', pos_x: x, pos_y: y, width: w, height: Math.round(fontSize * 2.4),
  content: text,
  style_data: { font_size: fontSize, font_weight: '400', font_family: 'Inter', text_color: color, text_align: 'left', line_height: 1.6 },
})

const body = (text, x, y, w, h, fontSize = 14, color = C.textSecondary) => ({
  type: 'text', pos_x: x, pos_y: y, width: w, height: h,
  content: text,
  style_data: { font_size: fontSize, font_weight: '400', font_family: 'Inter', text_color: color, text_align: 'left', line_height: 1.6 },
})

const pill = (text, x, y, w = 160, h = 44, fill = C.primary, textColor = C.white) => ({
  type: 'shape', pos_x: x, pos_y: y, width: w, height: h, content: text,
  style_data: {
    shape_fill: fill, shape_opacity: 100, shape_border_width: 0, shape_border_color: fill,
    shape_border_radius: 9999, shape_font_size: 14, shape_font_weight: '600', shape_font_color: textColor, shape_text_align: 'center',
  },
})

const ghost = (text, x, y, w = 160, h = 44, border = C.primary, textColor = C.primary) => ({
  type: 'shape', pos_x: x, pos_y: y, width: w, height: h, content: text,
  style_data: {
    shape_fill: 'transparent', shape_opacity: 100, shape_border_width: 2, shape_border_color: border,
    shape_border_radius: 9999, shape_font_size: 14, shape_font_weight: '600', shape_font_color: textColor, shape_text_align: 'center',
  },
})

const card = (x, y, w, h, fill = C.bgCard, radius = 16) => ({
  type: 'shape', pos_x: x, pos_y: y, width: w, height: h,
  style_data: { shape_fill: fill, shape_opacity: 100, shape_border_width: 1, shape_border_color: C.borderSubtle, shape_border_radius: radius },
})

const img = (x, y, w, h, fill = C.borderSubtle, radius = 12) => ({
  type: 'shape', pos_x: x, pos_y: y, width: w, height: h, content: '',
  style_data: { shape_fill: fill, shape_opacity: 100, shape_border_width: 1, shape_border_color: C.borderMuted, shape_border_radius: radius, shape_font_size: 22, shape_font_color: C.textMuted, shape_text_align: 'center' },
})

const bg = (x, y, w, h, fill = C.bgDark, radius = 16) => ({
  type: 'shape', pos_x: x, pos_y: y, width: w, height: h,
  style_data: { shape_fill: fill, shape_opacity: 100, shape_border_width: 0, shape_border_color: fill, shape_border_radius: radius },
})

const badge = (text, x, y, w = 80, fill = C.primarySoft, color = C.primaryLight) => ({
  type: 'shape', pos_x: x, pos_y: y, width: w, height: 24, content: text,
  style_data: {
    shape_fill: fill, shape_opacity: 100, shape_border_width: 0, shape_border_color: fill,
    shape_border_radius: 9999, shape_font_size: 10, shape_font_weight: '600', shape_font_color: color, shape_text_align: 'center',
  },
})

const divider = (x, y, w) => bg(x, y, w, 1, C.borderSubtle, 0)

const c = (text, x, y, w, h = 20, fs = 14, color = C.textPrimary, align = 'center') => ({
  type: 'text', pos_x: x, pos_y: y, width: w, height: h, content: text,
  style_data: { font_size: fs, font_weight: '700', font_family: 'Inter', text_color: color, text_align: align },
})

// ==========================================
// WEBSITE ELEMENT CATEGORIES
// ==========================================

export const premadeCategories = [
  { value: 'premade-hero', label: 'Heroes' },
  { value: 'premade-content', label: 'Content' },
  { value: 'premade-cta', label: 'CTA' },
  { value: 'premade-grid', label: 'Grids' },
  { value: 'premade-social', label: 'Social Proof' },
  { value: 'premade-commerce', label: 'Commerce' },
  { value: 'premade-nav', label: 'Navigation' },
]

// ==========================================
// WEBSITE ELEMENTS
// ==========================================

export const premadeComponents = [

  // ─── HEROES ───────────────────────────

  {
    id: 'premade-hero-center',
    name: 'Hero - Centered',
    category: 'premade-hero',
    icon: 'web',
    description: 'Large centered headline, subtitle, dual CTA',
    items_data: [
      n('hero-bg', bg(0, 0, 960, 480, C.bgDark, 20)),
      n('hero-badge', badge('NEW RELEASE', 400, 40, 120, C.primarySoft, C.primaryLight)),
      n('hero-title', c('Build Something\nIncredible Today', 130, 80, 700, 100, 48, C.textPrimary)),
      n('hero-subtitle', { ...sub('The modern platform for teams who want to ship faster. Beautiful defaults, powerful customization, zero config.', 180, 200, 600, 16, C.textSecondary), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('btn-cta-primary', pill('Get Started Free', 310, 280, 170, 48)),
      n('btn-cta-secondary', ghost('Watch Demo', 500, 280, 150, 48, C.borderMuted, C.textBody)),
      n('hero-image', img(180, 360, 600, 90, C.bgCard, 12)),
    ],
  },
  {
    id: 'premade-hero-split',
    name: 'Hero - Split Left',
    category: 'premade-hero',
    icon: 'view_sidebar',
    description: 'Text left with badges, image right',
    items_data: [
      n('hero-bg', bg(0, 0, 960, 440, C.bgDark, 20)),
      n('hero-badge', badge('BETA', 48, 40, 60, C.primarySoft, C.primaryLight)),
      n('hero-title', heading('Ship Faster.\nScale Smarter.', 48, 76, 420, 44, C.textPrimary)),
      n('hero-subtitle', sub('Everything you need to build, deploy, and grow your product. From idea to production in minutes.', 48, 200, 400, 15, C.textSecondary)),
      n('btn-cta-primary', pill('Start Building', 48, 290, 170, 48)),
      n('btn-cta-secondary', ghost('See Pricing', 236, 290, 140, 48, C.borderMuted, C.textBody)),
      n('hero-card', card(520, 30, 410, 380, C.bgCard, 16)),
      n('hero-image', img(536, 46, 378, 348, C.bgDark, 12)),
    ],
  },
  {
    id: 'premade-hero-split-right',
    name: 'Hero - Split Right',
    category: 'premade-hero',
    icon: 'view_sidebar',
    description: 'Image left, text right',
    items_data: [
      n('hero-bg', bg(0, 0, 960, 440, C.bgDark, 20)),
      n('hero-card', card(30, 30, 410, 380, C.bgCard, 16)),
      n('hero-image', img(46, 46, 378, 348, C.bgDark, 12)),
      n('hero-badge', badge('POPULAR', 500, 40, 80, C.primarySoft, C.primaryLight)),
      n('hero-title', heading('Design Without\nLimits', 500, 76, 420, 44, C.textPrimary)),
      n('hero-subtitle', sub('Unleash your creativity with tools built for modern designers. No constraints, just pure creative freedom.', 500, 200, 400, 15, C.textSecondary)),
      n('btn-cta-primary', pill('Get Started', 500, 290, 170, 48, C.purple, C.white)),
      n('btn-cta-secondary', ghost('See Examples', 690, 290, 150, 48, C.borderMuted, C.textBody)),
    ],
  },
  {
    id: 'premade-hero-gradient',
    name: 'Hero - Gradient Accent',
    category: 'premade-hero',
    icon: 'gradient',
    description: 'Gradient badge, large text, trust logos',
    items_data: [
      n('hero-bg', bg(0, 0, 960, 500, C.bgDark, 20)),
      n('hero-badge', pill('Introducing v2.0', 370, 32, 200, 32, C.primaryDeep, C.primaryLight)),
      n('hero-title', c('The Future of\nTeam Collaboration', 80, 80, 800, 120, 52, C.textPrimary)),
      n('hero-subtitle', { ...sub('Real-time collaboration, AI-powered workflows, and beautiful dashboards. Used by 10,000+ teams worldwide.', 180, 220, 600, 16, C.textSecondary), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('btn-cta-primary', pill('Start Free Trial', 340, 300, 160, 48)),
      n('btn-cta-secondary', ghost('Book a Demo', 520, 300, 140, 48, C.primary, C.primaryLight)),
      n('section-divider', divider(80, 390, 800)),
      n('trust-label', { ...body('TRUSTED BY', 0, 410, 960, 20, 10, C.textMuted), style_data: { ...body('').style_data, text_align: 'center', font_weight: '600', letter_spacing: 2 } }),
      n('logo-1', img(100, 445, 100, 32, C.bgCard, 6)),
      n('logo-2', img(240, 445, 100, 32, C.bgCard, 6)),
      n('logo-3', img(380, 445, 100, 32, C.bgCard, 6)),
      n('logo-4', img(520, 445, 100, 32, C.bgCard, 6)),
      n('logo-5', img(660, 445, 100, 32, C.bgCard, 6)),
    ],
  },

  // ─── CONTENT ──────────────────────────

  {
    id: 'premade-text-img-right',
    name: 'Text Left, Image Right',
    category: 'premade-content',
    icon: 'horizontal_split',
    description: 'Feature highlight with text and visual',
    items_data: [
      n('section-bg', bg(0, 0, 960, 380, C.bgDark, 20)),
      n('section-badge', badge('FEATURE', 48, 40, 80, C.infoSoft, C.infoLight)),
      n('section-title', heading('Why Teams\nChoose Us', 48, 80, 400, 36, C.textPrimary)),
      n('section-body', body('We provide industry-leading solutions that help businesses grow faster. Our platform combines cutting-edge technology with intuitive design.\n\nWith over 10 years of experience, we understand what it takes to succeed.', 48, 170, 400, 140, 14, C.textSecondary)),
      n('btn-learn-more', pill('Learn More', 48, 320, 140, 40)),
      n('image-card', card(510, 30, 420, 320, C.bgCard, 16)),
      n('image-placeholder', img(526, 46, 388, 288, C.bgDark, 12)),
    ],
  },
  {
    id: 'premade-text-img-left',
    name: 'Image Left, Text Right',
    category: 'premade-content',
    icon: 'horizontal_split',
    description: 'Visual left, feature text right',
    items_data: [
      n('section-bg', bg(0, 0, 960, 380, C.bgDark, 20)),
      n('image-card', card(30, 30, 420, 320, C.bgCard, 16)),
      n('image-placeholder', img(46, 46, 388, 288, C.bgDark, 12)),
      n('section-badge', badge('HOW IT WORKS', 510, 40, 110, C.infoSoft, C.infoLight)),
      n('section-title', heading('Simple Three-Step\nProcess', 510, 80, 400, 36, C.textPrimary)),
      n('section-body', body('Sign up, configure your settings, and launch in minutes. No complicated setup, no hidden fees.\n\nJust straightforward tools that work the way you need them to.', 510, 170, 400, 140, 14, C.textSecondary)),
      n('btn-try-it', pill('Try It Now', 510, 320, 140, 40)),
    ],
  },
  {
    id: 'premade-features-grid',
    name: 'Features - 3 Columns',
    category: 'premade-content',
    icon: 'grid_view',
    description: 'Icon cards with feature descriptions',
    items_data: [
      n('section-bg', bg(0, 0, 960, 380, C.bgDark, 20)),
      n('section-title', c('Features', 0, 24, 960, 32, 32, C.textPrimary)),
      n('section-subtitle', { ...sub('Everything you need to build modern applications', 180, 64, 600, 14, C.textSecondary), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('feature-card-1', card(32, 110, 285, 240, C.bgCard, 16)),
      n('feature-icon-1', bg(52, 130, 48, 48, C.primarySoft, 12)),
      n('feature-title-1', heading('Lightning Fast', 52, 195, 245, 18, C.textPrimary)),
      n('feature-desc-1', body('Sub-millisecond response times. Your users will notice the difference immediately.', 52, 225, 245, 80, 13, C.textSecondary)),
      n('feature-card-2', card(337, 110, 285, 240, C.bgCard, 16)),
      n('feature-icon-2', bg(357, 130, 48, 48, C.infoSoft, 12)),
      n('feature-title-2', heading('Secure by Default', 357, 195, 245, 18, C.textPrimary)),
      n('feature-desc-2', body('Enterprise-grade security built into every layer. SOC 2 Type II certified.', 357, 225, 245, 80, 13, C.textSecondary)),
      n('feature-card-3', card(642, 110, 285, 240, C.bgCard, 16)),
      n('feature-icon-3', bg(662, 130, 48, 48, C.greenBg, 12)),
      n('feature-title-3', heading('Easy Integration', 662, 195, 245, 18, C.textPrimary)),
      n('feature-desc-3', body('Connect with your favorite tools in minutes. Works with 200+ popular platforms.', 662, 225, 245, 80, 13, C.textSecondary)),
    ],
  },
  {
    id: 'premade-features-list',
    name: 'Features - Checklist',
    category: 'premade-content',
    icon: 'checklist',
    description: 'Feature list with check icons and text',
    items_data: [
      n('section-bg', bg(0, 0, 960, 400, C.bgDark, 20)),
      n('section-title', heading('Everything\nyou need', 48, 40, 360, 40, C.textPrimary)),
      n('section-subtitle', sub('A complete toolkit built for modern development teams who move fast.', 48, 140, 360, 14, C.textSecondary)),
      n('btn-get-started', pill('Get Started', 48, 220, 150, 44)),
      n('checklist-card', card(480, 30, 450, 340, C.bgCard, 16)),
      n('check-item-1', body('Unlimited projects and workspaces', 540, 55, 360, 24, 14, '#e2e8f0')),
      n('check-item-2', body('Real-time collaboration tools', 540, 95, 360, 24, 14, '#e2e8f0')),
      n('check-item-3', body('Advanced analytics dashboard', 540, 135, 360, 24, 14, '#e2e8f0')),
      n('check-item-4', body('Custom domain support', 540, 175, 360, 24, 14, '#e2e8f0')),
      n('check-item-5', body('Priority email & chat support', 540, 215, 360, 24, 14, '#e2e8f0')),
      n('check-item-6', body('API access with 99.9% uptime SLA', 540, 255, 360, 24, 14, '#e2e8f0')),
      n('check-item-7', body('SOC 2 Type II compliance', 540, 295, 360, 24, 14, '#e2e8f0')),
      n('check-icon-1', bg(500, 57, 20, 20, C.successText, 10)),
      n('check-icon-2', bg(500, 97, 20, 20, C.successText, 10)),
      n('check-icon-3', bg(500, 137, 20, 20, C.successText, 10)),
      n('check-icon-4', bg(500, 177, 20, 20, C.successText, 10)),
      n('check-icon-5', bg(500, 217, 20, 20, C.successText, 10)),
      n('check-icon-6', bg(500, 257, 20, 20, C.successText, 10)),
      n('check-icon-7', bg(500, 297, 20, 20, C.successText, 10)),
    ],
  },
  {
    id: 'premade-faq',
    name: 'FAQ Section',
    category: 'premade-content',
    icon: 'help',
    description: 'Expandable FAQ with card rows',
    items_data: [
      n('faq-bg', bg(0, 0, 960, 480, C.bgDark, 20)),
      n('faq-title', c('Frequently Asked Questions', 0, 24, 960, 32, 32, C.textPrimary)),
      n('faq-subtitle', { ...sub('Everything you need to know', 0, 64, 960, 14, C.textSecondary), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('faq-card-1', card(160, 108, 640, 72, C.bgCard, 12)),
      n('faq-q-1', heading('How do I get started?', 184, 118, 560, 16, '#e2e8f0')),
      n('faq-a-1', body('Sign up free, follow our quick-start guide, and launch in under 5 minutes.', 184, 143, 560, 24, 13, C.textSecondary)),
      n('faq-card-2', card(160, 196, 640, 72, C.bgCard, 12)),
      n('faq-q-2', heading('Can I cancel anytime?', 184, 206, 560, 16, '#e2e8f0')),
      n('faq-a-2', body('Yes. Cancel your subscription at any time with zero cancellation fees.', 184, 231, 560, 24, 13, C.textSecondary)),
      n('faq-card-3', card(160, 284, 640, 72, C.bgCard, 12)),
      n('faq-q-3', heading('Do you offer a free trial?', 184, 294, 560, 16, '#e2e8f0')),
      n('faq-a-3', body('14-day free trial on all paid plans. No credit card required.', 184, 319, 560, 24, 13, C.textSecondary)),
      n('faq-card-4', card(160, 372, 640, 72, C.bgCard, 12)),
      n('faq-q-4', heading('What payment methods accepted?', 184, 382, 560, 16, '#e2e8f0')),
      n('faq-a-4', body('All major credit cards, PayPal, and wire transfer for enterprise.', 184, 407, 560, 24, 13, C.textSecondary)),
    ],
  },

  // ─── CTA ──────────────────────────────

  {
    id: 'premade-cta-block',
    name: 'CTA Block - Centered',
    category: 'premade-cta',
    icon: 'ads_click',
    description: 'Full-width gradient CTA section',
    items_data: [
      n('cta-bg', bg(0, 0, 960, 300, C.primaryDeep, 24)),
      n('cta-title', c('Ready to Get Started?', 130, 50, 700, 44, 40, C.textPrimary)),
      n('cta-subtitle', { ...sub('Join 10,000+ teams already building faster. No credit card required.', 180, 110, 600, 16, C.ctaLight), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('btn-cta-primary', pill('Start Free Trial', 320, 180, 170, 48, C.white, C.primaryDeep)),
      n('btn-cta-secondary', ghost('Talk to Sales', 510, 180, 150, 48, C.accent, C.ctaLight)),
    ],
  },
  {
    id: 'premade-cta-banner',
    name: 'CTA Banner - Horizontal',
    category: 'premade-cta',
    icon: 'horizontal_rule',
    description: 'Compact inline CTA strip',
    items_data: [
      n('banner-bg', bg(0, 0, 960, 80, '#4f46e5', 16)),
      n('banner-title', heading('Start building for free today', 48, 20, 500, 20, C.white)),
      n('banner-subtitle', body('No credit card required. Cancel anytime.', 48, 46, 400, 16, 13, C.ctaLight)),
      n('btn-signup', pill('Sign Up Free', 770, 18, 150, 44, C.white, '#4f46e5')),
    ],
  },
  {
    id: 'premade-cta-split',
    name: 'CTA - Split Card',
    category: 'premade-cta',
    icon: 'call_to_action',
    description: 'Text left, action card right',
    items_data: [
      n('cta-bg', bg(0, 0, 960, 260, C.bgDark, 20)),
      n('cta-title', heading('Take your workflow\nto the next level', 48, 50, 440, 32, C.textPrimary)),
      n('cta-subtitle', sub('Automate repetitive tasks and focus on what matters most to your team.', 48, 140, 400, 14, C.textSecondary)),
      n('pricing-card', card(540, 30, 390, 200, C.bgCard, 16)),
      n('pricing-plan', c('Pro Plan', 540, 55, 390, 24, 20, C.textPrimary)),
      n('pricing-desc', { ...body('$29/mo - Unlimited everything', 540, 90, 390, 20, 14, C.textSecondary), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('btn-upgrade', pill('Upgrade Now', 640, 140, 180, 44)),
    ],
  },

  // ─── GRIDS ────────────────────────────

  {
    id: 'premade-news-3col',
    name: 'Cards - 3 Columns',
    category: 'premade-grid',
    icon: 'view_column_2',
    description: 'Image cards with text and action',
    items_data: [
      n('grid-bg', bg(0, 0, 960, 480, C.bgDark, 20)),
      n('grid-title', c('Latest Updates', 0, 20, 960, 28, 28, C.textPrimary)),
      n('card-1', card(24, 70, 296, 380, C.bgCard, 16)),
      n('card-1-image', img(24, 70, 296, 170, C.borderSubtle, 16)),
      n('card-1-badge', badge('DESIGN', 40, 260, 70, C.primarySoft, C.primaryLight)),
      n('card-1-title', heading('Modern Design Systems', 40, 295, 265, 16, C.textPrimary)),
      n('card-1-body', body('Learn how to build scalable design systems that grow with your product.', 40, 325, 265, 52, 13, C.textSecondary)),
      n('card-1-btn', pill('Read More', 40, 395, 100, 32)),
      n('card-2', card(332, 70, 296, 380, C.bgCard, 16)),
      n('card-2-image', img(332, 70, 296, 170, C.borderSubtle, 16)),
      n('card-2-badge', badge('DEV', 348, 260, 50, C.infoSoft, C.infoLight)),
      n('card-2-title', heading('API Best Practices', 348, 295, 265, 16, C.textPrimary)),
      n('card-2-body', body('Essential patterns for building robust and developer-friendly APIs.', 348, 325, 265, 52, 13, C.textSecondary)),
      n('card-2-btn', pill('Read More', 348, 395, 100, 32)),
      n('card-3', card(640, 70, 296, 380, C.bgCard, 16)),
      n('card-3-image', img(640, 70, 296, 170, C.borderSubtle, 16)),
      n('card-3-badge', badge('GROWTH', 656, 260, 75, C.greenBg, C.greenLight)),
      n('card-3-title', heading('Scaling Your Startup', 656, 295, 265, 16, C.textPrimary)),
      n('card-3-body', body('Strategies for growing from 0 to 1M users without breaking the bank.', 656, 325, 265, 52, 13, C.textSecondary)),
      n('card-3-btn', pill('Read More', 656, 395, 100, 32)),
    ],
  },
  {
    id: 'premade-news-2col',
    name: 'Cards - 2 Columns',
    category: 'premade-grid',
    icon: 'view_column_2',
    description: 'Two wider featured cards',
    items_data: [
      n('grid-bg', bg(0, 0, 960, 460, C.bgDark, 20)),
      n('grid-title', c('Featured Articles', 0, 20, 960, 28, 28, C.textPrimary)),
      n('card-1', card(24, 68, 448, 370, C.bgCard, 16)),
      n('card-1-image', img(24, 68, 448, 200, C.borderSubtle, 16)),
      n('card-1-badge', badge('FEATURED', 44, 290, 85, C.primarySoft, C.primaryLight)),
      n('card-1-title', heading('Building the Future of Design', 44, 325, 408, 18, C.textPrimary)),
      n('card-1-body', body('A deep dive into the tools and processes that are shaping modern product design.', 44, 355, 408, 44, 13, C.textSecondary)),
      n('card-1-btn', pill('Read Article', 44, 405, 120, 32)),
      n('card-2', card(488, 68, 448, 370, C.bgCard, 16)),
      n('card-2-image', img(488, 68, 448, 200, C.borderSubtle, 16)),
      n('card-2-badge', badge('NEW', 508, 290, 50, C.infoSoft, C.infoLight)),
      n('card-2-title', heading('Scaling Engineering Teams', 508, 325, 408, 18, C.textPrimary)),
      n('card-2-body', body('How top companies build and maintain high-performing engineering organizations.', 508, 355, 408, 44, 13, C.textSecondary)),
      n('card-2-btn', pill('Read Article', 508, 405, 120, 32)),
    ],
  },

  // ─── SOCIAL PROOF ─────────────────────

  {
    id: 'premade-testimonials-3col',
    name: 'Testimonials - 3 Cards',
    category: 'premade-social',
    icon: 'format_quote',
    description: 'Quote cards with avatars',
    items_data: [
      n('section-bg', bg(0, 0, 960, 380, C.bgDark, 20)),
      n('section-title', c('What Our Clients Say', 0, 20, 960, 28, 28, C.textPrimary)),
      n('testimonial-card-1', card(24, 70, 296, 280, C.bgCard, 16)),
      n('testimonial-quote-1', body('"This product completely transformed how we work. The team is incredibly responsive."', 44, 95, 256, 80, 14, C.textBody)),
      n('testimonial-avatar-1', img(44, 250, 40, 40, C.borderMuted, 20)),
      n('testimonial-name-1', heading('Sarah Johnson', 100, 252, 180, 14, C.textPrimary)),
      n('testimonial-role-1', body('CEO, TechStart', 100, 274, 180, 16, 11, C.textMuted)),
      n('testimonial-card-2', card(332, 70, 296, 280, C.bgCard, 16)),
      n('testimonial-quote-2', body('"Incredible value. We switched from a competitor and haven\'t looked back since."', 352, 95, 256, 80, 14, C.textBody)),
      n('testimonial-avatar-2', img(352, 250, 40, 40, C.borderMuted, 20)),
      n('testimonial-name-2', heading('Michael Chen', 408, 252, 180, 14, C.textPrimary)),
      n('testimonial-role-2', body('CTO, DataFlow', 408, 274, 180, 16, 11, C.textMuted)),
      n('testimonial-card-3', card(640, 70, 296, 280, C.bgCard, 16)),
      n('testimonial-quote-3', body('"Best investment we\'ve made this year. Productivity went up 40% in month one."', 660, 95, 256, 80, 14, C.textBody)),
      n('testimonial-avatar-3', img(660, 250, 40, 40, C.borderMuted, 20)),
      n('testimonial-name-3', heading('Emily Rodriguez', 716, 252, 180, 14, C.textPrimary)),
      n('testimonial-role-3', body('VP Marketing, GrowthCo', 716, 274, 180, 16, 11, C.textMuted)),
    ],
  },
  {
    id: 'premade-stats-row',
    name: 'Stats Row',
    category: 'premade-social',
    icon: 'analytics',
    description: '4 big numbers with labels',
    items_data: [
      n('stats-bg', bg(0, 0, 960, 160, C.bgDark, 20)),
      n('stat-value-1', c('10K+', 24, 30, 210, 44, 40, C.accent)),
      n('stat-label-1', { ...body('Happy Customers', 24, 82, 210, 24, 13, C.textSecondary), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('stat-value-2', c('99.9%', 252, 30, 210, 44, 40, C.accent)),
      n('stat-label-2', { ...body('Uptime SLA', 252, 82, 210, 24, 13, C.textSecondary), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('stat-value-3', c('50M+', 480, 30, 210, 44, 40, C.accent)),
      n('stat-label-3', { ...body('API Requests/Day', 480, 82, 210, 24, 13, C.textSecondary), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('stat-value-4', c('24/7', 708, 30, 210, 44, 40, C.accent)),
      n('stat-label-4', { ...body('Expert Support', 708, 82, 210, 24, 13, C.textSecondary), style_data: { ...body('').style_data, text_align: 'center' } }),
    ],
  },
  {
    id: 'premade-logos-bar',
    name: 'Logo / Trust Bar',
    category: 'premade-social',
    icon: 'verified',
    description: 'Trusted-by company logos strip',
    items_data: [
      n('trust-bg', bg(0, 0, 960, 100, C.bgDark, 16)),
      n('trust-label', { ...body('TRUSTED BY INDUSTRY LEADERS', 0, 12, 960, 18, 10, C.textMuted), style_data: { ...body('').style_data, text_align: 'center', font_weight: '600', letter_spacing: 2 } }),
      n('logo-1', img(80, 46, 120, 36, C.bgCard, 8)),
      n('logo-2', img(240, 46, 120, 36, C.bgCard, 8)),
      n('logo-3', img(400, 46, 120, 36, C.bgCard, 8)),
      n('logo-4', img(560, 46, 120, 36, C.bgCard, 8)),
      n('logo-5', img(720, 46, 120, 36, C.bgCard, 8)),
    ],
  },

  // ─── COMMERCE ─────────────────────────

  {
    id: 'premade-pricing-3col',
    name: 'Pricing - 3 Tiers',
    category: 'premade-commerce',
    icon: 'payments',
    description: 'Free, Pro, Enterprise pricing cards',
    items_data: [
      n('pricing-bg', bg(0, 0, 960, 520, C.bgDark, 20)),
      n('pricing-title', c('Simple, Transparent Pricing', 0, 15, 960, 32, 32, C.textPrimary)),
      n('pricing-subtitle', { ...sub('No hidden fees. Change plans or cancel at any time.', 0, 52, 960, 14, C.textSecondary), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('plan-free-card', card(24, 90, 296, 400, C.bgCard, 16)),
      n('plan-free-name', { ...heading('Free', 24, 115, 296, 18, C.textSecondary), style_data: { ...heading('').style_data, text_align: 'center' } }),
      n('plan-free-price', c('$0', 24, 150, 296, 40, 40, C.textPrimary)),
      n('plan-free-period', { ...body('/month', 24, 195, 296, 18, 12, C.textMuted), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('plan-free-divider', divider(48, 225, 248)),
      n('plan-free-features', body('5 projects\n1 GB storage\nBasic analytics\nEmail support\nCommunity access', 64, 245, 216, 150, 13, C.textSecondary)),
      n('plan-free-btn', ghost('Get Started', 74, 430, 196, 40, C.borderMuted, C.textBody)),
      n('plan-pro-card', card(332, 90, 296, 400, C.primarySoft, 16)),
      n('plan-pro-badge', badge('POPULAR', 402, 98, 80, C.primaryHover, C.ctaLight)),
      n('plan-pro-name', { ...heading('Pro', 332, 115, 296, 18, C.ctaLight), style_data: { ...heading('').style_data, text_align: 'center' } }),
      n('plan-pro-price', c('$29', 332, 150, 296, 40, 40, C.white)),
      n('plan-pro-period', { ...body('/month', 332, 195, 296, 18, 12, C.accent), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('plan-pro-divider', divider(356, 225, 248)),
      n('plan-pro-features', body('Unlimited projects\n50 GB storage\nAdvanced analytics\nPriority support\nCustom domain\nAPI access', 372, 245, 216, 150, 13, C.ctaLight)),
      n('plan-pro-btn', pill('Choose Pro', 382, 430, 196, 40)),
      n('plan-ent-card', card(640, 90, 296, 400, C.bgCard, 16)),
      n('plan-ent-name', { ...heading('Enterprise', 640, 115, 296, 18, C.textSecondary), style_data: { ...heading('').style_data, text_align: 'center' } }),
      n('plan-ent-price', c('$99', 640, 150, 296, 40, 40, C.textPrimary)),
      n('plan-ent-period', { ...body('/month', 640, 195, 296, 18, 12, C.textMuted), style_data: { ...body('').style_data, text_align: 'center' } }),
      n('plan-ent-divider', divider(664, 225, 248)),
      n('plan-ent-features', body('Everything in Pro\nUnlimited storage\nWhite-label option\nDedicated manager\nSLA guarantee\nSSO & SAML', 680, 245, 216, 150, 13, C.textSecondary)),
      n('plan-ent-btn', ghost('Contact Sales', 690, 430, 196, 40, C.borderMuted, C.textBody)),
    ],
  },
  {
    id: 'premade-product-card',
    name: 'Product Card',
    category: 'premade-commerce',
    icon: 'shopping_bag',
    description: 'E-commerce product with image, price, button',
    items_data: [
      n('product-card', card(0, 0, 300, 420, C.bgCard, 20)),
      n('product-image', img(0, 0, 300, 220, C.borderSubtle, 20)),
      n('product-badge', badge('NEW', 16, 236, 50, C.primarySoft, C.primaryLight)),
      n('product-title', heading('Product Name', 16, 270, 268, 18, C.textPrimary)),
      n('product-desc', body('Brief description of this amazing product and its key benefits.', 16, 300, 268, 40, 13, C.textSecondary)),
      n('product-price', heading('$49.99', 16, 355, 120, 22, C.accent)),
      n('btn-add-to-cart', pill('Add to Cart', 170, 352, 114, 38)),
    ],
  },
  {
    id: 'premade-newsletter',
    name: 'Newsletter Signup',
    category: 'premade-commerce',
    icon: 'mail',
    description: 'Email capture with title and input',
    items_data: [
      n('newsletter-bg', bg(0, 0, 960, 240, C.primaryDeep, 24)),
      n('newsletter-title', c('Stay in the Loop', 0, 40, 960, 32, 32, C.textPrimary)),
      n('newsletter-subtitle', { ...sub('Get the latest updates, tips, and exclusive offers in your inbox.', 180, 85, 600, 14, C.primaryLight), style_data: { ...sub('').style_data, text_align: 'center' } }),
      n('newsletter-input', card(260, 140, 310, 48, C.primarySoft, 24)),
      n('newsletter-placeholder', { ...body('Enter your email...', 280, 152, 250, 24, 13, C.primary), style_data: { ...body('').style_data } }),
      n('btn-subscribe', pill('Subscribe', 585, 140, 130, 48)),
    ],
  },

  // ─── NAVIGATION ───────────────────────

  {
    id: 'premade-header-nav',
    name: 'Header / Navbar',
    category: 'premade-nav',
    icon: 'menu',
    description: 'Logo, nav links, sign-in, CTA',
    items_data: [
      n('navbar-bg', bg(0, 0, 960, 64, C.bgDark, 0)),
      n('navbar-logo', heading('Logo', 32, 18, 80, 20, C.textPrimary)),
      n('nav-products', body('Products', 280, 22, 70, 20, 14, C.textBody)),
      n('nav-features', body('Features', 360, 22, 70, 20, 14, C.textBody)),
      n('nav-pricing', body('Pricing', 440, 22, 60, 20, 14, C.textBody)),
      n('nav-docs', body('Docs', 510, 22, 40, 20, 14, C.textBody)),
      n('nav-blog', body('Blog', 560, 22, 40, 20, 14, C.textSecondary)),
      n('nav-sign-in', body('Sign in', 730, 22, 50, 20, 14, C.textBody)),
      n('btn-get-started', pill('Get Started', 810, 14, 120, 36)),
    ],
  },
  {
    id: 'premade-footer',
    name: 'Footer',
    category: 'premade-nav',
    icon: 'call_to_action',
    description: 'Multi-column footer with links',
    items_data: [
      n('footer-bg', bg(0, 0, 960, 280, C.bgDark, 0)),
      n('footer-brand', heading('Brand', 48, 32, 180, 22, C.textPrimary)),
      n('footer-tagline', body('Building the future of design tools.\nEmpowering creators worldwide.', 48, 62, 180, 52, 12, C.textMuted)),
      n('col-product-title', heading('Product', 300, 32, 130, 14, C.textSecondary)),
      n('col-product-links', body('Features\nPricing\nIntegrations\nChangelog', 300, 58, 130, 100, 13, C.textMuted)),
      n('col-company-title', heading('Company', 460, 32, 130, 14, C.textSecondary)),
      n('col-company-links', body('About Us\nCareers\nBlog\nPress', 460, 58, 130, 100, 13, C.textMuted)),
      n('col-support-title', heading('Support', 620, 32, 130, 14, C.textSecondary)),
      n('col-support-links', body('Help Center\nContact\nStatus\nTerms', 620, 58, 130, 100, 13, C.textMuted)),
      n('col-social-title', heading('Social', 780, 32, 130, 14, C.textSecondary)),
      n('col-social-links', body('Twitter\nGitHub\nLinkedIn\nYouTube', 780, 58, 130, 100, 13, C.textMuted)),
      n('footer-divider', divider(0, 210, 960)),
      n('footer-copyright', body('2026 Brand, Inc. All rights reserved.', 48, 228, 400, 20, 12, C.textFaint)),
      n('footer-legal', body('Privacy  |  Terms  |  Cookies', 600, 228, 320, 20, 12, C.textFaint)),
    ],
  },
]
