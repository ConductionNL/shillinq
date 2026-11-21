/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The account mapping on a group entity (tax-cbcr-and-pillar2-returns task
 * 6.1, REQ-CBC-002): the entity's page lists the accounts of its
 * administration with the country-by-country category each feeds, and an
 * account's page sets that category. Held to the schema and to what
 * CbcrFiguresService reads, so a renamed property cannot leave the list empty.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const fragment = JSON.parse(read('src/manifest.d/tax-cbcr-and-pillar2-returns.json'))
const vatFragment = JSON.parse(read('src/manifest.d/tax-vat-rates-and-deductibility.json'))
const register = JSON.parse(read('lib/Settings/register.d/tax-cbcr-and-pillar2-returns.json'))
const figures = read('lib/Service/Cbcr/CbcrFiguresService.php')
const nl = JSON.parse(read('l10n/nl.json')).translations

describe('the account mapping of a group entity (REQ-CBC-002)', () => {
	const entity = fragment.pages.find((page) => page.id === 'GroupEntityDetail')
	const mapping = entity.config.relatedCollections.find((collection) => collection.title === 'Account mapping')

	it('lists the accounts of the entity\'s administration in account order, each opening its page', () => {
		expect(mapping).toMatchObject({
			register: 'shillinq',
			schema: 'Account',
			filter: { administrationId: '@object.administrationId' },
			sort: { field: 'accountNumber', dir: 'asc' },
			rowRoute: 'ChartOfAccountsDetail',
		})
		expect(mapping.columns.map((column) => column.key)).toEqual(['accountNumber', 'name', 'accountType', 'cbcrCategory'])
	})

	it('shows the category the figures are read by', () => {
		expect(register.components.schemas.Account.properties.cbcrCategory.enum).toContain('revenue')
		expect(figures).toMatch(/\$account\['cbcrCategory'\]/)
	})

	it('lets the account page set the category', () => {
		const account = vatFragment.pages.find((page) => page.id === 'ChartOfAccountsDetail')
		expect(account.config.fields.map((field) => field.key)).toContain('cbcrCategory')
	})

	it('has Dutch for its title and columns', () => {
		const missing = [mapping.title, ...mapping.columns.map((column) => column.label)].filter((label) => !nl[label])
		expect(missing).toEqual([])
	})
})
