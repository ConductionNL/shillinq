<?php

/**
 * Unit tests for the school-contributions register fragment.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the ARInvoice contribution group, the PaymentRequest 0.4.0 fields,
 * and that the merged ARInvoice version is the one this fragment sets.
 */
final class SchoolContributionsFragmentTest extends TestCase {
	/**
	 * The register fragment directory.
	 *
	 * @var string
	 */
	private const FRAGMENT_DIR = __DIR__ . '/../../../lib/Settings/register.d';

	/**
	 * The ten fields every ARInvoice must carry (add-shillinq-bookkeeping-compliance.json).
	 *
	 * @var array<int, string>
	 */
	private const INVOICE_REQUIRED = [
		'invoiceNumber',
		'customerId',
		'administrationId',
		'invoiceDate',
		'dueDate',
		'grossAmount',
		'netAmount',
		'currency',
		'periodId',
		'lifecycleState',
	];

	/**
	 * Decode one fragment.
	 *
	 * @param string $name The file name inside register.d.
	 *
	 * @return array<string, mixed> The decoded fragment.
	 */
	private function fragment(string $name): array {
		$data = json_decode((string)file_get_contents(self::FRAGMENT_DIR . '/' . $name), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		return $data;
	}//end fragment()

	/**
	 * The seed objects of this fragment for one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The seed objects.
	 */
	private function seeds(string $schema): array {
		$objects = $this->fragment('school-contributions.json')['components']['objects'];

		return array_values(
			array_filter(
				$objects,
				static fn (array $o): bool => ($o['@self']['schema'] ?? '') === $schema
			)
		);
	}//end seeds()

	/**
	 * The contribution group names everything the raise and the dunning read.
	 * OpenRegister drops an undeclared property in silence, so a missing one
	 * would lose the voluntary flag without an error.
	 *
	 * @return void
	 */
	public function testTheContributionGroupDeclaresWhatTheRaiseWrites(): void {
		$group = $this->fragment('school-contributions.json')['components']['schemas']['ARInvoice']['properties']['contribution'];

		self::assertSame('object', $group['type']);
		self::assertTrue($group['nullable']);
		foreach (['kind', 'voluntary', 'chargeable', 'beneficiary', 'raiseBatchId', 'revenueAccount'] as $part) {
			self::assertArrayHasKey($part, $group['properties'], $part . ' is missing from ARInvoice.contribution');
		}

		self::assertSame(
			['parental-contribution', 'lunch-supervision', 'school-trip', 'activity', 'other'],
			$group['properties']['kind']['enum']
		);
		foreach (['app', 'type', 'register', 'schema', 'id'] as $part) {
			self::assertArrayHasKey($part, $group['properties']['chargeable']['properties']);
		}
	}//end testTheContributionGroupDeclaresWhatTheRaiseWrites()

	/**
	 * This fragment declares no top-level required list: deepMergeConfig
	 * concatenates required lists across fragments, and a second owner would
	 * make every existing invoice fail validation.
	 *
	 * @return void
	 */
	public function testTheFragmentOwnsNoRequiredList(): void {
		$invoice = $this->fragment('school-contributions.json')['components']['schemas']['ARInvoice'];

		self::assertArrayNotHasKey('required', $invoice);
	}//end testTheFragmentOwnsNoRequiredList()

	/**
	 * The merged ARInvoice version is this fragment's, because it is the last
	 * fragment in sort order that sets one. The import only updates a schema
	 * whose version rises, so a lower merged version would leave the
	 * contribution group off every existing instance.
	 *
	 * @return void
	 */
	public function testTheMergedInvoiceVersionIsThisFragments(): void {
		$files = glob(self::FRAGMENT_DIR . '/*.json');
		self::assertIsArray($files);
		sort($files);

		$lastWriter = '';
		$lastVersion = '';
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$version = ($data['components']['schemas']['ARInvoice']['version'] ?? null);
			if (is_string($version) === true) {
				$lastWriter = basename($file);
				$lastVersion = $version;
			}
		}

		self::assertSame('school-contributions.json', $lastWriter);
		self::assertSame('0.15.0', $lastVersion);
	}//end testTheMergedInvoiceVersionIsThisFragments()

	/**
	 * The whole register as the repair step builds it: the base descriptor with
	 * every fragment merged in sort order by SettingsService's own merge.
	 *
	 * @return array<string, mixed> The merged register.
	 */
	private function mergedRegister(): array {
		$merge = new \ReflectionMethod(\OCA\Shillinq\Service\SettingsService::class, 'deepMergeConfig');
		$config = json_decode((string)file_get_contents(self::FRAGMENT_DIR . '/../shillinq_register.json'), true);
		$files = glob(self::FRAGMENT_DIR . '/*.json');
		self::assertIsArray($files);
		sort($files);
		foreach ($files as $file) {
			$config = $merge->invoke(null, $config, json_decode((string)file_get_contents($file), true));
		}

		return $config;
	}//end mergedRegister()

