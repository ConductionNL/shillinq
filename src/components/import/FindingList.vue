<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  The findings of an import step (validation or posting), errors first.
-->
<template>
	<p v-if="sorted.length === 0" data-testid="import-no-findings">
		{{ t('shillinq', 'No findings.') }}
	</p>
	<ul v-else class="finding-list" data-testid="import-findings">
		<li
			v-for="(finding, index) in sorted"
			:key="index"
			:class="'finding-list__item--' + finding.severity">
			<strong
				>{{
					finding.severity === 'error'
						? t('shillinq', 'Error')
						: t('shillinq', 'Warning')
				}}:</strong
			>
			{{ finding.message }}
		</li>
	</ul>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'FindingList',
	props: {
		findings: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		/**
		 * The findings, errors first.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		sorted() {
			return [...this.findings].sort(
				(a, b) =>
					Number(b?.severity === 'error')
					- Number(a?.severity === 'error'),
			)
		},
	},

	methods: { t },
}
</script>

<style scoped>
.finding-list {
	margin: 0;
	padding-inline-start: 1.25rem;
}

.finding-list__item--error {
	color: var(--color-error-text);
}
</style>
