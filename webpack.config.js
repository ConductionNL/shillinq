const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const TerserPlugin = require('terser-webpack-plugin')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

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
if (useLocalLib && fs.existsSync(localLibPkg)) {
	const localVersion = String(
		JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '',
	)
	const localMajor = parseInt(localVersion, 10)
	if (!Number.isNaN(localMajor) && localMajor < 2) {
		// eslint-disable-next-line no-console
		console.warn(
			`[shillinq] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ 'that is the Vue 2 line and this app is Vue 3. Building against the npm dist.',
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
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
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
}

module.exports = webpackConfig
