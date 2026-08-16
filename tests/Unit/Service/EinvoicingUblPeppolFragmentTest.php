<?php

/**
 * Unit tests for the add-shillinq-einvoicing-ubl-peppol register fragment.
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
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-011
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
 * Verifies the fragment is valid JSON, additively declares the ARInvoice
 * delivery-status field group (REQ-AR-011) + CustomerMaster.peppolParticipantId
 * without redefining `x-openregister-lifecycle` (would collide with the
 * canonical lifecycleState lifecycle — see fragment's _meta.description), and
 * ships internally consistent seed data (required-field completeness).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EinvoicingUblPeppolFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-einvoicing-ubl-peppol.json';

	/**
	 * Absolute path to the fragment that declares ARInvoice's base fields
	 * (invoiceNumber, customerId, ...) — ARInvoice is fragment-only, not in
	 * the monolith, so the merge-without-clobbering test overlays onto this
	 * fragment rather than the monolith.
	 *
	 * @var string
	 */
	private string $baseArInvoiceFragmentPath = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json';

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
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$fragment = $this->fragment();

		self::assertIsArray($fragment);
		self::assertArrayHasKey('components', $fragment);
		self::assertArrayHasKey('schemas', $fragment['components']);
		self::assertArrayHasKey('ARInvoice', $fragment['components']['schemas']);
		self::assertArrayHasKey('CustomerMaster', $fragment['components']['schemas']);

	}//end testFragmentIsValidJson()

	/**
	 * REQ-AR-011: ARInvoice declares the four additive delivery fields, all
	 * optional, deliveryStatus defaulting to not-sent.
	 *
	 * @return void
	 */
	public function testARInvoiceDeclaresDeliveryFields(): void {
		$props = $this->fragment()['components']['schemas']['ARInvoice']['properties'];

		foreach (['deliveryStatus', 'transmissionId', 'payloadFileUri', 'deliveryDetail', 'buyerPeppolParticipantId'] as $field) {
			self::assertArrayHasKey($field, $props, "ARInvoice must declare $field");
			self::assertTrue(($props[$field]['nullable'] ?? false), "$field must be optional/nullable");
		}

		self::assertSame('not-sent', $props['deliveryStatus']['default']);
		self::assertSame(
			['not-sent', 'queued', 'sent', 'delivered', 'rejected', 'failed'],
			$props['deliveryStatus']['enum']
		);

	}//end testARInvoiceDeclaresDeliveryFields()

	/**
	 * The fragment does NOT declare a second x-openregister-lifecycle block on
	 * ARInvoice — OR supports exactly one per schema and a second would
	 * collide with the canonical lifecycleState lifecycle (see fragment
	 * _meta.description + tasks.md Deviations).
	 *
	 * @return void
	 */
	public function testFragmentDoesNotDeclareASecondLifecycleBlock(): void {
		$arInvoice = $this->fragment()['components']['schemas']['ARInvoice'];

		self::assertArrayNotHasKey(
			'x-openregister-lifecycle',
			$arInvoice,
			'a second x-openregister-lifecycle on ARInvoice would deep-merge-collide with the canonical lifecycleState lifecycle'
		);
		// The documentation-only substitute is present instead.
		self::assertArrayHasKey('x-shillinq-delivery-substates', $arInvoice);
		self::assertSame('deliveryStatus', $arInvoice['x-shillinq-delivery-substates']['field']);

	}//end testFragmentDoesNotDeclareASecondLifecycleBlock()

	/**
	 * CustomerMaster declares the additive, optional peppolParticipantId field.
	 *
	 * @return void
	 */
	public function testCustomerMasterDeclaresPeppolParticipantId(): void {
		$props = $this->fragment()['components']['schemas']['CustomerMaster']['properties'];

		self::assertArrayHasKey('peppolParticipantId', $props);
		self::assertTrue($props['peppolParticipantId']['nullable']);

	}//end testCustomerMasterDeclaresPeppolParticipantId()

	/**
	 * The fragment merges additively onto the ARInvoice-owning fragment
	 * (add-shillinq-bookkeeping-compliance.json — ARInvoice is fragment-only,
	 * not in the monolith) without disturbing its pre-existing property set
	 * (ADR-037 disjoint union) or its canonical lifecycleState lifecycle.
	 *
	 * @return void
	 */
	public function testFragmentMergesWithoutClobberingBaseRegister(): void {
		$base = json_decode((string)file_get_contents($this->baseArInvoiceFragmentPath), true);
		$merged = $this->merge($base, $this->fragment());
		$ar = $merged['components']['schemas']['ARInvoice'];
		$props = $ar['properties'];

		// New fields present.
		self::assertArrayHasKey('deliveryStatus', $props);
		self::assertArrayHasKey('buyerPeppolParticipantId', $props);
		// Pre-existing fields untouched.
		self::assertArrayHasKey('invoiceNumber', $props);
		self::assertArrayHasKey('customerId', $props);
		// The canonical lifecycle is untouched — still keyed on lifecycleState.
		self::assertArrayHasKey('x-openregister-lifecycle', $ar);
		self::assertSame('lifecycleState', $ar['x-openregister-lifecycle']['field']);

	}//end testFragmentMergesWithoutClobberingBaseRegister()

	/**
	 * Seed data: every ARInvoice seed object carries the schema's required
	 * fields (invoiceNumber, customerId, administrationId, invoiceDate,
	 * dueDate, grossAmount, netAmount, currency, periodId, lifecycleState)
	 * plus a realistic deliveryStatus value.
	 *
	 * @return void
	 */
	public function testSeedArInvoicesCarryRequiredFieldsAndDeliveryStatus(): void {
		$required = ['invoiceNumber', 'customerId', 'administrationId', 'invoiceDate', 'dueDate', 'grossAmount', 'netAmount', 'currency', 'periodId', 'lifecycleState'];
		$objects = $this->fragment()['components']['objects'];

		$arInvoices = array_values(
			array_filter(
				$objects,
				static fn (array $o): bool => (($o['@self']['schema'] ?? '') === 'ARInvoice')
			)
		);

		self::assertCount(3, $arInvoices, 'design.md seed table specifies exactly 3 ARInvoice objects');

		foreach ($arInvoices as $invoice) {
			foreach ($required as $field) {
				self::assertArrayHasKey($field, $invoice, 'seed ARInvoice ' . ($invoice['invoiceNumber'] ?? '?') . " is missing required field $field");
			}

			self::assertContains(
				$invoice['deliveryStatus'],
				['not-sent', 'queued', 'sent', 'delivered', 'rejected', 'failed']
			);
		}

		$bySlug = [];
		foreach ($arInvoices as $invoice) {
			$bySlug[$invoice['invoiceNumber']] = $invoice['deliveryStatus'];
		}

		self::assertSame('delivered', $bySlug['2026-0042']);
		self::assertSame('sent', $bySlug['2026-0051']);
		self::assertSame('not-sent', $bySlug['2026-0060']);

	}//end testSeedArInvoicesCarryRequiredFieldsAndDeliveryStatus()

	/**
	 * Seed data: every CustomerMaster seed object carries its schema's
	 * required fields (customerId, legalName, email, administrationId,
	 * lifecycleState).
	 *
	 * @return void
	 */
	public function testSeedCustomerMastersCarryRequiredFields(): void {
		$required = ['customerId', 'legalName', 'email', 'administrationId', 'lifecycleState'];
		$objects = $this->fragment()['components']['objects'];

		$customers = array_values(
			array_filter(
				$objects,
				static fn (array $o): bool => (($o['@self']['schema'] ?? '') === 'CustomerMaster')
			)
		);

		self::assertCount(3, $customers);

		foreach ($customers as $customer) {
			foreach ($required as $field) {
				self::assertArrayHasKey($field, $customer, 'seed CustomerMaster ' . ($customer['customerId'] ?? '?') . " is missing required field $field");
			}
		}

	}//end testSeedCustomerMastersCarryRequiredFields()
}//end class
