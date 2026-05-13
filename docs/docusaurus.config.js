// @ts-check

/**
 * Shillinq documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, i18n scaffolding, KvK / BTW
 * copyright). Site-specific overrides — locale (en only), sidebar
 * path, mermaid theme, custom prism themes, shillinq-only navbar
 * items — are passed through createConfig() opts.
 *
 * Scaffolded via /journeydoc-init (ADR-030). Adapted from the
 * pipelinq docs site. This config is a best-effort starting point —
 * shillinq had no Docusaurus site before; review and tune as needed.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'Shillinq',
  tagline: 'Open-source business administration suite for Nextcloud — bookkeeping, invoicing, procurement, contract management',
  url: 'https://shillinq.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'shillinq',

  /* `repoUrl` (preset 2.7.0+) sets the target of the brand navbar's
     GitHub icon. The "Stable · v{x.y.z}" pill in the navbar reads
     `customFields.appVersion`, which the preset auto-populates from
     `appinfo/info.xml` at build time (the canonical Nextcloud-app
     version source). */
  repoUrl: 'https://github.com/ConductionNL/shillinq',

  /* English-only for now (ADR-030). The brand preset ships a
     multi-locale i18n block (nl/en/de/fr), but enabling locales
     without translated markdown breaks SSR on doc pages — stale
     locale metadata trips `Cannot read properties of undefined`.
     Re-enable 'nl' once a Dutch translation pass has shipped the
     `i18n/nl/docusaurus-plugin-content-docs/current/` markdown. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
    localeConfigs: {
      en: { label: 'English' },
    },
  },

  /* The shillinq docs source lives at the repo root of `docs/` rather
     than under a `docs/` subfolder, so we override the preset's default
     `presets:` block to point `docs.path` at './' and disable the blog
     plugin. customCss carries shillinq-specific CSS only — brand tokens
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
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar items.

     The preset 2.7.0 navbar swizzle renders three brand-specific item
     types alongside the regular docSidebar / link / localeDropdown:

       type: 'custom-github'       icon-only GitHub mark
       type: 'custom-apiDocs'      icon + 'API Documentation' (defaults to /api)
       type: 'custom-versionPill'  'Stable · v{x.y.z}' chip, reads info.xml

     The `custom-` prefix is the Docusaurus convention for theme-
     defined types that bypass the strict themeConfig validator.
     Shillinq doesn't ship an OpenAPI reference yet, so apiDocs is
     omitted (we can wire it in once tests/e2e produces the OAS). The
     pill auto-resolves to 'Stable · v0.1.0' from appinfo/info.xml. */
  navbar: {
    /* Override the preset's default `img/logo.svg` (which it would
       silently overlay with its own Conduction-avatar bundled asset).
       The shillinq asset is shipped under a non-colliding filename so
       Docusaurus's staticDirectories merge doesn't shadow it. */
    logo: {
      alt: 'Shillinq avatar',
      src: 'img/app-logo.svg',
    },
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      { type: 'custom-versionPill', position: 'right' },
      {
        type: 'custom-github',
        href: 'https://github.com/ConductionNL/shillinq',
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
     instead of failing — the absence is visible at preview time and
     the capture spec brings everything back on demand. Flip to
     'throw' once screenshots are committed. */
  hooks: {
    onBrokenMarkdownImages: 'warn',
  },
};

module.exports = config;
