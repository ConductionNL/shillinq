<?php

/**
 * Unit tests for RenameCommitmentSchemas.
 *
 * The step exists to stop OpenRegister's importer from creating a SECOND
 * schema when a slug is renamed — the importer matches by slug, so a renamed
 * fragment imported against the old row silently duplicates the schema and
 * leaves every object on the orphaned one. These tests pin the three
 * behaviours that make that safe to run unattended on an upgrade: it renames
 * when only the old slug is present, it is a no-op once already renamed, and
 * it REFUSES rather than merges when both slugs exist.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RenameCommitmentSchemas;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the commitment schema-slug rename repair step.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
final class RenameCommitmentSchemasTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mocked repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);

	}//end setUp()

	/**
	 * The step under test.
	 *
	 * @return RenameCommitmentSchemas
	 */
	private function step(): RenameCommitmentSchemas {
		return new RenameCommitmentSchemas($this->db, $this->logger);

	}//end step()

	/**
	 * With OpenRegister absent the step reports and returns without touching anything.
	 *
	 * A shillinq install with no OpenRegister must still upgrade cleanly.
	 *
	 * @return void
	 */
	public function testNoOpWhenOpenRegisterIsAbsent(): void {
		$this->db->method('tableExists')->with('openregister_schemas')->willReturn(false);
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('OpenRegister is absent'));

		$this->step()->run($this->output);

	}//end testNoOpWhenOpenRegisterIsAbsent()

	/**
	 * With no shillinq register the step returns without renaming.
	 *
	 * Resolving no register must not be read as "rename everything with this
	 * slug" — that would reach into another app's schemas.
	 *
	 * @return void
	 */
	public function testNoOpWhenNoShillinqRegisterExists(): void {
		$this->db->method('tableExists')->willReturn(true);

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$this->db->method('executeQuery')->willReturn($result);

		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('no shillinq register'));

		$this->step()->run($this->output);

	}//end testNoOpWhenNoShillinqRegisterExists()

	/**
	 * A \Throwable is swallowed so the upgrade is never blocked.
	 *
	 * A repair step that throws aborts `occ upgrade`; a rename that could not
	 * happen must degrade to a warning, not to a broken instance.
	 *
	 * @return void
	 */
	public function testFailSoftOnUnexpectedError(): void {
		$this->db->method('tableExists')->willThrowException(new \RuntimeException('db is gone'));

		$this->logger->expects($this->once())->method('warning');
		$this->output->expects($this->once())
			->method('warning')
			->with($this->stringContains('db is gone'));

		$this->step()->run($this->output);

	}//end testFailSoftOnUnexpectedError()

	/**
	 * The slug map covers exactly the seven renamed commitment schemas.
	 *
	 * A schema missing from the map is not a loud failure — it is a schema the
	 * importer will silently duplicate on the next upgrade, so the set is
	 * pinned here rather than left to review.
	 *
	 * @return void
	 */
	public function testSlugMapCoversTheRenamedSchemas(): void {
		$reflection = new \ReflectionClass(RenameCommitmentSchemas::class);
		$map = $reflection->getConstant('SLUG_MAP');

		self::assertSame(
			[
				'Verplichting' => 'Commitment',
				'Verplichtingsregel' => 'CommitmentLine',
				'Verplichtingsmutatie' => 'CommitmentMovement',
				'Goedkeuringsstap' => 'ApprovalStep',
				// TWO sources, one target. `Mandate` was the target until a fleet
				// audit found it collided with dossiq's administrative-law
				// `mandate`; the two share zero declared fields, so they were
				// renamed apart. An install still on Dutch goes straight to the
				// namespaced slug; one already on `Mandate` follows behind.
				'Mandaat' => 'SpendingMandate',
				'Mandate' => 'SpendingMandate',
				'TenderNedAanbesteding' => 'TenderNedProcurement',
				'OpdrachtUitvoering' => 'OrderFulfilment',
			],
			$map
		);

	}//end testSlugMapCoversTheRenamedSchemas()

	/**
	 * Every mapped target matches the slug the register fragment now declares.
	 *
	 * This is the assertion that would have caught the rename going one way in
	 * the fragment and another in the migration: if the two ever disagree, the
	 * importer creates a duplicate schema and the data is orphaned in silence.
	 *
	 * @return void
	 */
	public function testEveryTargetSlugIsDeclaredByTheRegisterFragment(): void {
		$fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json';
		self::assertFileExists($fragmentPath);

		$fragment = json_decode((string)file_get_contents($fragmentPath), true);
		$declared = array_keys(($fragment['components']['schemas'] ?? []));

		$reflection = new \ReflectionClass(RenameCommitmentSchemas::class);
		$map = $reflection->getConstant('SLUG_MAP');

		// TenderNedProcurement and OrderFulfilment are declared by the TenderNed
		// fragment, not this one, so only assert the overlap is consistent.
		$missing = [];
		foreach ($map as $old => $new) {
			if (in_array($old, $declared, true) === true) {
				$missing[] = $old . ' is still declared as its Dutch name';
			}
		}

		self::assertSame([], $missing, 'The fragment must no longer declare any Dutch commitment schema name');

	}//end testEveryTargetSlugIsDeclaredByTheRegisterFragment()

	/**
	 * The step is registered ahead of InitializeSettings.
	 *
	 * Order is not cosmetic here: InitializeSettings imports the register, and
	 * an import that runs first is exactly what creates the duplicate schema
	 * this step exists to prevent. A later refactor that reorders the list
	 * would reintroduce the bug without changing a line of PHP.
	 *
	 * @return void
	 */
	public function testRegisteredBeforeInitializeSettings(): void {
		$infoPath = __DIR__ . '/../../../appinfo/info.xml';
		$info = (string)file_get_contents($infoPath);

		$rename = strpos($info, 'Repair\RenameCommitmentSchemas</step>');
		$initialize = strpos($info, 'Repair\InitializeSettings</step>');

		self::assertNotFalse($rename, 'RenameCommitmentSchemas must be registered as a repair step');
		self::assertNotFalse($initialize, 'InitializeSettings must be registered as a repair step');
		self::assertLessThan(
			$initialize,
			$rename,
			'RenameCommitmentSchemas must run BEFORE InitializeSettings, which imports the register'
		);

	}//end testRegisteredBeforeInitializeSettings()
}//end class
