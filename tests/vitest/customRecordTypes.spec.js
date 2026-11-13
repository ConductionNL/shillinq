/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * platform-custom-record-types: the shown own record types become cards, and
 * the generic /records/:schema pages open only a shown type.
 *
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md
 */

import axios from '@nextcloud/axios'
import { describe, expect, it, vi } from 'vitest'
import ClusterOverview from '../../src/components/cluster-overview/ClusterOverview.vue'
import fragment from '../../src/manifest.d/platform-custom-record-types.json'
import {
	cardSections,
	customRecordGuard,
	fetchShownTypes,
	isShown,
} from '../../src/utils/customRecordTypes.js'

const PARKING = { slug: 'parkeerplaats', title: 'Parkeerplaats', description: 'Rented parking spaces', icon: null }

describe('own record type cards', () => {
	it('draws one card per shown type, opening its list page', () => {
		expect(cardSections([PARKING, { slug: 'voertuig', title: '', description: '' }], 'Own record types')).toEqual([
			{
				id: 'own-record-types',
				label: 'Own record types',
				cards: [
					{ id: 'parkeerplaats', label: 'Parkeerplaats', description: 'Rented parking spaces', route: 'CustomRecords', params: { schema: 'parkeerplaats' } },
					{ id: 'voertuig', label: 'voertuig', description: '', route: 'CustomRecords', params: { schema: 'voertuig' } },
				],
			},
		])
	})

	it('draws no section when nothing is shown', () => {
		expect(cardSections([], 'Own record types')).toEqual([])
	})

	it('links a card with its route params', () => {
		expect(ClusterOverview.methods.cardTo({ route: 'CustomRecords', params: { schema: 'parkeerplaats' } }))
			.toEqual({ name: 'CustomRecords', params: { schema: 'parkeerplaats' } })
		expect(ClusterOverview.methods.cardTo({ route: 'Journals', query: { tab: 'open' } }))
			.toEqual({ name: 'Journals', query: { tab: 'open' } })
		expect(ClusterOverview.methods.cardTo({ route: 'Journals' })).toEqual({ name: 'Journals' })
	})
})

describe('reading the shown types', () => {
	it('returns the types the endpoint answers', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: { types: [PARKING] } })
		await expect(fetchShownTypes()).resolves.toEqual([PARKING])
		expect(get.mock.calls[0][0]).toContain('/apps/shillinq/api/custom-record-types')
		get.mockRestore()
	})

	it('counts a failed read as nothing shown', async () => {
		const get = vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('403'))
		await expect(fetchShownTypes()).resolves.toEqual([])
		get.mockRestore()
	})

	it('matches a slug ignoring case, never an empty one', () => {
		expect(isShown([PARKING], 'Parkeerplaats')).toBe(true)
		expect(isShown([PARKING], 'Invoice')).toBe(false)
		expect(isShown([PARKING], '')).toBe(false)
		expect(isShown([PARKING], undefined)).toBe(false)
	})
})

describe('the own records guard', () => {
	it('lets a shown type through', async () => {
		const guard = customRecordGuard(async () => [PARKING])
		await expect(guard({ name: 'CustomRecords', params: { schema: 'parkeerplaats' } })).resolves.toBe(true)
		await expect(guard({ name: 'CustomRecordDetail', params: { schema: 'parkeerplaats', id: '1' } })).resolves.toBe(true)
	})

	it('sends a shipped or unknown type to the own records page', async () => {
		const guard = customRecordGuard(async () => [PARKING])
		await expect(guard({ name: 'CustomRecords', params: { schema: 'Invoice' } }))
			.resolves.toEqual({ name: 'CustomRecordTypes', query: { unavailable: 'Invoice' } })
		await expect(guard({ name: 'CustomRecordDetail', params: { schema: 'Invoice', id: '7' } }))
			.resolves.toEqual({ name: 'CustomRecordTypes', query: { unavailable: 'Invoice' } })
	})

	it('leaves every other route alone without asking the server', async () => {
		const fetchTypes = vi.fn()
		await expect(customRecordGuard(fetchTypes)({ name: 'Journals', params: {} })).resolves.toBe(true)
		expect(fetchTypes).not.toHaveBeenCalled()
	})
})

describe('the own records fragment', () => {
	it('routes the list and detail pages by the schema in the address', () => {
		const pages = Object.fromEntries(fragment.pages.map((page) => [page.id, page]))
		expect(pages.CustomRecordTypes).toMatchObject({ route: '/records', type: 'custom', component: 'CustomRecordTypes' })
		expect(pages.CustomRecords).toMatchObject({ route: '/records/:schema', type: 'index', config: { register: 'shillinq', schema: '@route.schema', rowRoute: 'CustomRecordDetail' } })
		expect(pages.CustomRecordDetail).toMatchObject({ route: '/records/:schema/:id', type: 'detail', config: { register: 'shillinq', schema: '@route.schema' } })
	})

	it('switches each own record type through the admin endpoint', () => {
		const content = fragment.adminTab.widget.content
		expect(fragment.adminTab.widget.type).toBe('toggle-table')
		expect(content.source).toMatchObject({ url: '/apps/shillinq/api/custom-record-types/available', path: 'types', key: 'slug', label: 'title' })
		expect(content.bind).toEqual({ mode: 'map', url: '/apps/shillinq/api/custom-record-types/available', path: 'shown' })
		expect(content.save).toEqual({ url: '/apps/shillinq/api/custom-record-types', method: 'PATCH', payload: { slug: '{field}', shown: '{value}' } })
	})
})
