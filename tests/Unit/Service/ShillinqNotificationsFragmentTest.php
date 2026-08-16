<?php

/**
 * Unit tests for the shillinq-notifications register fragment.
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
 * @spec openspec/changes/shillinq-notifications/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the kind:config shillinq-notifications fragment overlays the
 * AR/PO notification rules onto the chained financial schemas after
 * the AR-core and PO-3-way schemas landed on development.
 *
 * Covers tasks 2.1 (ARInvoice overdue), 2.2 (ARInvoice paid),
 * 2.6 (PurchaseOrder submitted), 2.7 (PurchaseOrder approved),
 * 3.1 (validator-conformant shape) and 3.2 (bilingual + metadata-only).
 */
final class ShillinqNotificationsFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/shillinq-notifications.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Absolute path to the PurchaseOrder schemas fragment (declares the
	 * register the PO rules attach to).
	 *
	 * @var string
	 */
	private string $purchaseOrderFragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * The EFFECTIVE register config: the monolith with EVERY register.d
	 * fragment merged over it, which is what
	 * `SettingsService::loadRegisterConfigData()` hands to the importer.
	 *
	 * Needed for any question about a schema a fragment owns — the monolith
	 * alone cannot answer one, and the retired top-level `components.<Name>`
	 * path that used to look like an answer was never imported.
	 *
	 * @return array<string,mixed> The merged config.
	 */
	private function effective(): array {
		$merged = $this->jsonFile($this->registerPath);
		$fragments = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		self::assertNotEmpty($fragments, 'No register.d fragments found — the merge would be a no-op.');
		sort($fragments);

		foreach ($fragments as $fragment) {
			$merged = $this->merge(base: $merged, overlay: $this->jsonFile($fragment));
		}

		return $merged;
	}//end effective()

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
	 * The fragment file is present and valid JSON with the AR + PO overlays.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJsonWithBothOverlays(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->jsonFile($this->fragmentPath);

		// The AR rules live under components.schemas since 42ae3b7b — the
		// top-level components.ARInvoice sibling was dead config OR's
		// ImportHandler never read (it iterates components.schemas only).
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('ARInvoice', $data['components']['schemas']);
		self::assertArrayHasKey('x-openregister-notifications', $data['components']['schemas']['ARInvoice']);

		self::assertArrayHasKey('PurchaseOrder', $data['components']['schemas']);
		self::assertArrayHasKey('x-openregister-notifications', $data['components']['schemas']['PurchaseOrder']);

	}//end testFragmentIsValidJsonWithBothOverlays()

	/**
	 * ARInvoice overdue is a scheduled rule with the validator-required
	 * intervalSec >= 86400 and filters on state + dueDate (REQ tasks 2.1).
	 *
	 * @return void
	 */
	public function testArInvoiceOverdueIsScheduledDaily(): void {
		$data = $this->jsonFile($this->fragmentPath);
		$rule = $data['components']['schemas']['ARInvoice']['x-openregister-notifications']['overdue'];

		self::assertSame('scheduled', $rule['trigger']['type']);
		self::assertGreaterThanOrEqual(86400, $rule['trigger']['intervalSec']);

		// Canonical scheduled-filter grammar since 42ae3b7b: a map of
		// field => scalar equality. lifecycleState=overdue already encodes
		// "past due date, not paid/written-off" — the old {all:[...]}
		// notIn/before grammar was never understood by OR's validator.
		self::assertSame(['lifecycleState' => 'overdue'], $rule['trigger']['filter']);

	}//end testArInvoiceOverdueIsScheduledDaily()

	/**
	 * ARInvoice paid uses updated + field-change condition on state=paid
	 * (REQ task 2.2).
	 *
	 * @return void
	 */
	public function testArInvoicePaidIsUpdatedConditionRule(): void {
		$data = $this->jsonFile($this->fragmentPath);
		$rule = $data['components']['schemas']['ARInvoice']['x-openregister-notifications']['paid'];

		self::assertSame('updated', $rule['trigger']['type']);

		$condition = $rule['trigger']['condition'];
		// ARInvoice's lifecycle field is lifecycleState (42ae3b7b modernised
		// the rule away from the non-existent `state` field).
		self::assertSame('lifecycleState', $condition['field']);
		self::assertSame('equals', $condition['operator']);
		self::assertSame('paid', $condition['value']);

	}//end testArInvoicePaidIsUpdatedConditionRule()

	/**
	 * PurchaseOrder submitted uses created + recipient kind=field requester
	 * + procurement group (REQ task 2.6).
	 *
	 * @return void
	 */
	public function testPurchaseOrderSubmittedFiresOnCreatedToRequester(): void {
		$data = $this->jsonFile($this->fragmentPath);
		$rule = $data['components']['schemas']['PurchaseOrder']['x-openregister-notifications']['submitted'];

		self::assertSame('created', $rule['trigger']['type']);

		$recipients = $rule['recipients'];
		self::assertCount(2, $recipients);
		self::assertSame('field', $recipients[0]['kind']);
		self::assertSame('requester', $recipients[0]['field']);
		self::assertSame('groups', $recipients[1]['kind']);
		self::assertContains('shillinq-procurement', $recipients[1]['groups']);

	}//end testPurchaseOrderSubmittedFiresOnCreatedToRequester()

	/**
	 * PurchaseOrder approved uses updated + condition statusCode=approved
	 * (REQ task 2.7). NOTE: schema field is `statusCode` (not `status`).
	 *
	 * @return void
	 */
	public function testPurchaseOrderApprovedTargetsStatusCodeField(): void {
		$data = $this->jsonFile($this->fragmentPath);
		$rule = $data['components']['schemas']['PurchaseOrder']['x-openregister-notifications']['approved'];

		self::assertSame('updated', $rule['trigger']['type']);

		$condition = $rule['trigger']['condition'];
		self::assertSame('statusCode', $condition['field']);
		self::assertSame('equals', $condition['operator']);
		self::assertSame('approved', $condition['value']);

		$recipients = $rule['recipients'];
		self::assertCount(1, $recipients);
		self::assertSame('field', $recipients[0]['kind']);
		self::assertSame('requester', $recipients[0]['field']);

	}//end testPurchaseOrderApprovedTargetsStatusCodeField()

	/**
	 * Every subject is bilingual nl/en and metadata-only — no line content,
	 * no monetary totals, only invoice/PO number + dueDate (REQ task 3.2).
	 *
	 * @return void
	 */
	public function testAllSubjectsAreBilingualAndMetadataOnly(): void {
		$data = $this->jsonFile($this->fragmentPath);

		$rules = [];
		foreach ($data['components']['schemas']['ARInvoice']['x-openregister-notifications'] as $name => $rule) {
			$rules['ARInvoice.' . $name] = $rule['subject'];
		}
		foreach ($data['components']['schemas']['PurchaseOrder']['x-openregister-notifications'] as $name => $rule) {
			$rules['PurchaseOrder.' . $name] = $rule['subject'];
		}

		self::assertCount(4, $rules);

		$allowedTokens = ['invoiceNumber', 'dueDate', 'poNumber'];

		foreach ($rules as $name => $subject) {
			self::assertArrayHasKey('nl', $subject, $name . ' missing nl subject');
			self::assertArrayHasKey('en', $subject, $name . ' missing en subject');
			self::assertNotSame('', $subject['nl']);
			self::assertNotSame('', $subject['en']);

			// Metadata-only — every placeholder must be on the allow-list.
			foreach (['nl', 'en'] as $locale) {
				preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $subject[$locale], $matches);
				foreach ($matches[1] as $token) {
					self::assertContains(
						$token,
						$allowedTokens,
						sprintf('%s.%s uses non-metadata token {{%s}}', $name, $locale, $token)
					);
				}
			}
		}

	}//end testAllSubjectsAreBilingualAndMetadataOnly()

	/**
	 * Every rule declares at least one valid channel and at least one
	 * recipient — matches the OR NotificationAnnotationValidator's
	 * required-keys contract without booting NC (REQ task 3.1).
	 *
	 * @return void
	 */
	public function testEveryRuleHasChannelAndRecipients(): void {
		$data = $this->jsonFile($this->fragmentPath);
		$validKinds = ['users', 'field', 'groups', 'relation', 'object-acl', 'expression'];
		$validChannel = ['nc-notification', 'email', 'activity', 'webhook', 'talk'];

		$rules = array_merge(
			$data['components']['schemas']['ARInvoice']['x-openregister-notifications'],
			$data['components']['schemas']['PurchaseOrder']['x-openregister-notifications']
		);

		foreach ($rules as $name => $rule) {
			self::assertArrayHasKey('channels', $rule, $name . ' missing channels');
			self::assertNotEmpty($rule['channels']);
			foreach ($rule['channels'] as $channel) {
				self::assertContains($channel, $validChannel, $name . ' has bad channel ' . $channel);
			}

			self::assertArrayHasKey('recipients', $rule, $name . ' missing recipients');
			self::assertNotEmpty($rule['recipients']);
			foreach ($rule['recipients'] as $recipient) {
				self::assertArrayHasKey('kind', $recipient);
				self::assertContains($recipient['kind'], $validKinds, $name . ' has bad recipient kind');
				if ($recipient['kind'] === 'object-acl') {
					self::assertContains($recipient['permission'], ['read', 'manage']);
				}
			}
		}

	}//end testEveryRuleHasChannelAndRecipients()

	/**
	 * Fragment merges additively onto the AR + PO schemas:
	 * the new rule names do not clobber any pre-existing rules
	 * (the AP/PaymentRun rules already shipped in the monolith).
	 *
	 * @return void
	 */
	public function testFragmentOverlaysWithoutClobber(): void {
		$monolith = $this->jsonFile($this->registerPath);
		$poFrag = $this->jsonFile($this->purchaseOrderFragmentPath);

		// The AR + PO schemas exist post-merge with their existing fields.
		$merged = $this->merge(base: $monolith, overlay: $poFrag);
		$merged = $this->merge(base: $merged, overlay: $this->jsonFile($this->fragmentPath));

		// ARInvoice's overdue/paid rules are now present (under
		// components.schemas since 42ae3b7b — the only branch OR reads).
		$arRules = $merged['components']['schemas']['ARInvoice']['x-openregister-notifications'];
		self::assertArrayHasKey('overdue', $arRules);
		self::assertArrayHasKey('paid', $arRules);

		// PurchaseOrder gains the submitted/approved rules.
		$poRules = $merged['components']['schemas']['PurchaseOrder']['x-openregister-notifications'];
		self::assertArrayHasKey('submitted', $poRules);
		self::assertArrayHasKey('approved', $poRules);

		// APInvoice keeps the approvalNeeded rule from the monolith (AP-core).
		// Read from components.schemas — the only branch OpenRegister's
		// ImportHandler iterates. APInvoice used to sit one level above it, at
		// the monolith's top-level `components.APInvoice`, so this assertion
		// was reading a rule the importer never loaded; the schema has since
		// been moved and the rule has to be found where it now lives.
		// NOTE: PaymentRun does not currently carry x-openregister-notifications
		// — the description that said "PaymentRun rules were already published"
		// was premature; that notification set was never shipped.
		self::assertArrayHasKey(
			'approvalNeeded',
			$merged['components']['schemas']['APInvoice']['x-openregister-notifications']
		);

		// The `requester` field referenced by both PO rules exists on
		// the PurchaseOrder schema declared by the chained change.
		self::assertArrayHasKey(
			'requester',
			$merged['components']['schemas']['PurchaseOrder']['properties']
		);

		// The lifecycle field referenced by AR's paid rule + the `dueDate`
		// field referenced by AR's overdue filter exist on ARInvoice.
		//
		// ARInvoice is owned by a register.d fragment, not by the monolith,
		// so the three-document merge above cannot answer this — and the
		// retired `components.ARInvoice` path these two assertions used to
		// read was never loaded by the importer at all. Answered against the
		// full effective config, which is what OpenRegister receives.
		//
		// The field is `lifecycleState`, not `state`. `state` was a property
		// of the retired schema version that lived at the dead path; the live
		// ARInvoice has never had it. Both rules in this fragment filter on
		// `lifecycleState` (overdue: trigger.filter, paid: trigger.condition),
		// so asserting `state` checked a name nothing uses against a schema
		// nothing loads. Read the rules' own field names off the fragment so
		// this cannot drift again.
		$arInvoice = $this->effective()['components']['schemas']['ARInvoice']['properties'];
		$arRuleSet = $this->jsonFile($this->fragmentPath)['components']['schemas']['ARInvoice']['x-openregister-notifications'];

		$referencedFields = [
			array_key_first($arRuleSet['overdue']['trigger']['filter']),
			$arRuleSet['paid']['trigger']['condition']['field'],
		];

		foreach ($referencedFields as $field) {
			self::assertArrayHasKey(
				$field,
				$arInvoice,
				'AR notification rule references field "' . $field . '", which ARInvoice does not declare.'
			);
		}

		self::assertContains('lifecycleState', $referencedFields);
		self::assertArrayHasKey('dueDate', $arInvoice);

	}//end testFragmentOverlaysWithoutClobber()

}//end class
