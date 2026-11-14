/**
 * Save as template on an administration (ledger-multi-administration 3.2,
 * REQ-LMA-003): the header action on the administration page opens the
 * dialog for that administration, and the dialog posts the trimmed name to
 * the template endpoint, refusing an empty name and showing the server's
 * refusal as it words it.
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (match, key) => params[key]),
}))
const dialogs = { showError: vi.fn(), showSuccess: vi.fn() }
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => dialogs.showError(...args),
	showSuccess: (...args) => dialogs.showSuccess(...args),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
	getFilePickerBuilder: vi.fn(),
}))
const spawnDialog = vi.fn()
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: (...args) => spawnDialog(...args),
}))

const ROOT = path.resolve(__dirname, '../..')
const { manifestActions } = await import('../../src/manifestActions.js')
const { openSaveAdministrationTemplate, saveAdministrationAsTemplate } =
	await import('../../src/utils/administrationTemplateActions.js')
const Modal = (await import('../../src/modals/SaveAdministrationTemplateModal.vue')).default

/**
 * The modal's state with its methods bound, without mounting it.
 *
 * @param {object} props The props.
 * @return {object} The instance.
 */
function modal(props) {
	const vm = { ...props, $emit: vi.fn() }
	Object.assign(vm, Modal.data.call(vm))
	for (const [name, fn] of Object.entries(Modal.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('the Save as template header action', () => {
	it('is declared on the administration page and registered as a handler', () => {
		const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/bookkeeping-multi-administratie.json'), 'utf8'))
		const page = fragment.pages.find((candidate) => candidate.id === 'AdministratieDetail')
		const action = page.config.headerActions.find((candidate) => candidate.id === 'save-as-template')

		expect(action).toMatchObject({ type: 'handler', handler: 'openSaveAdministrationTemplate' })
		expect(manifestActions.openSaveAdministrationTemplate).toBe(openSaveAdministrationTemplate)
	})

	it('opens the dialog for the page\'s administration, offering its name', () => {
		openSaveAdministrationTemplate({ item: { id: 'adm-7', name: 'Bakkerij De Korst' } })

		expect(spawnDialog).toHaveBeenCalledWith(Modal, { administrationId: 'adm-7', suggestedName: 'Bakkerij De Korst' })
	})
})

describe('saving the template', () => {
	it('posts the name to the administration\'s template endpoint', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 'tpl-1', name: 'Bakery' } })

		await expect(saveAdministrationAsTemplate('adm-7', 'Bakery')).resolves.toEqual({ id: 'tpl-1', name: 'Bakery' })
		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/adm-7/template', { name: 'Bakery' })
	})

	it('saves the trimmed name and closes with the template', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 'tpl-1' } })
		const vm = modal({ administrationId: 'adm-7', suggestedName: '  Bakery  ' })

		await vm.submit()

		expect(axiosMock.post).toHaveBeenCalledWith(expect.any(String), { name: 'Bakery' })
		expect(dialogs.showSuccess).toHaveBeenCalledTimes(1)
		expect(vm.$emit).toHaveBeenCalledWith('close', { id: 'tpl-1' })
	})

	it('refuses an empty name without asking the server', async () => {
		const vm = modal({ administrationId: 'adm-7', suggestedName: '   ' })

		await vm.submit()

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(vm.error).toBe('Give the template a name.')
		expect(vm.$emit).not.toHaveBeenCalled()
	})

	it('shows the server\'s refusal and stays open', async () => {
		axiosMock.post.mockRejectedValue({ response: { data: { error: 'Administration not found' } } })
		const vm = modal({ administrationId: 'adm-9', suggestedName: 'Other' })

		await vm.submit()

		expect(vm.error).toBe('Administration not found')
		expect(vm.submitting).toBe(false)
		expect(vm.$emit).not.toHaveBeenCalled()
	})
})
