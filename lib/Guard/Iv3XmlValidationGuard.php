<?php

/**
 * Iv3 XML Validation Guard
 *
 * Lifecycle precondition for the Iv3Export schema's `validate` and
 * `revalidate` transitions (lib/Settings/shillinq_register.json,
 * REQ-IV3 CBS Investeringen/verplichtingen export). ADR-031 exception-path
 * PHP guard: whether the generated XML is actually ready to be validated is
 * a completeness check the declarative lifecycle DSL cannot express.
 *
 * shillinq#425: this class did not exist prior to this change, so every
 * `validate`/`revalidate` transition on Iv3Export threw a RuntimeException
 * from OpenRegister's LifecycleGuardRegistry::resolve() (fail-hard, not a
 * silent bypass — confirmed by reading LifecycleGuardRegistry::resolve()
 * and LifecycleValidationListener::handle(), neither of which catches it).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

/**
 * Guards Iv3Export `validate`/`revalidate` — the export must actually have a
 * generated XML document and at least one aggregated bucket before an
 * operator can declare it CBS-schema-valid.
 *
 * Referenced by name from the Iv3Export schema's x-openregister-lifecycle
 * `requires:` clauses (via the shared RegisterRequiresGuardAdapter — see
 * lib/Lifecycle/RegisterRequiresGuardAdapter.php).
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class Iv3XmlValidationGuard {
	/**
	 * Precondition for `validate`/`revalidate`: the export must carry a
	 * materialised XML attachment and at least one aggregated bucket.
	 *
	 * Fail-closed is implicit here — there is no external I/O, so this is a
	 * pure data-completeness check with no exception path to fail closed on.
	 *
	 * @param array<string, mixed> $export The Iv3Export object being transitioned.
	 *
	 * @return bool True when the export may be marked validated.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireValidXml(array $export): bool {
		$xmlUri = trim((string)($export['xmlAttachmentUri'] ?? ''));
		if ($xmlUri === '') {
			return false;
		}

		$buckets = ($export['buckets'] ?? null);
		if (is_array($buckets) === false || $buckets === []) {
			return false;
		}

		return true;
	}//end requireValidXml()
}//end class
