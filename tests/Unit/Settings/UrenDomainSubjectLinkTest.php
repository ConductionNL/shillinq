<?php

/**
 * UrenRegistratie domain-subject link.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * An hour entry can name the domain object it was worked on.
 *
 * WHY THIS MATTERS AND WHY IT IS TESTED HERE.
 *
 * Before this fragment, `UrenRegistratie` could only be scoped inside
 * Shillinq's own world — projectId, costProjectId, taskId,
 * projectAssignmentId. Hours worked on a procest case had nowhere to record
 * that, so no case could report what it had cost. `subjectApp` + `subjectId`
 * close that, and hydra ADR-081 fixes the division of labour: the domain app
 * CLASSIFIES (procest may show a sum of HOURS on its case) and Shillinq
 * AGGREGATES (the money stays in the app that owns the ledger).
 *
 * The assertions below are about the CONTRACT, not the storage:
 *
 *  1. Both fields must be DECLARED. OpenRegister's MagicMapper discards
 *     properties a schema does not declare — it logs and continues — so an
 *     hour entry POSTed with a subject against an undeclared schema would be
 *     accepted, stored without it, and silently unattributable. That failure
 *     has no runtime symptom, which is why it is pinned.
 *  2. Both must be NULLABLE. Every hour entry that exists today has no
 *     subject; making either required would invalidate the entire historical
 *     ledger on the next validation pass.
 *  3. Neither may be declared as a resolvable relation. The target lives in
 *     ANOTHER app's register, so Shillinq cannot resolve or cascade it — and
 *     an hour entry must survive the domain object being archived. A relation
 *     here would invite exactly the cascade that must not happen. This is
 *     checked against BOTH spellings: the retired per-schema
 *     `relatedSchema` (ADR-062 rule 7 retired that dialect on 2026-07-08) and
 *     the canonical property-level `$ref` that replaced it. Checking only the
 *     retired key would leave this invariant declared but enforced nowhere.
 *
 * @coversNothing
 */
class UrenDomainSubjectLinkTest extends TestCase {

	/**
	 * The merged UrenRegistratie properties, assembled the way the loader does.
	 *
	 * @return array<string, array<string, mixed>> Property name => definition.
	 */
	private function mergedProperties(): array {
		$settings = __DIR__ . '/../../../lib/Settings';

		$sources = [$settings . '/shillinq_register.json'];
		foreach ((array)glob($settings . '/register.d/*.json') as $fragment) {
			$sources[] = $fragment;
		}

		$props = [];
		foreach ($sources as $source) {
			$doc = json_decode((string)file_get_contents($source), associative: true);
			if (is_array($doc) === false) {
				continue;
			}

			$schema = ($doc['components']['schemas']['UrenRegistratie'] ?? null);
			if (is_array($schema) === true && is_array(($schema['properties'] ?? null)) === true) {
				$props = array_merge($props, $schema['properties']);
			}
		}

		return $props;
	}

	/**
	 * Both subject fields are declared, so MagicMapper will not discard them.
	 *
	 * @return void
	 */
	public function testTheSubjectFieldsAreDeclared(): void {
		$props = $this->mergedProperties();

		foreach (['subjectApp', 'subjectId'] as $field) {
			self::assertArrayHasKey(
				$field,
				$props,
				sprintf(
					'UrenRegistratie does not declare "%s". OpenRegister\'s MagicMapper DISCARDS '
					. 'undeclared properties — an hour entry POSTed with a subject would be accepted, '
					. 'stored without it, and silently unattributable to any case.',
					$field
				)
			);
		}
	}

	/**
	 * Both are optional, so the existing ledger stays valid.
	 *
	 * @return void
	 */
	public function testTheSubjectFieldsAreOptionalAndNullable(): void {
		$settings = __DIR__ . '/../../../lib/Settings';

		$required = [];
		$sources = [$settings . '/shillinq_register.json'];
		foreach ((array)glob($settings . '/register.d/*.json') as $fragment) {
			$sources[] = $fragment;
		}

		foreach ($sources as $source) {
			$doc = json_decode((string)file_get_contents($source), associative: true);
			$schema = ($doc['components']['schemas']['UrenRegistratie'] ?? null);
			if (is_array($schema) === true) {
				$required = array_merge($required, (array)($schema['required'] ?? []));
			}
		}

		$props = $this->mergedProperties();

		foreach (['subjectApp', 'subjectId'] as $field) {
			self::assertNotContains(
				$field,
				$required,
				$field . ' must not be required — every hour entry recorded before this change has no '
				. 'subject, and requiring it would invalidate the whole historical ledger.'
			);
			self::assertTrue(
				($props[$field]['nullable'] ?? false),
				$field . ' must be nullable for the same reason.'
			);
		}
	}

	/**
	 * The link is weak: not a resolvable relation, because the target is
	 * another app's.
	 *
	 * Checked against both the retired `relatedSchema` key and the canonical
	 * property-level `$ref` that replaced it (ADR-062 rule 7), so the invariant
	 * stays enforced under the current dialect rather than only the dead one.
	 *
	 * @return void
	 */
	public function testTheSubjectLinkIsWeakRatherThanARelation(): void {
		$props = $this->mergedProperties();

		foreach (['subjectApp', 'subjectId'] as $field) {
			foreach (['relatedSchema', '$ref'] as $relationKey) {
				self::assertArrayNotHasKey(
					$relationKey,
					$props[$field],
					$field . ' must not declare ' . $relationKey . ': the target lives in another app\'s register, '
					. 'so Shillinq cannot resolve or cascade it, and an hour entry must survive the domain '
					. 'object being archived.'
				);
			}
		}
	}
}
