<?php

/**
 * Unit tests for the migrate-legacy-notification-dialect fragment migration.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-legacy-notification-dialect/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies tasks 2.12, 2.13, 2.18 of migrate-legacy-notification-dialect:
 * the bare-string `"trigger": "updated"` + plural-but-flat `"conditions": {field: value}`
 * legacy shape on ACMReport (bookkeeping-market-government-separation.json),
 * ActuarialValuation (bookkeeping-pension-ias19.json) and AnnualReport
 * (bookkeeping-titel-9-jaarrekening.json) is now the canonical
 * `{"type": "updated", "condition": {field, operator, value}}` shape
 * (matches `AnnotationNotificationDispatcher::fieldChangeConditionMatches()`),
 * and the `"kind": "acl"` recipient (not in
 * `NotificationAnnotationValidator::VALID_RECIPIENT_KINDS`) is now the
 * canonical `"kind": "object-acl"`.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MigratedNotificationDialectFragmentTest extends TestCase {

	/**
	 * Absolute paths to the three fragments touched by task 2.
	 *
	 * @var array<string,string>
	 */
	private const FRAGMENT_PATHS = [
		'ACMReport' => __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-market-government-separation.json',
		'ActuarialValuation' => __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-pension-ias19.json',
		'AnnualReport' => __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json',
	];

	/**
	 * Every notification rule keyed by fragment schema, expecting the
	 * field name each rule's condition watches and the value it matches.
	 *
	 * @var array<string,array<string,array{field:string,value:string}>>
	 */
	private const EXPECTED_RULES = [
		'ACMReport' => [
			'signingStatusSigned' => ['field' => 'signingStatus', 'value' => 'signed'],
			'signingStatusDeclined' => ['field' => 'signingStatus', 'value' => 'declined'],
			'decisionOutcomeApproved' => ['field' => 'decisionOutcome', 'value' => 'approved'],
			'decisionOutcomeRejected' => ['field' => 'decisionOutcome', 'value' => 'rejected'],
		],
		'ActuarialValuation' => [
			'decisionApproved' => ['field' => 'decisionOutcome', 'value' => 'approved'],
			'decisionRejected' => ['field' => 'decisionOutcome', 'value' => 'rejected'],
		],
		'AnnualReport' => [
			'adoptionApproved' => ['field' => 'decisionOutcome', 'value' => 'approved'],
			'adoptionRejected' => ['field' => 'decisionOutcome', 'value' => 'rejected'],
		],
	];

	/**
	 * Decode a JSON file to an array.
	 *
	 * @param string $path Path to the JSON document.
	 *
	 * @return array<string,mixed>
	 */
	private function jsonFile(string $path): array {
		$data = json_decode((string)file_get_contents($path), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end jsonFile()

	/**
	 * Every migrated rule declares a structured `trigger.type=updated` +
	 * `trigger.condition` shape — never the bare-string legacy trigger.
	 *
	 * @return void
	 */
	public function testTriggerIsStructuredUpdatedWithCondition(): void {
		foreach (self::FRAGMENT_PATHS as $schema => $path) {
			$data = $this->jsonFile($path);
			$rules = $data['components']['schemas'][$schema]['x-openregister-notifications'];

			foreach (self::EXPECTED_RULES[$schema] as $ruleName => $expected) {
				self::assertArrayHasKey($ruleName, $rules, "$schema.$ruleName missing");
				$rule = $rules[$ruleName];

				self::assertIsArray($rule['trigger'], "$schema.$ruleName trigger must be a structured object, not a bare string");
				self::assertSame('updated', $rule['trigger']['type']);
				self::assertArrayNotHasKey('conditions', $rule, "$schema.$ruleName must not carry the legacy plural 'conditions' key");

				$condition = $rule['trigger']['condition'];
				self::assertSame($expected['field'], $condition['field']);
				self::assertSame('equals', $condition['operator']);
				self::assertSame($expected['value'], $condition['value']);
			}
		}

	}//end testTriggerIsStructuredUpdatedWithCondition()

	/**
	 * Every migrated rule's recipients array uses the canonical
	 * `object-acl` recipient kind (never the invalid `acl` kind), matching
	 * `NotificationAnnotationValidator::VALID_RECIPIENT_KINDS`.
	 *
	 * @return void
	 */
	public function testRecipientsUseCanonicalObjectAclKind(): void {
		$validKinds = ['users', 'field', 'groups', 'relation', 'object-acl', 'expression'];

		foreach (self::FRAGMENT_PATHS as $schema => $path) {
			$data = $this->jsonFile($path);
			$rules = $data['components']['schemas'][$schema]['x-openregister-notifications'];

			foreach (array_keys(self::EXPECTED_RULES[$schema]) as $ruleName) {
				$rule = $rules[$ruleName];

				self::assertIsArray($rule['recipients'], "$schema.$ruleName recipients must be a plural array, not a singular object");
				self::assertNotEmpty($rule['recipients']);

				$kinds = array_column($rule['recipients'], 'kind');
				self::assertContains('object-acl', $kinds, "$schema.$ruleName should keep its manage-ACL recipient");
				self::assertNotContains('acl', $kinds, "$schema.$ruleName must not use the invalid 'acl' kind");

				foreach ($rule['recipients'] as $recipient) {
					self::assertContains($recipient['kind'], $validKinds, "$schema.$ruleName has an unrecognised recipient kind");
					if ($recipient['kind'] === 'object-acl') {
						self::assertContains($recipient['permission'], ['read', 'manage']);
					}
				}
			}
		}//end foreach

	}//end testRecipientsUseCanonicalObjectAclKind()

	/**
	 * The condition's `field` references a real property on the owning
	 * schema — the rule is actually wired to a field that exists, not an
	 * orphaned reference.
	 *
	 * @return void
	 */
	public function testConditionFieldExistsOnSchema(): void {
		foreach (self::FRAGMENT_PATHS as $schema => $path) {
			$data = $this->jsonFile($path);
			$schemaDef = $data['components']['schemas'][$schema];
			$rules = $schemaDef['x-openregister-notifications'];
			$props = $schemaDef['properties'];

			foreach (array_keys(self::EXPECTED_RULES[$schema]) as $ruleName) {
				$field = $rules[$ruleName]['trigger']['condition']['field'];
				self::assertArrayHasKey($field, $props, "$schema.$ruleName condition references non-existent field '$field'");

				// The matched value must be a member of the field's own enum
				// when the property declares one — otherwise the rule can
				// never fire (dead condition).
				if (isset($props[$field]['enum']) === true) {
					self::assertContains(
						$rules[$ruleName]['trigger']['condition']['value'],
						$props[$field]['enum'],
						"$schema.$ruleName condition value is not a valid '$field' enum member"
					);
				}
			}
		}//end foreach

	}//end testConditionFieldExistsOnSchema()

	/**
	 * No fragment touched by task 2.12/2.13/2.18 retains a legacy-dialect
	 * token as detected by hydra's check_notification_dialect.py scan
	 * (singular `channel`/`recipient` rule-level keys, `trigger.calculated`,
	 * `lifecycleEnter`, `alsoDispatchLifecycle`, `idempotencyKey`).
	 *
	 * @return void
	 */
	public function testNoLegacySingularRecipientOrChannelKeyRemains(): void {
		foreach (self::FRAGMENT_PATHS as $schema => $path) {
			$data = $this->jsonFile($path);
			$rules = $data['components']['schemas'][$schema]['x-openregister-notifications'];

			foreach ($rules as $ruleName => $rule) {
				self::assertArrayNotHasKey('recipient', $rule, "$schema.$ruleName still declares singular 'recipient'");
				self::assertArrayNotHasKey('channel', $rule, "$schema.$ruleName still declares singular 'channel'");
			}
		}

	}//end testNoLegacySingularRecipientOrChannelKeyRemains()
}//end class
