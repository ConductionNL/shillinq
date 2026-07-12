const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
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
		import: path.join(__dirname, 'src', 'components', 'widget', 'WidgetEmbed.js'),
		filename: 'widget.js',
		library: { name: 'BookingWidget', type: 'umd', export: 'default' },
	},
}

// TEMP: force node_modules — sibling ../nextcloud-vue is beta.111 which lacks
// restartWalkthroughFromSettings / collapsed-nav reveal / $ref dropdowns.
// Revert this comment (keep useLocalLib logic) once the sibling is updated to ≥beta.138.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = false // was: fs.existsSync(localLib)

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
		// Pin vue-demi to its Vue 2.7 ESM entry. pinia 2.3.x and @vueuse
		// statically import `hasInjectionContext` / `TransitionGroup` from
		// vue-demi, which only exist in its Vue 2.7 build. The install-time
		// `vue-demi-switch` postinstall that rewrites lib/index.mjs does not
		// reliably run under `npm ci`, leaving vue-demi's Vue 3 default entry
		// in place and breaking the production build. Aliasing directly to the
		// v2.7 entry makes the build deterministic in CI and locally.
		'vue-demi$': path.resolve(__dirname, 'node_modules/vue-demi/lib/v2.7/index.mjs'),
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
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
// Register the exact-match style.css alias BEFORE the bare package alias below:
// enhanced-resolve applies the first matching entry, and the bare alias maps the
// package to its DIRECTORY, so '@nextcloud/dialogs/style.css' (imported by
// nextcloud-vue's useAppInstaller) would resolve to a non-existent root style.css.
// dialogs v6 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css')
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

// dialogs v6 drags in a FilePicker chunk that imports node's `path`, and webpack 5 no
// longer auto-polyfills node core modules — without this the bundle fails to emit with
// "Can't resolve 'path'". This app only uses the toast APIs (showError/showSuccess), so
// the FilePicker code path never runs and an empty module is safe.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: false,
}

module.exports = webpackConfig
