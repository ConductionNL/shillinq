/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of scripts/check-integration-parity.js, the app-side checker
 * behind hydra gate 24. Each scenario writes a small repository (lib/ PHP
 * faces, src/ registrations, lib/Settings schema JSON) to a scratch directory,
 * runs the script against it, and compares exit code, stdout and stderr with
 * tests/vitest/fixtures/checkIntegrationParity.golden.json, so the verdict,
 * every message and the per-rule assertion counts are pinned.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 */

import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const SCRIPT = path.join(ROOT, 'scripts/check-integration-parity.js')
const GOLDEN = path.join(__dirname, 'fixtures/checkIntegrationParity.golden.json')

/**
 * A PHP class returning one `new LeafDescriptor(...)` with the given arguments.
 *
 * @param {string} name The class name.
 * @param {string} args The named arguments.
 * @return {string} The source.
 */
function leaf(name, args) {
	return `<?php\nfinal class ${name} {\n\tconst LABEL = 'Const label';\n\tconst SURFACES = ['tab', 'widget', ];\n\tpublic function leaf() {\n\t\treturn new LeafDescriptor(${args});\n\t}\n}\n`
}

/**
 * A PHP integration provider with the given getters.
 *
 * @param {string} name The class name.
 * @param {object} getters Method name to returned PHP expression.
 * @return {string} The source.
 */
function provider(name, getters) {
	const methods = Object.entries(getters)
		.map(
			([method, expr]) =>
				`\tpublic function ${method}(): string {\n\t\treturn ${expr};\n\t}\n`,
		)
		.join('')
	return `<?php\nclass ${name} extends AbstractIntegrationProvider {\n${methods}}\n`
}

const SCHEMAS = JSON.stringify({
	components: {
		schemas: {
			plannedItem: {
				slug: 'planned-item',
				properties: { title: {}, date: {}, assignee: {} },
			},
			reference: { properties: { name: {} } },
		},
	},
})

const PASSING = {
	'lib/AppInfo/Application.php':
		'<?php\nclass Application {\n\tpublic const APP_ID = \'shillinq\';\n\tconst GROUP = "grp";\n}\n',
	'lib/Leaf/MountLeaf.php': leaf(
		'MountLeaf',
		"id: 'leaf-mount', kinds: [LeafDescriptor::KIND_RENDER_SURFACE], renderMode: LeafDescriptor::RENDER_MODE_MOUNT, label: self::LABEL, icon: 'icon-a', group: Application::GROUP, requiredApp: Application::APP_ID, surfaces: self::SURFACES",
	),
	'lib/Leaf/DataLeaf.php': leaf(
		'DataLeaf',
		"id: 'leaf-data', kinds: ['data-provider'], label: $this->l10n->t('Data label')",
	),
	'lib/Leaf/Provider.php': provider('Provider', {
		getId: "'prov-b'",
		getLabel: "$this->l10n->t('Provider label')",
		getIcon: "'icon-b'",
		getGroup: 'MountLeaf::LABEL',
		getRequiredApp: 'Application::APP_ID',
		getReferenceType: '$this->type',
	}),
	'lib/Leaf/NamedProvider.php':
		"<?php\nclass NamedProvider implements Foo, IntegrationProviderInterface {\n\tpublic function getId(): string {\n\t\treturn 'prov-named';\n\t}\n\tpublic function getLabel(): string {\n\t\t$x = 1;\n\t}\n}\n",
	'lib/Settings/register.json': SCHEMAS,
	'lib/Settings/broken.json': '{ not json',
	'lib/Settings/other.json': JSON.stringify({ info: {} }),
	'lib/helpers.php': "<?php\nfunction helper() {\n\treturn 'x';\n}\n",
	'src/leaves/mount.js':
		"import { mount, unmount } from './impl.js'\nconst LABEL = 'Const label'\nregisterIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, label: LABEL, icon: `icon-a`, group: t('shillinq', 'grp'), requiredApp: \"shillinq\", surfaces: ['widget', 'tab'], extra: { note: 'x' } })\n",
	'src/leaves/shared-config.js':
		"const SHARED = loadConfig()\nregisterIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, offlineConfig: SHARED })\n",
	'src/leaves/provider.js':
		"const DESCRIPTOR = {\n\tid: 'prov-b',\n\ttab: Tab,\n\twidget: Widget,\n\tlabel: t('Provider label'),\n\t'icon': 'icon-b',\n\tofflineConfig: { ...baseConfig, plannedSchema: 'planned-item', referenceSchema: 'reference', titleField: 'title', \"dateField\": 'date', assigneeField: someVar },\n}\nconst SECOND = {\n\t'a' + 'b': 1,\n\tid: 'prov-c',\n\ttab: Tab,\n\twidget: Widget,\n\tweight: 4 / 2 * 3,\n\tgroup: \"g, (c}\",\n\trequiredApp: `shillinq`,\n\ticon: 'x', /* note */'icon-c',\n}\nOCA.OpenRegister.integrations.register(DESCRIPTOR)\nOCA.OpenRegister.integrations.register(UNKNOWN)\nOCA.OpenRegister.integrations.register( SECOND )\n",
	'src/leaves/icons.js':
		"registerIntegrationIcons({ id: 'not-a-registration' })\n",
	'src/leaves/owned.js':
		"import Default, { fooIntegration as fooIntegration, other } from '@conduction/nextcloud-vue'\nregisterIntegration({ ...fooIntegration, tab: T })\nregisterIntegration({ ...Default, tab: T })\n",
	'src/leaves/library.js':
		"import { fooIntegration, bazIntegration } from '@conduction/nextcloud-vue'\nexport function registerIntegration(descriptor) {\n\treturn descriptor\n}\nregisterIntegration( { ...fooIntegration, ...bazIntegration, tab: T } )\n",
	'src/leaves/panel.vue':
		"<template><div /></template>\n<script>\nregisterIntegration({ id: 'leaf-vue', tab: T, widget: W, label: `Vue, (label` })\n</script>\n",
	'src/leaves/typed.ts':
		"registerIntegration({ id: 'leaf-ts', tab: T, widget: W, label: 'TS' })\n",
	'lib/Leaf/Typed.php':
		leaf(
			'Typed',
			"id: 'leaf-vue', kinds: ['render-surface'], label: 'Vue, (label'",
		)
		+ leaf(
			'TypedTs',
			"'positional', id: 'leaf-ts', kinds: ['render-surface'], weight: 4 / 2 * 3, label: 'TS'",
		).replace('<?php\n', ''),
	'lib/Leaf/ProvC.php':
		'<?php\nclass ProvC extends AbstractIntegrationProvider {\n\tpublic function getId(): string {\n\t\treturn \'prov-c\';\n\t}\n\tpublic function getLabel(): string {\n\t\t$x = 1;\n\t}\n\tpublic function getGroup(): string {\n\t\treturn "g, (c}";\n\t}\n\tpublic function getRequiredApp(): string {\n\t\treturn Application::APP_ID;\n\t}\n}\n',
	'lib/Aaa.php': '<?php\n// no class here\nreturn [];\n',
	'lib/Zzz.php': "<?php\nreturn ['x' => 1];\n",
	'src/leaves/reader.vue':
		"<!-- names 'leaf-hidden' only in a comment -->\n<script>\nexport default { data() { return { leaf: 'leaf-data', other: \"prov-named\" } } }\n</script>\n",
	'src/plain.ts': 'export const x = 1\n',
}

