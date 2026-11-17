<?php

/**
 * Unit tests for ImportPostingPayloads: the opening entry, its reversal and
 * the customer payload built from a staged relation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use DomainException;
use OCA\Shillinq\Service\Import\ImportPostingPayloads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The payload builder, which has no collaborators.
 */
#[CoversClass(ImportPostingPayloads::class)]
final class ImportPostingPayloadsTest extends TestCase {
	/**
	 * The batch the entries are built for.
	 *
	 * @var array<string,mixed>
	 */
	private const BATCH = ['id' => 'batch-1', 'migrationDate' => '2026-01-01T00:00:00Z', 'administrationId' => 'adm-1', 'sourceSystem' => ' Exact '];

	/**
	 * The opening entry splits each mapped line into its debit and credit sides.
	 *
	 * @return void
	 */
	public function testTheOpeningEntryCarriesEveryNonZeroSide(): void {
		$entry = (new ImportPostingPayloads())->openingEntry(
			self::BATCH,
			[
				'lines' => [
					['sourceAccount' => '1000', 'targetAccount' => ' 1010 ', 'debit' => 100.004, 'credit' => 0],
					['sourceAccount' => '0500', 'targetAccount' => '0500', 'credit' => '60'],
					['sourceAccount' => '1600', 'targetAccount' => '1600', 'debit' => 5, 'credit' => 45],
				],
			]
		);

		$this->assertSame(
			[
				['accountNumber' => '1010', 'side' => 'debit', 'amount' => 100.0, 'description' => 'Opening balance'],
				['accountNumber' => '0500', 'side' => 'credit', 'amount' => 60.0, 'description' => 'Opening balance'],
				['accountNumber' => '1600', 'side' => 'debit', 'amount' => 5.0, 'description' => 'Opening balance'],
				['accountNumber' => '1600', 'side' => 'credit', 'amount' => 45.0, 'description' => 'Opening balance'],
			],
			$entry['lines']
		);
		$this->assertSame('IMP-' . substr(hash('sha256', 'batch-1'), 0, 12), $entry['journalNumber']);
		$this->assertSame('2026-01-01', $entry['entryDate']);
		$this->assertSame('Opening balance from the Exact import', $entry['description']);
		$this->assertSame('manual', $entry['journalType']);
		$this->assertSame(ImportPostingPayloads::SOURCE_APP, $entry['sourceApp']);
		$this->assertSame('not-required', $entry['approvalState']);
		$this->assertSame('adm-1', $entry['administrationId']);
		$this->assertSame('draft', $entry['state']);

	}//end testTheOpeningEntryCarriesEveryNonZeroSide()

	/**
	 * An unmapped account refuses the entry, naming the source account.
	 *
	 * @return void
	 */
	public function testAnUnmappedAccountIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The account 4000 has no mapping.');
		(new ImportPostingPayloads())->openingEntry(self::BATCH, ['lines' => [['sourceAccount' => '4000', 'targetAccount' => '  ', 'debit' => 1]]]);

	}//end testAnUnmappedAccountIsRefused()

	/**
	 * Fewer than two lines give no entry; without a source system the import is called auditfile.
	 *
	 * @return void
	 */
	public function testTooFewLinesGiveNoEntryAndTheSourceDefaultsToAuditfile(): void {
		$payloads = new ImportPostingPayloads();
		$this->assertNull($payloads->openingEntry(self::BATCH, ['lines' => [['targetAccount' => '1000', 'debit' => 10, 'credit' => 0]]]));
		$this->assertNull($payloads->openingEntry(self::BATCH, []));

		$entry = $payloads->openingEntry(
			['@self' => ['id' => 'batch-2'], 'sourceSystem' => ''],
			['lines' => [['targetAccount' => '1000', 'debit' => 10], ['targetAccount' => '2000', 'credit' => 10]]]
		);
		$this->assertSame('Opening balance from the auditfile import', $entry['description']);
		$this->assertSame('IMP-' . substr(hash('sha256', 'batch-2'), 0, 12), $entry['journalNumber']);
		$this->assertSame('', $entry['entryDate']);

	}//end testTooFewLinesGiveNoEntryAndTheSourceDefaultsToAuditfile()

	/**
	 * The reversal swaps every side and names the opening entry.
	 *
	 * @return void
	 */
	public function testTheReversalSwapsEverySide(): void {
		$payloads = new ImportPostingPayloads();
		$entry = $payloads->reversingEntry(
			self::BATCH,
			[
				'journalNumber' => 'IMP-abc',
				'lines' => [
					['accountNumber' => '1010', 'side' => 'debit', 'amount' => 100.0, 'description' => 'Opening balance'],
					['accountNumber' => '0500', 'side' => 'credit', 'amount' => 100.0, 'description' => 'Opening balance'],
				],
			]
		);

		$this->assertSame(['credit', 'debit'], array_column($entry['lines'], 'side'));
		$this->assertSame(['1010', '0500'], array_column($entry['lines'], 'accountNumber'));
		$this->assertSame('Reversal of the opening balance', $entry['lines'][0]['description']);
		$this->assertSame('Reversal of IMP-abc', $entry['description']);
		$this->assertStringStartsWith('IMP-R-', $entry['journalNumber']);

		$this->assertSame('Reversal of the imported opening balance', $payloads->reversingEntry(self::BATCH, [])['description']);

	}//end testTheReversalSwapsEverySide()

	/**
	 * A customer relation with an email becomes a CustomerMaster with its optional fields.
	 *
	 * @return void
	 */
	public function testACustomerRelationBecomesACustomerMaster(): void {
		$payloads = new ImportPostingPayloads();

		$this->assertSame(
			[
				'customerId' => 'D-1',
				'legalName' => 'Bakkerij B.V.',
				'email' => 'info@bakkerij.example',
				'administrationId' => 'adm-1',
				'lifecycleState' => 'active',
				'kvkNumber' => '12345678',
				'telephone' => '010-1234567',
			],
			$payloads->customer(
				[
					'type' => ' c ', 'code' => 'D-1', 'name' => ' Bakkerij B.V. ', 'email' => ' info@bakkerij.example ',
					'kvk' => '12345678', 'vat' => ' ', 'phone' => '010-1234567',
				],
				'adm-1'
			)
		);

		$generated = $payloads->customer(['type' => 'B', 'name' => 'Zonder Code', 'email' => 'a@b.example'], 'adm-1');
		$this->assertSame('IMP-' . substr(hash('sha256', 'Zonder Code|a@b.example'), 0, 8), $generated['customerId']);

	}//end testACustomerRelationBecomesACustomerMaster()

	/**
	 * A supplier, or a customer without an email, gives no customer.
	 *
	 * @return void
	 */
	public function testSuppliersAndCustomersWithoutEmailGiveNone(): void {
		$payloads = new ImportPostingPayloads();

		$this->assertNull($payloads->customer(['type' => 'S', 'email' => 'x@y.example'], 'adm-1'));
		$this->assertNull($payloads->customer(['type' => 'C', 'email' => '  '], 'adm-1'));
		$this->assertFalse($payloads->isCustomer([]));
		$this->assertTrue($payloads->isCustomer(['type' => 'b']));

	}//end testSuppliersAndCustomersWithoutEmailGiveNone()
}//end class
