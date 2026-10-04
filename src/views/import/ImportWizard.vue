<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  The import wizard (platform-administration-import, REQ-AIW-002): one page
  that takes an administrator from choosing an auditfile in Files, through
  the mapping review, the validation findings and the dry run, to posting.
  Each step moves one ImportBatch through its declared lifecycle; the server
  runs the import pipeline in the transition's action.
-->
<template>
	<div class="import-wizard" data-testid="import-wizard">
		<header class="import-wizard__header">
			<h2>{{ t('shillinq', 'Import wizard') }}</h2>
			<p class="import-wizard__hint">
				{{
					t(
						'shillinq',
						'Move an administration over from another package with its XAF auditfile. Nothing is booked until you choose Post.',
					)
				}}
			</p>
		</header>

		<ol class="import-wizard__steps" :aria-label="t('shillinq', 'Import steps')">
			<li
				v-for="(id, index) in steps"
				:key="id"
				:class="{
					'import-wizard__step--current': id === step,
					'import-wizard__step--done': index < stepIndex,
				}"
				:aria-current="id === step ? 'step' : null">
				{{ stepLabel(id) }}
			</li>
		</ol>

		<NcNoteCard v-if="error" type="error" data-testid="import-wizard-error">
			{{ error }}
		</NcNoteCard>

		<section
			v-if="step === 'upload'"
			class="import-wizard__panel"
			data-testid="import-step-upload">
			<h3>{{ t('shillinq', 'Choose the auditfile') }}</h3>
			<p>
				{{
					t(
						'shillinq',
						'Pick the XAF auditfile from your Files. The file is linked, not copied.',
					)
				}}
			</p>
			<NcButton data-testid="import-pick-file" @click="pickFile">
				{{ t('shillinq', 'Choose file') }}
			</NcButton>
			<p v-if="form.path" data-testid="import-picked-file">
				{{ form.path }}
			</p>
			<div class="import-wizard__actions">
				<NcButton
					variant="primary"
					:disabled="!form.path"
					@click="step = 'profile'">
					{{ t('shillinq', 'Next') }}
				</NcButton>
			</div>
		</section>

		<section
			v-else-if="step === 'profile'"
			class="import-wizard__panel"
			data-testid="import-step-profile">
			<h3>{{ t('shillinq', 'Source package') }}</h3>
			<NcSelect
				v-model="sourceSystem"
				:options="sourceSystems"
				:inputLabel="t('shillinq', 'Package the auditfile comes from')"
				label="label"
				:clearable="false" />
			<div class="import-wizard__field">
				<label for="import-migration-date">{{
					t('shillinq', 'Migration date')
				}}</label>
				<input
					id="import-migration-date"
					v-model="form.migrationDate"
					type="date" />
			</div>
			<NcCheckboxRadioSwitch v-model="form.scope.openingBalance">
				{{ t('shillinq', 'Import the opening balance') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.scope.relations">
				{{ t('shillinq', 'Import customers') }}
			</NcCheckboxRadioSwitch>
			<div class="import-wizard__actions">
				<NcButton :disabled="busy" @click="step = 'upload'">
					{{ t('shillinq', 'Back') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !ready"
					data-testid="import-start"
					@click="start">
					{{ t('shillinq', 'Read the auditfile') }}
				</NcButton>
			</div>
		</section>

		<section
			v-else-if="step === 'mapping'"
			class="import-wizard__panel"
			data-testid="import-step-mapping">
			<h3>{{ t('shillinq', 'Mapping review') }}</h3>
			<p>
				{{
					t('shillinq', 'Accounts to review: {count}', {
						count: open.length,
					})
				}}
			</p>
			<table class="import-wizard__table">
				<caption class="hidden-visually">
					{{
						t('shillinq', 'Account mappings')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('shillinq', 'Source account') }}
						</th>
						<th scope="col">
							{{ t('shillinq', 'Target account') }}
						</th>
						<th scope="col">
							{{ t('shillinq', 'Confirmed') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in mappings"
						:key="row.id"
						data-testid="import-mapping-row">
						<td>{{ row.sourceCode }} {{ row.sourceName }}</td>
						<td>
							{{ row.targetAccount || t('shillinq', 'Not mapped') }}
						</td>
						<td>
							{{
								row.confirmed === true
									? t('shillinq', 'Yes')
									: t('shillinq', 'No')
							}}
						</td>
					</tr>
				</tbody>
			</table>
			<p v-if="unmapped > 0">
				{{
					t(
						'shillinq',
						'Map the remaining accounts in the Mapping list, then come back to this batch.',
					)
				}}
			</p>
			<div class="import-wizard__actions">
				<NcButton
					:disabled="busy || open.length === 0"
					data-testid="import-confirm-suggestions"
					@click="confirmAll">
					{{ t('shillinq', 'Confirm the suggested accounts') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || open.length > 0"
					data-testid="import-validate"
					@click="transition('validate')">
					{{ t('shillinq', 'Validate') }}
				</NcButton>
			</div>
		</section>

		<section
			v-else-if="step === 'validation'"
			class="import-wizard__panel"
			data-testid="import-step-validation">
			<h3>{{ t('shillinq', 'Validation') }}</h3>
			<FindingList :findings="batch?.validationReport?.findings || []" />
			<p
				v-if="batch?.status === 'validation_failed'"
				data-testid="import-validation-failed">
				{{
					t(
						'shillinq',
						'This import cannot be posted. Correct the auditfile or the mapping and start a new import.',
					)
				}}
			</p>
			<div class="import-wizard__actions">
				<NcButton
					variant="primary"
					:disabled="busy || !dryRunAllowed"
					data-testid="import-dry-run"
					@click="transition('dryRun')">
					{{ t('shillinq', 'Run the dry run') }}
				</NcButton>
			</div>
		</section>

		<section
			v-else-if="step === 'dry-run'"
			class="import-wizard__panel"
			data-testid="import-step-dry-run">
			<h3>{{ t('shillinq', 'Dry run') }}</h3>
			<dl class="import-wizard__summary">
				<dt>{{ t('shillinq', 'Opening balance debit') }}</dt>
				<dd data-testid="import-dry-run-debit">
					{{ money(totals.debit) }}
				</dd>
				<dt>{{ t('shillinq', 'Opening balance credit') }}</dt>
				<dd data-testid="import-dry-run-credit">
					{{ money(totals.credit) }}
				</dd>
				<dt>{{ t('shillinq', 'Open items') }}</dt>
				<dd>{{ openItems }}</dd>
				<dt>{{ t('shillinq', 'Relations') }}</dt>
				<dd>{{ (batch?.dryRunReport?.contacts || []).length }}</dd>
			</dl>
			<div class="import-wizard__actions">
				<NcButton
					variant="primary"
					:disabled="busy"
					data-testid="import-post"
					@click="transition('post')">
					{{ t('shillinq', 'Post') }}
				</NcButton>
			</div>
		</section>

		<section v-else class="import-wizard__panel" data-testid="import-step-post">
			<h3>{{ t('shillinq', 'Post') }}</h3>
			<NcNoteCard
				v-if="batch?.status === 'posted'"
				type="success"
				data-testid="import-posted">
				{{ t('shillinq', 'The import is posted with its opening entry.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="batch?.status === 'reversed'" type="info">
				{{ t('shillinq', 'The import is reversed.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="error" data-testid="import-posting-failed">
				{{ t('shillinq', 'The import was not posted.') }}
			</NcNoteCard>
			<FindingList :findings="batch?.postingReport?.findings || []" />
			<div class="import-wizard__actions">
				<NcButton @click="restart">
					{{ t('shillinq', 'Start a new import') }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<script>
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import FindingList from '../../components/import/FindingList.vue'
import { fetchAdministrationContext } from '../../api/administrationApi.js'
import {
	canRunDryRun,
	confirmSuggestions,
	createBatch,
	formComplete,
	loadBatch,
	loadMappings,
	openingTotals,
	openMappings,
	refusalMessage,
	runTransition,
	SOURCE_SYSTEMS,
	stepForBatch,
	WIZARD_STEPS,
} from '../../utils/importWizard.js'

/**
 * A fresh wizard form.
 *
 * @return {object}
 */
function emptyForm() {
	return {
		path: '',
		sourceSystem: 'xaf-generic',
		migrationDate: '',
		administrationId: '',
		scope: { openingBalance: true, relations: true },
	}
}

export default {
	name: 'ImportWizard',
	components: {
		FindingList,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			steps: WIZARD_STEPS,
			step: 'upload',
			form: emptyForm(),
			batch: null,
			mappings: [],
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The position of the current step in the six.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		stepIndex() {
			return this.steps.indexOf(this.step)
		},

		/**
		 * The source packages the wizard offers.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		sourceSystems() {
			return SOURCE_SYSTEMS.map((system) => ({
				id: system.id,
				label:
					system.id === 'xaf-generic'
						? t('shillinq', 'XAF auditfile (any package)')
						: system.label,
			}))
		},

		sourceSystem: {
			/**
			 * The chosen source package as a select option.
			 *
			 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
			 */
			get() {
				return (
					this.sourceSystems.find(
						(system) => system.id === this.form.sourceSystem,
					) || null
				)
			},

			/**
			 * Store the chosen source package.
			 *
			 * @param {{id: string}} option The chosen select option.
			 *
			 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
			 */
			set(option) {
				this.form.sourceSystem = option?.id || ''
			},
		},

		/**
		 * Whether the upload step names everything parse needs.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		ready() {
			return formComplete(this.form)
		},

		/**
		 * The mapping rows still to confirm.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		open() {
			return openMappings(this.mappings)
		},

		/**
		 * How many mapping rows have no target account.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		unmapped() {
			return this.mappings.filter((row) => !row.targetAccount).length
		},

		/**
		 * Whether validation lets the dry run start.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		dryRunAllowed() {
			return canRunDryRun(this.batch)
		},

		/**
		 * The opening balance debit and credit of the dry run.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		totals() {
			return openingTotals(this.batch?.dryRunReport)
		},

		/**
		 * The open items the dry run counted.
		 *
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		openItems() {
			const report = this.batch?.dryRunReport || {}
			return (
				(report.arOpenItems || []).length + (report.apOpenItems || []).length
			)
		},
	},

	/**
	 * Resolve the target administration, then open the batch the address names.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	async mounted() {
		await this.resolveAdministration()
		const id = this.$route?.query?.batch
		if (id) {
			await this.resume(String(id))
		}
	},

	methods: {
		t,

		/**
		 * The active administration is the import's target.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async resolveAdministration() {
			try {
				const context = await fetchAdministrationContext()
				this.form.administrationId = context?.activeAdministrationId || ''
			} catch {
				this.form.administrationId = ''
			}
		},

		/**
		 * The label of a step.
		 *
		 * @param {string} id The step id.
		 * @return {string}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		stepLabel(id) {
			const labels = {
				upload: t('shillinq', 'Choose file'),
				profile: t('shillinq', 'Source package'),
				mapping: t('shillinq', 'Mapping review'),
				validation: t('shillinq', 'Validation'),
				'dry-run': t('shillinq', 'Dry run'),
				post: t('shillinq', 'Post'),
			}
			return labels[id] || id
		},

		/**
		 * Pick the auditfile from Files.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async pickFile() {
			try {
				const picked = await getFilePickerBuilder(
					t('shillinq', 'Choose the auditfile'),
				)
					.setMultiSelect(false)
					.allowDirectories(false)
					// @nextcloud/dialogs 7 starts a picker with no buttons, and a
					// picker without one can never pick (live pass S1).
					.addButton({
						label: t('shillinq', 'Choose'),
						variant: 'primary',
						callback: () => {},
					})
					.build()
					.pick()
				this.form.path = Array.isArray(picked)
					? picked[0] || ''
					: picked || ''
			} catch {
				// Closing the picker chooses nothing.
			}
		},

		/**
		 * Create the batch, read the auditfile and start the mapping review.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async start() {
			await this.guarded(async () => {
				const created = await createBatch(this.form)
				this.remember(created.id)
				this.batch = await runTransition(created.id, 'parse')
				this.batch = await runTransition(this.batch.id, 'startMapping')
				await this.show()
			})
		},

		/**
		 * Confirm every suggested mapping row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async confirmAll() {
			await this.guarded(async () => {
				await confirmSuggestions(this.mappings)
				this.mappings = await loadMappings(this.batch.id)
			})
		},

		/**
		 * Run a lifecycle step on the batch and show where it landed.
		 *
		 * @param {string} action validate, dryRun or post.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async transition(action) {
			await this.guarded(async () => {
				this.batch = await runTransition(this.batch.id, action)
				await this.show()
			})
		},

		/**
		 * Move to the step the batch's status belongs on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async show() {
			this.step = stepForBatch(this.batch)
			if (this.step === 'mapping') {
				this.mappings = await loadMappings(this.batch.id)
			}
		},

		/**
		 * Run a request, showing the refusal it comes back with.
		 *
		 * @param {() => Promise<void>} work The request.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async guarded(work) {
			this.busy = true
			this.error = ''
			try {
				await work()
			} catch (error) {
				this.error = refusalMessage(
					error,
					t('shillinq', 'The step could not be completed.'),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Begin again with an empty form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async restart() {
			this.form = emptyForm()
			this.batch = null
			this.mappings = []
			this.step = 'upload'
			this.remember(null)
			await this.resolveAdministration()
		},

		/**
		 * Open an existing batch on the step its status belongs on (live pass S3).
		 *
		 * @param {string} id The batch id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		async resume(id) {
			this.busy = true
			this.error = ''
			try {
				this.batch = await loadBatch(id)
				await this.show()
			} catch {
				this.batch = null
				this.mappings = []
				this.step = 'upload'
				this.error = t(
					'shillinq',
					'The import batch {id} could not be opened.',
					{ id },
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Keep the batch in the address, so leaving the page does not lose it.
		 *
		 * @param {string|null} id The batch id, or null for a new import.
		 * @return {void}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		remember(id) {
			if (!this.$router) {
				return
			}
			this.$router.replace({ query: id ? { batch: id } : {} })
		},

		/**
		 * An amount in euros.
		 *
		 * @param {number} amount The amount.
		 * @return {string}
		 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
		 */
		money(amount) {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'EUR',
			}).format(amount || 0)
		},
	},
}
</script>

<style scoped>
.import-wizard {
	padding: 1rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
	max-width: 56rem;
}

.import-wizard__hint {
	color: var(--color-text-maxcontrast);
	margin: 0.25rem 0 0 0;
}

.import-wizard__steps {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem 1.5rem;
	padding-inline-start: 1.25rem;
	margin: 0;
}

.import-wizard__step--current {
	font-weight: bold;
}

.import-wizard__step--done {
	color: var(--color-text-maxcontrast);
}

.import-wizard__panel {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.import-wizard__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	max-width: 16rem;
}

.import-wizard__actions {
	display: flex;
	gap: 0.5rem;
}

.import-wizard__table {
	border-collapse: collapse;
	width: 100%;
}

.import-wizard__table th,
.import-wizard__table td {
	text-align: start;
	padding: 0.25rem 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.import-wizard__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 0.25rem 1rem;
}
</style>
