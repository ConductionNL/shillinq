/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The administration is chosen in one place: the header switcher
 * (CnTenantBadge in CnAppRoot's #tenant-badge slot), not a menu page.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-13
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8')

describe('header administration switcher', () => {
	it('mounts CnTenantBadge as a switcher over the administration context and switch endpoints', () => {
		const app = read('src/App.vue')
		expect(app).toMatch(/<template #tenant-badge>[\s\S]*<CnTenantBadge/)
		expect(app).toContain("url: '/apps/shillinq/api/administrations/context'")
		expect(app).toContain('switchUrl="/apps/shillinq/api/administrations/switch"')
		expect(app).toContain('switchParam="administrationId"')
	})

	it('has no switcher page or menu entry left', () => {
		const fragment = JSON.parse(read('src/manifest.d/bookkeeping-multi-administratie.json'))
		expect(fragment.pages.map((p) => p.id)).not.toContain('AdministrationSwitcher')
		expect(JSON.stringify(fragment.menu)).not.toContain('AdministrationSwitcher')
		expect(read('src/registry.js')).not.toContain('AdministrationSwitcherPage')
	})
})