const SCENARIOS = {
	'every rule passes': PASSING,
	'R1 incomplete render pairs': {
		...PASSING,
		'src/leaves/mount.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, label: 'Const label' })\n",
		'src/leaves/component.js':
			"registerIntegration({ id: 'leaf-comp', tab: T })\nregisterIntegration({ id: 'leaf-comp2', renderMode: 'component', widget: W })\n",
		'lib/Leaf/Comp.php':
			leaf('Comp', "id: 'leaf-comp', kinds: ['render-surface']")
			+ leaf('Comp2', "id: 'leaf-comp2', kinds: ['render-surface']").replace(
				'<?php\n',
				'',
			),
	},
	'R2 correlation failures': {
		...PASSING,
		'lib/Leaf/Nested.php': leaf(
			'Nested',
			"new LeafDescriptor(id: 'leaf-nested', kinds: ['data-provider'])",
		),
		'lib/Leaf/NoId.php': leaf(
			'NoId',
			"id: $dynamic, kinds: [], label: 'Server label'",
		),
		'lib/Leaf/Phantom.php': leaf(
			'Phantom',
			"id: 'leaf-phantom', kinds: ['render-surface']",
		),
		'lib/Leaf/Lonely.php': provider('Lonely', { getId: "'prov-lonely'" }),
		'lib/Leaf/Hidden.php': leaf(
			'Hidden',
			"id: 'leaf-hidden', kinds: ['data-provider'] # a comment, here\n",
		),
		'lib/Leaf/NoIdProvider.php': provider('NoIdProvider', {
			getId: '$this->id',
		}),
		'src/leaves/spread-label.js':
			"import { barIntegration } from '@conduction/nextcloud-vue'\nregisterIntegration({ ...barIntegration, label: 'Client label', tab: T, widget: W })\n",
		'src/leaves/orphan.js':
			"registerIntegration({ id: 'leaf-orphan', tab: T, widget: W })\n",
		'src/leaves/commented.js':
			"// 'prov-lonely'\n/* \"prov-lonely\" */\nconst a = 'x'\n",
	},
	'R3 renderMode mismatch': {
		...PASSING,
		'lib/Leaf/Implicit.php': leaf(
			'Implicit',
			"id: 'leaf-implicit', kinds: ['render-surface'], renderMode: 'mount'",
		),
		'src/leaves/implicit.js':
			"registerIntegration({ id: 'leaf-implicit', tab: T, widget: W })\n",
		'src/leaves/mount.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'component', tab: T, widget: W })\n",
	},
	'R4 metadata mismatch': {
		...PASSING,
		'src/leaves/mount.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, label: 'Other', icon: 'icon-a', group: 'other', requiredApp: 'nope', surfaces: ['tab'] })\n",
	},
	'R5 spread sources': {
		...PASSING,
		'src/leaves/spreads.js':
			"import { localIntegration } from './local.js'\nregisterIntegration({ ...missingIntegration, tab: T })\nregisterIntegration({ ...localIntegration })\nregisterIntegration({ tab: T, widget: W })\n",
	},
	'R6 offlineConfig failures': {
		...PASSING,
		'src/leaves/provider.js':
			"registerIntegration({ id: 'prov-b', tab: T, widget: W, label: 'Provider label', offlineConfig: { plannedSchema: 'planned-item', resultSchema: 'nope', titleField: 'title', assigneeField: 'missing', templateRefField: 'x' } })\n",
		'src/leaves/second.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, offlineConfig: { plannedSchema: 'absent', dateField: 'date' } })\n",
		'src/leaves/third.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, offlineConfig: { dateField: 'date' } })\n",
	},
	'R6 without schema files': {
		...Object.fromEntries(
			Object.entries(PASSING).filter(
				([file]) => file.startsWith('lib/Settings/') === false,
			),
		),
		'src/leaves/zz-config.js':
			"registerIntegration({ id: 'leaf-mount', renderMode: 'mount', mount, unmount, offlineConfig: { dateField: 'date' } })\n",
	},
	'comments, quotes and unbalanced text': {
		...PASSING,
		'lib/Leaf/Lex.php': leaf(
			'Lex',
			"id: 'leaf-lex', /* block, comment ) */kinds: ['render-surface'], label: 'It\\'s (here', // line, comment )\n icon: \"a\\\"b, (\", group: 'g' # hash, comment )\n, surfaces: [ 'tab', /* c, */'widget' ] /* end */",
		),
		'lib/Leaf/Open.php':
			"<?php\nclass Open {\n\tconst LIST = ['a', 'b'];\n\tpublic function leaf() {\n\t\treturn new LeafDescriptor(id: 'leaf-open', kinds: ['render-surface'] /* never closed",
		'src/leaves/lex.js':
			"registerIntegration({ id: 'leaf-lex', // comment, }\n /* g */'group': 'g', label: 'It\\'s (here', /* , } */ tab: T, widget: W, icon: \"a\\\"b, (\", surfaces: [`tab`, /* c */'widget'] })\nregisterIntegration({ id: 'leaf-unclosed', tab: T // never closed",
	},
	'nothing to check': {
		'lib/Empty.php': '<?php\nclass Empty {}\n',
		'src/empty.js': 'export default {}\n',
	},
	'no lib and no src': {
		'README.md': 'nothing here\n',
	},
}

