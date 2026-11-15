/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What a searchable picker over one OpenRegister schema does: turn the records
 * into options, filter them on the search, resolve the selected value and emit
 * what the user picks. The component supplies its props, how a record reads and
 * its translated failure text (loadFailedText).
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

/**
 * The rows in the object endpoint's answer.
 *
 * @param {object} response The answer.
 * @return {Array<object>} The rows, empty when the answer holds no list.
 */
function rowsOf(response) {
	const rows =
		response.data?.results ?? response.data?.objects ?? response.data ?? []
	return Array.isArray(rows) ? rows : []
}

/**
 * A picker mixin for one schema.
 *
 * @param {object} config The picker.
 * @param {string} config.schema The schema slug.
 * @param {string} config.rowsKey The data key holding the records.
 * @param {string} config.optionKey The option key holding the record.
 * @param {function(object): string} config.valueOf The value of a record as an option.
 * @param {function(object): string} config.storedValueOf The value of a record compared with the stored value.
 * @param {function(object): string} config.nameOf The name of a record the search matches.
 * @return {object} The mixin.
 */
export function recordPickerMixin(config) {
	return {
		props: {
			/**
			 * v-model: the selected value (string).
			 */
			modelValue: {
				type: String,
				default: '',
			},
		},

		emits: ['update:modelValue', 'selected'],

		data() {
			return {
				loading: false,
				query: '',
				[config.rowsKey]: [],
				fetchError: '',
			}
		},

		computed: {
			options() {
				return this[config.rowsKey].map((row) => ({
					value: config.valueOf(row),
					display: this.formatDisplay(row),
					[config.optionKey]: row,
				}))
			},

			filteredOptions() {
				const q = (this.query || '').trim().toLowerCase()
				if (!q) {
					return this.options
				}
				return this.options.filter((option) => {
					const value = String(option.value || '').toLowerCase()
					const name = config
						.nameOf(option[config.optionKey])
						.toLowerCase()
					return value.includes(q) || name.includes(q)
				})
			},

			selectedOption() {
				if (!this.modelValue) {
					return null
				}
				const match = this.options.find(
					(option) => option.value === String(this.modelValue),
				)
				if (match) {
					return match
				}
				return {
					value: String(this.modelValue),
					display: String(this.modelValue),
					[config.optionKey]: null,
				}
			},
		},

		methods: {
			onSearch(query) {
				this.query = query || ''
			},

			onSelected(option) {
				if (!option) {
					return
				}
				this.$emit('selected', option[config.optionKey] || null)
			},

			onUpdateModelValue(option) {
				const value = option?.value ?? ''
				this.$emit('update:modelValue', value)
				if (option?.[config.optionKey]) {
					this.$emit('selected', option[config.optionKey])
				}
			},

			emitStoredRecord() {
				if (!this.modelValue) {
					return
				}
				const match = this[config.rowsKey].find(
					(row) => config.storedValueOf(row) === String(this.modelValue),
				)
				if (match) {
					this.$emit('selected', match)
				}
			},

			async loadRecords(params) {
				this.loading = true
				this.fetchError = ''
				try {
					const response = await axios.get(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${config.schema}`,
						),
						{ params },
					)
					this[config.rowsKey] = rowsOf(response)
					this.emitStoredRecord()
				} catch (e) {
					this[config.rowsKey] = []
					this.fetchError =
						e?.response?.data?.error || this.loadFailedText()
				} finally {
					this.loading = false
				}
			},
		},
	}
}
