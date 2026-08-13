const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

// The `@nextcloud` v8 base is Vue-2 era: on its own it activates ZERO
// `vue/no-deprecated-*` rules, so Vue-2 idioms (`beforeDestroy`, `.sync`,
// `filters:`) survive a green lint — this repo had four live `beforeDestroy`
// hooks (a 30 s setInterval, an event-bus subscription, a live object
// subscription and a camera MediaStream) that Vue 3 ignores outright.
// `conductionVue3Fixes` from @conduction/nextcloud-vue layers the Vue 3 rules
// on top. It is an ARRAY of three configs (shared parserOptions, a `.vue`
// parser layer, and the rule layer), and must be spread LAST so it wins.
// It registers no plugins, which is why it layers cleanly onto the base.
//
// CJS: the extensionless subpath works because the package ships no `exports`
// map. From ESM this would need `/eslint/index.js`.
const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	{
		extends: compat.extends('@nextcloud'),

		settings: {
			'import/resolver': {
				alias: {
					map: [
						['@', './src'],
						[
							'@floating-ui/dom-actual',
							'./node_modules/@floating-ui/dom',
						],
					],
					extensions: ['.js', '.ts', '.vue', '.json', '.css'],
				},
			},
		},

		rules: {
			// Allow unused i18n functions (t, n) — imported for future translation wiring
			'no-unused-vars': [
				'error',
				{ varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' },
			],
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
			'jsdoc/escape-inline-tags': 'off',
			'vue/first-attribute-linebreak': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'import/namespace': 'off',
			'import/default': 'off',
			'import/no-named-as-default': 'off',
			'import/no-named-as-default-member': 'off',
			'import/no-unresolved': [
				'error',
				{ ignore: ['^@conduction/nextcloud-vue'] },
			],
		},
	},
	// Spread LAST so the Vue-3 rules win over the Vue-2 @nextcloud base.
	...conductionVue3Fixes,
	// eslint-config-prettier LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything spread after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule: the whole `vue/no-deprecated-*` family
	// stays present and ON, because prettier has no opinion about them.
	// `indent` is now off HERE and enforced by prettier's `useTabs: true`
	// instead — the same tab, from the tool that also covers CSS and SCSS,
	// which @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