/**
 * Output lines in sorted order. The script reports files in directory-read
 * order, which differs between filesystems, so the record pins every line but
 * not their order.
 *
 * @param {string} text The output.
 * @return {string[]} Its lines, sorted.
 */
function sortedLines(text) {
	return text.split('\n').sort()
}

/**
 * Write a scenario's files to a scratch root and run the script there.
 *
 * @param {object} files Relative path to content.
 * @return {object} Exit code and output.
 */
function runScenario(files) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'sh-parity-'))
	try {
		for (const [file, content] of Object.entries(files)) {
			fs.mkdirSync(path.dirname(path.join(root, file)), { recursive: true })
			fs.writeFileSync(path.join(root, file), content)
		}
		const result = spawnSync(process.execPath, [SCRIPT, root], {
			encoding: 'utf8',
			timeout: 20000,
		})
		return {
			status: result.status,
			stdout: sortedLines(result.stdout),
			stderr: sortedLines(result.stderr),
		}
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
}

describe('check-integration-parity', () => {
	it('matches the golden record', () => {
		const recorded = {}
		for (const [name, files] of Object.entries(SCENARIOS)) {
			recorded[name] = runScenario(files)
		}
		if (process.env.SHILLINQ_GOLDEN_RECORD === '1') {
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(recorded).toEqual(JSON.parse(fs.readFileSync(GOLDEN, 'utf8')))
	})
})
