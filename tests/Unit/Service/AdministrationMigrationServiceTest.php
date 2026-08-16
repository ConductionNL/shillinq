<?php

/**
 * Unit tests for AdministrationMigrationService.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationMigrationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the AdministrationMigration dual-post logic (REQ-MA-006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationMigrationServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AdministrationMigrationService
	 */
	private AdministrationMigrationService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new AdministrationMigrationService();

	}//end setUp()

	/**
	 * Lifecycle transitions match the schema declaration.
	 *
	 * @return void
	 */
	public function testIsTransitionAllowed(): void {
		self::assertTrue($this->service->isTransitionAllowed(from: 'voorbereid', to: 'uitgevoerd'));
		self::assertTrue($this->service->isTransitionAllowed(from: 'voorbereid', to: 'teruggedraaid'));
		self::assertTrue($this->service->isTransitionAllowed(from: 'uitgevoerd', to: 'geboekt_beide'));
		self::assertTrue($this->service->isTransitionAllowed(from: 'uitgevoerd', to: 'teruggedraaid'));
		self::assertTrue($this->service->isTransitionAllowed(from: 'geboekt_beide', to: 'teruggedraaid'));

		// Terminal state.
		self::assertFalse($this->service->isTransitionAllowed(from: 'teruggedraaid', to: 'uitgevoerd'));
		self::assertFalse($this->service->isTransitionAllowed(from: 'geboekt_beide', to: 'voorbereid'));

		// Same-state no-op tolerated.
		self::assertTrue($this->service->isTransitionAllowed(from: 'voorbereid', to: 'voorbereid'));

	}//end testIsTransitionAllowed()

	/**
	 * statusAfterSidePosted moves through voorbereid -> uitgevoerd -> geboekt_beide.
	 *
	 * @return void
	 */
	public function testStatusAfterSidePosted(): void {
		$empty = [];
		$sourceOnly = ['sourceJournalEntryId' => 'gltx-1'];
		$destOnly = ['destinationJournalEntryId' => 'gltx-2'];
		$both = ['sourceJournalEntryId' => 'gltx-1', 'destinationJournalEntryId' => 'gltx-2'];

		self::assertSame('voorbereid', $this->service->statusAfterSidePosted(migration: $empty));
		self::assertSame('uitgevoerd', $this->service->statusAfterSidePosted(migration: $sourceOnly));
		self::assertSame('uitgevoerd', $this->service->statusAfterSidePosted(migration: $destOnly));
		self::assertSame('geboekt_beide', $this->service->statusAfterSidePosted(migration: $both));

	}//end testStatusAfterSidePosted()

	/**
	 * statusAfterReversal lands on teruggedraaid from any reversible state.
	 *
	 * @return void
	 */
	public function testStatusAfterReversal(): void {
		self::assertSame('teruggedraaid', $this->service->statusAfterReversal(currentStatus: 'voorbereid'));
		self::assertSame('teruggedraaid', $this->service->statusAfterReversal(currentStatus: 'uitgevoerd'));
		self::assertSame('teruggedraaid', $this->service->statusAfterReversal(currentStatus: 'geboekt_beide'));

		// Already reversed: stays terminal.
		self::assertSame('teruggedraaid', $this->service->statusAfterReversal(currentStatus: 'teruggedraaid'));

	}//end testStatusAfterReversal()

	/**
	 * computeTransferAmounts derives result from market - book by default.
	 *
	 * @return void
	 */
	public function testComputeTransferAmountsDefaultResult(): void {
		$migration = [
			'bookValueTransferred' => 87000.0,
			'marketValueTransferred' => 92000.0,
		];

		$amounts = $this->service->computeTransferAmounts(migration: $migration);
		self::assertSame(8700000, $amounts['bookCents']);
		self::assertSame(9200000, $amounts['marketCents']);
		self::assertSame(500000, $amounts['resultCents']);

	}//end testComputeTransferAmountsDefaultResult()

	/**
	 * computeTransferAmounts honours an explicit resultImpact override.
	 *
	 * @return void
	 */
	public function testComputeTransferAmountsExplicitResult(): void {
		$migration = [
			'bookValueTransferred' => 87000.0,
			'marketValueTransferred' => 92000.0,
			'resultImpact' => 0.0,
		];

		$amounts = $this->service->computeTransferAmounts(migration: $migration);
		self::assertSame(0, $amounts['resultCents']);

	}//end testComputeTransferAmountsExplicitResult()

	/**
	 * Source draft uses the source administration and posts at book value
	 * plus the result differential.
	 *
	 * @return void
	 */
	public function testBuildSourceJournalDraft(): void {
		$migration = [
			'migrationNumber' => 'MIG-2026-007',
			'date' => '2026-09-01',
			'sourceAdministrationId' => 'adm-werk-001',
			'bookValueTransferred' => 100.0,
			'marketValueTransferred' => 150.0,
			'fiscalTreatment' => 'with_actuals',
			'legalBasis' => 'Akte X',
		];

		$draft = $this->service->buildSourceJournalDraft(migration: $migration);
		self::assertSame('adm-werk-001', $draft['administrationId']);
		self::assertSame('migration_source', $draft['kind']);
		self::assertSame(10000, $draft['bookValueCents']);
		self::assertSame(15000, $draft['marketValueCents']);
		self::assertSame(5000, $draft['resultCents']);
		self::assertSame('with_actuals', $draft['fiscalTreatment']);

	}//end testBuildSourceJournalDraft()

	/**
	 * Destination draft uses the market value for met_realisatie and the
	 * book value for geruisloze_doorschuiving.
	 *
	 * @return void
	 */
	public function testBuildDestinationJournalDraftFiscalTreatment(): void {
		$migration = [
			'destinationAdministrationId' => 'adm-werk-002',
			'date' => '2026-09-01',
			'migrationNumber' => 'MIG-2026-007',
			'bookValueTransferred' => 100.0,
			'marketValueTransferred' => 150.0,
		];

		$marktwaarde = $this->service->buildDestinationJournalDraft(
			migration: $migration + ['fiscalTreatment' => 'with_actuals']
		);
		self::assertSame(15000, $marktwaarde['activationCents']);

		$geruisloos = $this->service->buildDestinationJournalDraft(
			migration: $migration + ['fiscalTreatment' => 'geruisloze_doorschuiving']
		);
		self::assertSame(10000, $geruisloos['activationCents']);

	}//end testBuildDestinationJournalDraftFiscalTreatment()

	/**
	 * buildJournalDrafts groups both sides and flags the lock based on status.
	 *
	 * @return void
	 */
	public function testBuildJournalDraftsBothSides(): void {
		$migration = [
			'migrationNumber' => 'MIG-2026-007',
			'sourceAdministrationId' => 'adm-werk-001',
			'destinationAdministrationId' => 'adm-werk-002',
			'bookValueTransferred' => 50.0,
			'marketValueTransferred' => 75.0,
			'date' => '2026-09-01',
			'status' => 'voorbereid',
		];

		$drafts = $this->service->buildJournalDrafts(migration: $migration);
		self::assertArrayHasKey('source', $drafts);
		self::assertArrayHasKey('destination', $drafts);
		self::assertSame('adm-werk-001', $drafts['source']['administrationId']);
		self::assertSame('adm-werk-002', $drafts['destination']['administrationId']);
		self::assertFalse($drafts['source']['locked']);
		self::assertFalse($drafts['destination']['locked']);

		// Lock once the migration moves into a terminal state. Use
		// array_merge / spread so the new status overrides the existing
		// key — PHP's `+` operator keeps the left-hand value on collision.
		$locked = $this->service->buildJournalDrafts(
			migration: array_merge($migration, ['status' => 'geboekt_beide'])
		);
		self::assertTrue($locked['source']['locked']);
		self::assertTrue($locked['destination']['locked']);

	}//end testBuildJournalDraftsBothSides()

	/**
	 * Reversal entries invert every cent amount on both sides.
	 *
	 * @return void
	 */
	public function testBuildReversalEntriesInverts(): void {
		$migration = [
			'migrationNumber' => 'MIG-2026-007',
			'sourceAdministrationId' => 'adm-werk-001',
			'destinationAdministrationId' => 'adm-werk-002',
			'bookValueTransferred' => 50.0,
			'marketValueTransferred' => 75.0,
			'date' => '2026-09-01',
		];

		$reversal = $this->service->buildReversalEntries(migration: $migration);
		self::assertSame(-5000, $reversal['source']['bookValueCents']);
		self::assertSame(-7500, $reversal['source']['marketValueCents']);
		self::assertSame(-2500, $reversal['source']['resultCents']);
		self::assertSame(-7500, $reversal['destination']['activationCents']);
		self::assertSame('migration_source_reversal', $reversal['source']['kind']);
		self::assertSame('migration_destination_reversal', $reversal['destination']['kind']);

	}//end testBuildReversalEntriesInverts()

	/**
	 * isEditable matches the lifecycle locks (REQ-MA-006).
	 *
	 * @return void
	 */
	public function testIsEditable(): void {
		self::assertTrue($this->service->isEditable(status: 'voorbereid'));
		self::assertTrue($this->service->isEditable(status: 'uitgevoerd'));
		self::assertFalse($this->service->isEditable(status: 'geboekt_beide'));
		self::assertFalse($this->service->isEditable(status: 'teruggedraaid'));

	}//end testIsEditable()
}//end class
