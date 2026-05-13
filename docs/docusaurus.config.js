// @ts-check

/**
 * Shillinq documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, i18n scaffolding, KvK / BTW
 * copyright). Site-specific overrides,locale (en only), sidebar
 * path, mermaid theme, custom prism themes, shillinq-only navbar
 * items,are passed through createConfig() opts.
 *
 * Scaffolded via /journeydoc-init (ADR-030). Adapted from the
 * pipelinq docs site. This config is a best-effort starting point.
 * Shillinq had no Docusaurus site before; review and tune as needed.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'Shillinq',
  tagline: 'Open-source business administration suite for Nextcloud,bookkeeping, invoicing, procurement, contract management',
  url: 'https://shillinq.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'shillinq',

  /* Locales: en (primary) + nl (declared so the locale dropdown is
     present for translators; Dutch markdown ships via issue #79). If
     SSR fails on the nl locale (ADR-030 edge case with stale locale
     metadata), revert `locales` to `['en']` with a one-line comment
     citing #79 and the rest of the change still ships. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'nl'],
    localeConfigs: {
      en: { label: 'English' },
      nl: { label: 'Nederlands' },
    },
  },

  /* The shillinq docs source lives at the repo root of `docs/` rather
     than under a `docs/` subfolder, so we override the preset's default
     `presets:` block to point `docs.path` at './' and disable the blog
     plugin. customCss carries shillinq-specific CSS only,brand tokens
     and the theme swizzles are auto-loaded by the brand theme entry in
     `themes:` below. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          /* docs.path: './' makes plugin-content-docs scan every file
             in docs/, which collides with plugin-content-pages's own
             scan of docs/src/pages/. Exclude src/ (pages live there)
             plus the standard node_modules bucket. */
          exclude: ['**/node_modules/**', 'src/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://github.com/ConductionNL/shillinq/tree/development/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
    /* Redocusaurus mounts the OpenAPI spec at /api as a Redoc three-pane
       page. The OAS JSON ships via issue #80; until then the build emits
       a warning but does not fail. If the build does fail on the missing
       file, commit a minimal `{"openapi":"3.0.0","info":{"title":"Shillinq",
       "version":"0.0.0"},"paths":{}}` shim at static/oas/shillinq.json. */
    [
      'redocusaurus',
      {
        specs: [
          {
            id: 'shillinq',
            spec: 'static/oas/shillinq.json',
            route: '/api',
          },
        ],
        theme: {
          primaryColor: '#21468B',
        },
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with shillinq's own (Documentation sidebar link,
     shillinq GitHub link). Object.assign in createConfig is shallow,
     so items: replaces wholesale. */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: '/api',
        label: 'API Documentation',
        position: 'right',
      },
      {
        href: 'https://github.com/ConductionNL/shillinq',
        label: 'GitHub',
        position: 'right',
      },
      { type: 'localeDropdown', position: 'right' },
    ],
  },

  /* Per-property footer override (preset 1.2.0+): we pass `links` only,
     so the brand `style: 'dark'` and the brand KvK/BTW/IBAN/address
     copyright string both inherit unchanged. */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer mini-games on this product-page footer
     (preset 1.3.0+). The static skyline + canal decoration are kept;
     the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). prism + mermaid land alongside. */
  themeConfig: {
    prism: {
      theme: require('prism-react-renderer/themes/github'),
      darkTheme: require('prism-react-renderer/themes/dracula'),
    },
    mermaid: {
      theme: { light: 'default', dark: 'dark' },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   markdown + onBrokenAnchors directly so they make it into the final
   Docusaurus config. trailingSlash is left at the preset's default. */
config.onBrokenAnchors = 'warn';
config.markdown = {
  mermaid: true,
  /* Tutorial pages reference screenshots populated by
     `tests/e2e/docs-screenshots.spec.ts`. The Playwright capture run
     is separate from the docs build, so the build needs to succeed
     even when a fresh checkout doesn't have every PNG yet. Warn
     instead of failing,the absence is visible at preview time and
     the capture spec brings everything back on demand. Flip to
     'throw' once screenshots are committed. */
  hooks: {
    onBrokenMarkdownImages: 'warn',
  },
};

module.exports = config;