	/**
	 * A declined voluntary contribution is a closed invoice: the merged ARInvoice
	 * knows the state, reaches it only through the guarded transitions, and is
	 * never overdue (REQ-SCON-014). The raise's language and the decline moment are declared,
	 * or OpenRegister drops them in silence (REQ-SCON-011, REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 */
	public function testADeclinedContributionIsAClosedInvoice(): void {
		$invoice = $this->mergedRegister()['components']['schemas']['ARInvoice'];

		$contribution = $invoice['properties']['contribution']['properties'];
		self::assertArrayHasKey('language', $contribution);
		self::assertSame('date-time', $contribution['declinedAt']['format']);

		self::assertContains('declined', $invoice['properties']['lifecycleState']['enum']);
		self::assertContains('issued', $invoice['properties']['lifecycleState']['enum']);

		$lifecycle = $invoice['x-openregister-lifecycle'];
		self::assertArrayHasKey('declined', $lifecycle['states']);
		$guard = 'OCA\\Shillinq\\Lifecycle\\VoluntaryDeclineGuard::requireVoluntary';
		self::assertSame(['from' => 'issued', 'to' => 'declined'], array_intersect_key($lifecycle['transitions']['decline'], ['from' => 1, 'to' => 1]));
		self::assertSame($guard, $lifecycle['transitions']['decline']['requires']);
		self::assertSame('overdue', $lifecycle['transitions']['decline-overdue']['from']);
		self::assertSame($guard, $lifecycle['transitions']['decline-overdue']['requires']);
		self::assertTrue(method_exists('OCA\\Shillinq\\Lifecycle\\VoluntaryDeclineGuard', 'requireVoluntary'));

		self::assertStringContainsString("lifecycleState != 'declined'", $invoice['x-openregister-calculations']['isOverdue']['expression']);
		self::assertStringContainsString("lifecycleState != 'paid'", $invoice['x-openregister-calculations']['isOverdue']['expression']);
	}//end testADeclinedContributionIsAClosedInvoice()

	/**
	 * PaymentRequest 0.4.0 carries the reference, the child and the settled edge.
	 *
	 * @return void
	 */
	public function testPaymentRequestCarriesTheContributionFields(): void {
		$schema = $this->fragment('ar-invoice-payment-links.json')['components']['schemas']['PaymentRequest'];
		$properties = $schema['properties'];

		self::assertSame('0.4.0', $schema['version']);
		foreach (['beneficiary', 'voluntary', 'raiseBatchId', 'settledAt', 'settledVia'] as $property) {
			self::assertArrayHasKey($property, $properties, $property . ' is missing from PaymentRequest');
		}

		self::assertArrayHasKey('app', $properties['subject']['properties']);
		self::assertContains('contribution', $properties['requestType']['enum']);
		self::assertSame(
			['provider', 'cash', 'pin', 'bank-transfer', 'waived', 'other'],
			$properties['settledVia']['enum']
		);
		self::assertSame('date-time', $properties['settledAt']['format']);
	}//end testPaymentRequestCarriesTheContributionFields()

	/**
	 * Every seeded contribution invoice satisfies the required list and carries
	 * the group; a voluntary one carries the notice.
	 *
	 * @return void
	 */
	public function testSeedInvoicesAreCompleteContributions(): void {
		$invoices = $this->seeds('ARInvoice');
		self::assertGreaterThanOrEqual(3, count($invoices));

		$voluntarySeen = false;
		$declinedSeen = false;
		foreach ($invoices as $invoice) {
			foreach (self::INVOICE_REQUIRED as $field) {
				self::assertArrayHasKey($field, $invoice, $invoice['@self']['slug'] . ' misses ' . $field);
			}

			self::assertIsArray($invoice['contribution']);
			self::assertSame('nl', ($invoice['contribution']['language'] ?? null), $invoice['@self']['slug'] . ' has no language');
			if ($invoice['lifecycleState'] === 'declined') {
				$declinedSeen = true;
				self::assertTrue($invoice['contribution']['voluntary']);
				self::assertNotEmpty($invoice['contribution']['declinedAt']);
			}

			if ($invoice['contribution']['voluntary'] === true) {
				$voluntarySeen = true;
				self::assertStringContainsString('vrijwillig', $invoice['invoiceNote']);
				self::assertStringEndsWith('(vrijwillig)', $invoice['invoiceLines'][0]['itemName']);
			} else {
				self::assertArrayNotHasKey('invoiceNote', $invoice);
			}
		}

		self::assertTrue($voluntarySeen, 'no voluntary contribution among the seeds');
		self::assertTrue($declinedSeen, 'no declined contribution among the seeds');
	}//end testSeedInvoicesAreCompleteContributions()

	/**
	 * The seeded request stands on the chargeable and on a seeded invoice.
	 *
	 * @return void
	 */
	public function testTheSeedRequestIsInvoiceBacked(): void {
		$requests = $this->seeds('PaymentRequest');
		self::assertNotEmpty($requests);

		$invoiceSlugs = array_map(static fn (array $o): string => $o['@self']['slug'], $this->seeds('ARInvoice'));
		foreach ($requests as $request) {
			self::assertSame('object', $request['subjectKind']);
			self::assertSame('contribution', $request['requestType']);
			self::assertSame('learniq', $request['subject']['app']);
			self::assertContains($request['invoiceReference'], $invoiceSlugs);
		}
	}//end testTheSeedRequestIsInvoiceBacked()
}//end class
