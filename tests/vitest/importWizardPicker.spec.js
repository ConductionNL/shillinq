/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The import wizard's file picker, built by the REAL FilePickerBuilder of
 * @nextcloud/dialogs as installed. Only the dialog itself is not opened: the
 * built FilePicker's pick() is replaced after the builder has done its work.
 * @nextcloud/dialogs 7 starts a picker with no buttons, so a picker built
 * without addButton() renders no Choose button and nothing can ever be
 * picked (live pass S1, 3 Oct 2026).
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const built = vi.hoisted(() => ({ pickers: [] }))
// The dialogs bundle imports @nextcloud/vue components for the dialog it
// would open. The dialog is never opened here, so they are inert stand-ins;
// the builder and the FilePicker it builds are the installed code.
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
// Browser-only helpers the bundle reads at import: inert as well.
vi.mock('@nextcloud/logger', () => {
	const logger = {
		debug: () => {},
		info: () => {},
		warn: () => {},
		error: () => {},
	}
	const builder = new Proxy(
		{},
		{ get: (target, key) => (key === 'build' ? () => logger : () => builder) },
	)
	return { getLoggerBuilder: () => builder }
})
vi.mock('@nextcloud/capabilities', () => ({ getCapabilities: () => ({}) }))
vi.mock('@nextcloud/event-bus', () => ({
	subscribe: () => {},
	unsubscribe: () => {},
	emit: () => {},
}))
for (const name of [
	'NcButton',
	'NcIconSvgWrapper',
	'NcLoadingIcon',
	'NcDialog',
	'NcNoteCard',
]) {
	vi.doMock('@nextcloud/vue/components/' + name, () => ({ default: { name } }))
}
vi.mock('@nextcloud/dialogs', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		getFilePickerBuilder: (title) => {
			const builder = real.getFilePickerBuilder(title)
			const build = builder.build.bind(builder)
			builder.build = () => {
				const picker = build()
				picker.pick = vi
					.fn()
					.mockResolvedValue('/Migrations/auditfile-2025.xaf')
				built.pickers.push(picker)
				return picker
			}
			return builder
		},
	}
})

const ImportWizard = (await import('../../src/views/import/ImportWizard.vue'))
	.default

beforeEach(() => {
	built.pickers = []
})

describe('the import wizard file picker', () => {
	it('offers a Choose button, so a file can be picked', async () => {
		const vm = { form: { path: '' } }
		await ImportWizard.methods.pickFile.call(vm)

		expect(built.pickers).toHaveLength(1)
		const buttons = built.pickers[0].buttons
		const list =
			typeof buttons === 'function'
				? buttons(
						[{ path: '/Migrations/auditfile-2025.xaf' }],
						'/Migrations',
					)
				: buttons
		expect(list.length).toBeGreaterThan(0)
		expect(list.map((button) => button.label)).toContain('Choose')
		expect(list.find((button) => button.label === 'Choose').variant).toBe(
			'primary',
		)
		expect(vm.form.path).toBe('/Migrations/auditfile-2025.xaf')
	})
})
