const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const TerserPlugin = require('terser-webpack-plugin')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// frontend-bundle-hygiene REQ-FBH-001 / ADR-061 decision 3: production builds
// MUST NOT ship `devtool: 'source-map'` — pipelinq and openregister are the
// named reference (`webpackConfig.devtool = isDev ? 'cheap-source-map' :
// false`). Before this change: 30 `.map` files, ~69 MB combined in `js/`,
// mostly the 29 MB `shillinq-main.js.map` alone. `false` in production keeps
// dev's cheap, fast line-level maps unaffected.
webpackConfig.devtool = isDev ? 'cheap-source-map' : false

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'shillinq'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	// REQ-WSW-004: embeddable booking self-service widget — script-tag
	// bundle (`widget.js`). The npm package (widget/) and web-component
	// entrypoints re-import the same loader, so this is the single
	// authoritative bundle for all four embed methods.
	widget: {
		import: path.join(
			__dirname,
			'src',
			'components',
			'widget',
			'WidgetEmbed.js',
		),
		filename: 'widget.js',
		library: { name: 'BookingWidget', type: 'umd', export: 'default' },
	},
}

// Use the sibling monorepo checkout of @conduction/nextcloud-vue when one is
// present, otherwise the npm dist.
//
// ⚠️ USE_LOCAL_LIB is opt-OUT, and the shared `apps-extra/nextcloud-vue`
// checkout sits on the Vue 2 (1.x / beta.*) line. Defaulting to "on" would
// silently compile Vue 2 library sources into this Vue 3 app and still
// produce a clean build. Guard on the sibling's MAJOR version rather than
// trusting the default: a 1.x sibling is the Vue 2 line and must be ignored.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib) {
	// The `localMajor < 2` test this replaces was blind to the skew it was
	// written for: nc-vue's Vue 2 line and its Vue 3 line are BOTH major 2. The
	// sibling is 2.0.5 (Vue 2) against a declared ^2.3.0 (Vue 3), so `2 < 2` was
	// false and the Vue 2 checkout was accepted. Compare against the declared
	// RANGE instead, and fail CLOSED if the check cannot run.
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			`[shillinq] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ "it does not satisfy this app's declared range. Building against the npm dist.",
		)
		useLocalLib = false
	}
}

webpackConfig.resolve = {
	// `.mjs` matters now: @nextcloud/vue@9, @nextcloud/dialogs@7 and
	// vue-router@4/5 all ship .mjs entry files, and overriding `extensions`
	// replaces webpack's default list rather than extending it.
	extensions: ['.vue', '.js', '.mjs', '.json'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		// pinia@4 dropped `main` AND `module`, leaving only an `exports` map
		// (`{".": "./dist/pinia.js"}`) — the same shape as @nextcloud/vue and
		// @nextcloud/dialogs below. pinia@3 still had `main: index.cjs` /
		// `module: dist/pinia.mjs`, which is why the Vue-2-era alias to the
		// package DIRECTORY worked until now: a directory alias bypasses
		// `exports` entirely and then looks for a main/index.js that no longer
		// exists, so every `import { … } from 'pinia'` failed with
		// "Can't resolve 'pinia'". Alias to the absolute FILE; the `$`
		// (exact-match) form keeps deep imports going through the exports map.
		pinia$: path.resolve(__dirname, 'node_modules/pinia/dist/pinia.js'),
		// @nextcloud/vue@9, @nextcloud/dialogs@7 and vue-router@5 are ESM-only:
		// their package.json has NO `main` and NO `module`, only an `exports`
		// map. A Vue-2-era alias to the package DIRECTORY bypasses `exports`
		// entirely and then looks for a main/index.js that does not exist, so
		// every import fails with "Can't resolve '@nextcloud/vue'". Alias to
		// the absolute FILE; the `$` (exact-match) form keeps deep imports
		// going through the exports map.
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// @nextcloud/vue@9 hard-depends on vue-router ^5.1.0 while this app is
		// on vue-router 4, so npm installs a SECOND nested copy under
		// node_modules/@nextcloud/vue/node_modules/vue-router. Two router
		// instances mean two different injection keys: NcAppNavigationItem's
		// RouterLink would look up a router this app never provided and
		// navigation dies with no console error. Force every `vue-router`
		// specifier onto this app's single copy.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by the aliased @conduction/nextcloud-vue components
			// (CnCard, CnDataTable, CnAppRoot internals, …) when building
			// against the monorepo-dev source tree.
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
		{
			// Image assets referenced by library components (e.g. Leaflet
			// marker icons pulled in transitively).
			test: /\.(png|jpe?g|gif|svg)$/,
			type: 'asset/resource',
			generator: {
				filename: 'img/[name][ext]',
			},
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules.
// Register the exact-match style.css alias BEFORE the package alias below:
// enhanced-resolve applies the first matching entry, so
// '@nextcloud/dialogs/style.css' (imported by nextcloud-vue's useAppInstaller)
// must be caught first. dialogs v7 ships the stylesheet at dist/style.css
// behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/style.css',
)
// Exact-match, absolute FILE — see the @nextcloud/vue note above; dialogs v7 is
// exports-map-only too, so a directory alias resolves to nothing.
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// @nextcloud/dialogs@7 and @conduction/nextcloud-vue drag in a FilePicker
// chunk that imports node's `path`, and webpack 5 no longer auto-polyfills
// node core modules. Vue 2 got away with `path: false` because it emitted no
// async chunks and the FilePicker code path was never reached; the Vue 3
// dependency set code-splits it, so give it a real polyfill instead of an
// empty module.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: require.resolve('path-browserify'),
}

// `@nextcloud/webpack-vue-config` hardcodes publicPath to `/apps/shillinq/js/`
// and shillinq is installed under `custom_apps/`. `publicPath: 'auto'` makes
// webpack derive the chunk base from the currently-executing script URL, which
// is correct under either apps root. src/setPublicPath.js sets
// `__webpack_public_path__` explicitly as well (it also has to set the CSP
// nonce); belt and braces, because getting this wrong does NOT 404 —
// Nextcloud answers 200 with text/html and the failure surfaces as a MIME
// refusal / ChunkLoadError on lazily-loaded routes only.
webpackConfig.output = {
	...(webpackConfig.output || {}),
	publicPath: 'auto',
}

// Webpack's default TerserPlugin spawns `cpus - 1` worker processes, which
// OOM-kills the build inside the memory-capped WSL VM. Two workers keep peak
// memory bounded while still parallelising minification.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	minimizer: [new TerserPlugin({ parallel: 2 })],
	// frontend-bundle-hygiene REQ-FBH-002 / ADR-061: the base
	// `@nextcloud/webpack-vue-config` only sets
	// `splitChunks.automaticNameDelimiter` — webpack 5's own default
	// `splitChunks.chunks: 'async'` means NONE of this app's three *initial*
	// entries (`main`, `adminSettings`, `widget`) share code today; each
	// independently bundles its own full copy of Vue, @nextcloud/vue,
	// @conduction/nextcloud-vue and Pinia. Pull the shared framework code
	// used by `main`/`adminSettings` into two cached chunks, following
	// pipelinq's `ncVue`/`vendor` cacheGroup split
	// (pipelinq/webpack.config.js:259-295).
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		// `widget` (src/components/widget/WidgetEmbed.js, REQ-WSW-004) is a
		// UMD bundle partner sites embed via ONE `<script src=".../widget.js">`
		// tag. It MUST stay fully self-contained — splitting its framework
		// code into a second chunk would require a third-party page to load a
		// script tag it was never told about. Excluded by name, mirroring
		// openregister's `integrationGlobal` exclusion for the identical
		// reason (openregister/webpack.config.js:60).
		chunks: (chunk) => chunk.name !== 'widget',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// 'initial' ONLY — not 'all'. The outer `chunks` filter above
				// still lets webpack consider async (dynamically-imported)
				// modules for this cacheGroup if it were 'all', which would
				// hoist nc-vue's OWN lazily-loaded chunks (the RVO icon set,
				// the @mdi/js icon-browser bundle behind
				// CnIconBrowserPanel.vue's `import(/* webpackChunkName:
				// "cn-icons-mdi" */ '@mdi/js')`) into this eager chunk,
				// loaded on every page instead of only when their feature is
				// opened — exactly the regression pipelinq's own comment
				// documents hitting with the RVO set and fixing the same way.
				chunks: 'initial',
				// Matches both the npm dist AND the monorepo-dev alias
				// (`../nextcloud-vue/src/...`, resolved outside node_modules
				// when USE_LOCAL_LIB=true aliases @conduction/nextcloud-vue
				// to the sibling source tree above).
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				// minChunks: 2 (NOT the cacheGroup default of 1) — measured, not
				// assumed. `main` (595 pages, most of the nc-vue barrel) and
				// `adminSettings` (a handful of settings screens, a small slice
				// of the barrel) use very different-sized subsets of nc-vue. At
				// minChunks:1 (the first version of this change), the cacheGroup
				// extracted the UNION of both entries' nc-vue usage into one
				// chunk — 6.28 MB — that BOTH entries then had to load in full.
				// Measured effect: adminSettings's own entrypoint total went
				// from 3.34 MiB to 8.44 MiB, a regression, not a win — the exact
				// "moving bytes into a second chunk the same page also loads"
				// trap. minChunks:2 restricts extraction to modules actually
				// reachable from BOTH entries, leaving each entry's own
				// exclusively-used modules where they were. See design.md §6/
				// tasks.md Validation for the corrected before/after numbers.
				minChunks: 2,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				chunks: 'initial',
				test: /[\\/]node_modules[\\/](vue|vue-router|pinia|vue-material-design-icons|@vueuse)[\\/]/,
				priority: 20,
				// Same minChunks:2 correction as the ncVue group above.
				minChunks: 2,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
