<?php

/**
 * Unit tests for VatReturnChecksGuard (REQ-TVRB-001).
 *
 * The guard runs on BtwAangifte.submit through the real
 * RegisterRequiresGuardAdapter and the real VatReturnCheckService, over a
 * quarter posted through the real posting mapper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\VatReturnChecksGuard;
use OCA\Shillinq\Service\Vat\VatReturnCheckService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Vat\VatQuarterBooks;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Submit is refused while a blocking check fails, and the refusal names it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatReturnChecksGuardTest extends TestCase {
	use VatQuarterBooks;

	/**
	 * The literal requires tag of BtwAangifte.submit.
	 */
	private const TAG = 'OCA\Shillinq\Lifecycle\VatReturnChecksGuard::canSubmit';

	/**
	 * Reset the rule engine's memo, so the providers are discovered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		RuleEngine::reset();
	}//end setUp()

	/**
	 * Prepare Q3 2026 over the rows; answer the adapter on its submit and the prepared return.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return array{0: RegisterRequiresGuardAdapter, 1: array<string,mixed>}
	 */
	private function adapterFor(array $rows): array {
		$saved = [];
		$store = new InMemoryObjectServiceStub($rows, $saved, true);
		$prepared = (new VATReturnService(appConfig: $this->defaultsConfig(), logger: new NullLogger(), objectService: $store))
			->createReturn(administrationId: 'adm-kb', period: 'quarter', periodYear: 2026, periodNumber: 3, regime: 'standard');

		$guard = new VatReturnChecksGuard(checks: new VatReturnCheckService(objectService: $store, appConfig: $this->defaultsConfig()));
		$adapter = new RegisterRequiresGuardAdapter(guard: $guard, method: 'canSubmit', denyMessage: 'The return cannot be submitted.', logger: new NullLogger());

		return [$adapter, $prepared];
	}//end adapterFor()

	/**
	 * The spec's scenario: a posted purchase line with input VAT but no tariff
	 * refuses Submit with the check's name and the transaction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	public function testSubmitIsRefusedNamingTheFailingCheck(): void {
		$rows = $this->withEntry(
			$this->posted([$this->sale(), $this->purchase()]),
			'MEM-77',
			[
				['accountNumber' => '7200', 'side' => 'debit', 'amount' => 100.0],
				['accountNumber' => '1230', 'side' => 'debit', 'amount' => 21.0],
				['accountNumber' => '1600', 'side' => 'credit', 'amount' => 121.0],
			]
		);
		[$adapter, $prepared] = $this->adapterFor($rows);

		$result = $adapter->check($prepared, 'submit', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('has a VAT return box', (string)$result->getMessage());
		self::assertStringContainsString('MEM-77', (string)$result->getMessage());
	}//end testSubmitIsRefusedNamingTheFailingCheck()

	/**
	 * A clean return may be submitted, and a failing warning does not block it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	public function testACleanReturnOrAWarningOnlyMaySubmit(): void {
		[$adapter, $prepared] = $this->adapterFor($this->posted([$this->sale(), $this->purchase()]));
		self::assertTrue($adapter->check($prepared, 'submit', 'alice')->isAllowed());

		$rows = $this->posted([$this->sale(), $this->purchase()]);
		$rows['ARInvoice'] = [['id' => 'ar-d1', 'invoiceNumber' => 'CONCEPT-1', 'invoiceDate' => '2026-09-20', 'administrationId' => 'adm-kb', 'lifecycleState' => 'draft']];
		[$adapter, $prepared] = $this->adapterFor($rows);
		self::assertTrue($adapter->check($prepared, 'submit', 'alice')->isAllowed());
	}//end testACleanReturnOrAWarningOnlyMaySubmit()

	/**
	 * The guard is the requires of BtwAangifte.submit, and the app registers
	 * that exact tag through the adapter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	public function testTheGuardIsWiredOnSubmit(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/bookkeeping-vat-btw-filing.json'), true);
		$submit = $fragment['components']['schemas']['BtwAangifte']['x-openregister-lifecycle']['transitions']['submit'];
		self::assertSame(self::TAG, $submit['requires']);

		$application = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		self::assertStringContainsString("'" . self::TAG . "'", $application);
		self::assertStringContainsString("guard: \$c->get(VatReturnChecksGuard::class),\n\t\t\t\t\tmethod: 'canSubmit',", $application);
	}//end testTheGuardIsWiredOnSubmit()
}//end class
