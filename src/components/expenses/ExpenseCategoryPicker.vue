<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Expense category picker on the receipt form (expenses-category-mapping
 REQ-ECM-002). Offers only the active categories of the receipt's own
 administration, named in the reader's language with the code beside it,
 and stores the category code in Receipt.category.

 Uses NcSelect with an explicit `input-label` (hydra-gate-nc-input-labels).

 @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
-->
<template>
	<NcSelect
		:modelValue="selectedOption"
		:options="filteredOptions"
		:loading="loading"
		:inputLabel="t('shillinq', 'Expense category')"
		:placeholder="t('shillinq', 'Choose an expense category')"
		:filterable="true"
		:clearable="false"
		label="display"
		trackBy="value"
		data-testid="expense-category-picker"
		@search="onSearch"
		@option:selected="onSelected"
		@update:modelValue="onUpdateModelValue" />
</template>

<script>
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { categoryName, pickableCategories } from '../../utils/expenseCategories.js'
import { recordPickerMixin } from '../BudgetBBVMapping/recordPickerMixin.js'

export default {
	name: 'ExpenseCategoryPicker',
	components: { NcSelect },
	mixins: [
		recordPickerMixin({
			schema: 'ExpenseCategory',
			rowsKey: 'categories',
			optionKey: 'category',
			valueOf: (category) => String(category.code ?? ''),
			storedValueOf: (category) => String(category.code ?? ''),
			nameOf: (category) => categoryName(category, getLanguage()),
		}),
	],

	props: {
		/** The receipt's administration; only its categories are offered. */
		administrationId: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The active categories of the administration as options.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
		 */
		options() {
			return pickableCategories(this.categories, this.administrationId).map((category) => ({
				value: String(category.code),
				display: this.formatDisplay(category),
				category,
			}))
		},
	},

	watch: {
		administrationId() {
			this.fetchCategories()
		},
	},

	async created() {
		await this.fetchCategories()
	},

	methods: {
		t,

		/**
		 * The option text: the name in the reader's language and the code.
		 *
		 * @param {object} category An ExpenseCategory record.
		 * @return {string} The text.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
		 */
		formatDisplay(category) {
			const name = categoryName(category, getLanguage())
			return name === String(category.code) ? name : `${name} (${category.code})`
		},

		/**
		 * The translated text shown when the categories cannot be loaded.
		 *
		 * @return {string} The text.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
		 */
		loadFailedText() {
			return this.t('shillinq', 'Failed to load expense categories.')
		},

		/**
		 * Load the administration's active categories.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
		 */
		async fetchCategories() {
			const params = { _limit: 500, active: true }
			if (this.administrationId) {
				params.administrationId = this.administrationId
			}
			await this.loadRecords(params)
		},
	},
}
</script>
