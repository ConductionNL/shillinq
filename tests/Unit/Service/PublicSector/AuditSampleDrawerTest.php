<?php

/**
 * Unit tests for AuditSampleDrawer: a seeded draw is reproducible, it is
 * stored as a valid AuditSample, and impossible draws are refused (REQ-011).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use DateTime;
use DomainException;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\AuditSampleDrawer;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The drawer over the real records service and an in-memory register.
 */
#[CoversClass(AuditSampleDrawer::class)]
#[UsesClass(PublicSectorRecords::class)]
#[UsesClass(ObjectIdentifier::class)]
final class AuditSampleDrawerTest extends TestCase {
	/**
	 * What the store saved: schema and object.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The drawer, with one protocol and auditor anna.
	 *
	 * @param bool $withUser Whether an auditor is logged in.
	 *
	 * @return AuditSampleDrawer
	 */
	private function drawer(bool $withUser = true): AuditSampleDrawer {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(['Controleprotocol' => [['id' => 'prot-2026', 'name' => 'Controleprotocol 2026']]], $this->saved);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$records = new PublicSectorRecords($store, $this->createMock(ObjectTransitionRunner::class), $location);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($withUser === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('anna');
		}

		$session->method('getUser')->willReturn($user);
		return new AuditSampleDrawer($records, $time, $session);

	}//end drawer()

	/**
	 * A population of 4,000 posted purchase line ids.
	 *
	 * @return array<int,string>
	 */
	private static function population(): array {
		$ids = [];
		for ($i = 1; $i <= 4000; $i++) {
			$ids[] = sprintf('gl-%05d', $i);
		}

		return $ids;

	}//end population()

	/**
	 * Twenty-five lines drawn with seed 20261 are stored with the seed, and the
	 * same seed returns the same lines even when the population arrives shuffled.
	 *
	 * @return void
	 */
	public function testTheSameSeedDrawsTheSameLines(): void {
		$first  = $this->drawer()->draw('prot-2026', self::population(), 25, 20261, 'Purchase invoices 2026');
		$stored = $this->saved[0];
		// The store stamps the id it answers with; the payload is what the drawer wrote.
		$this->assertSame($first['id'], $stored['object']['id']);
		unset($stored['object']['id']);

		$shuffled = array_reverse(self::population());
		$second = $this->drawer()->draw('prot-2026', $shuffled, 25, 20261);

		$this->assertCount(25, $first['sampledLineIds']);
		$this->assertCount(25, array_unique($first['sampledLineIds']));
		$this->assertSame([], array_diff($first['sampledLineIds'], self::population()));
		$this->assertSame($first['sampledLineIds'], $second['sampledLineIds']);
		$this->assertSame('20261', $first['reproducibleSeed']);

		$this->assertSame('AuditSample', $stored['schema']);
		$this->assertSame(
			[
				'protocol' => 'prot-2026',
				'population' => 'Purchase invoices 2026',
				'selectionMethod' => 'random',
				'sampleSize' => 25,
				'reproducibleSeed' => '20261',
				'sampledLineIds' => $first['sampledLineIds'],
				'extractedAt' => '2026-11-13T10:00:00+00:00',
				'extractedBy' => 'anna',
			],
			$stored['object']
		);
		$errors = RegisterSchema::errors(slug: 'AuditSample', object: $stored['object']);
		$this->assertSame([], $errors, 'the stored sample validates against the merged register');

	}//end testTheSameSeedDrawsTheSameLines()

	/**
	 * Another seed draws other lines.
	 *
	 * @return void
	 */
	public function testAnotherSeedDrawsOtherLines(): void {
		$a = $this->drawer()->draw('prot-2026', self::population(), 25, 20261);
		$b = $this->drawer()->draw('prot-2026', self::population(), 25, 20262);

		$this->assertNotSame($a['sampledLineIds'], $b['sampledLineIds']);

	}//end testAnotherSeedDrawsOtherLines()

	/**
	 * Without a seed one is chosen and stored, and redrawing with it repeats the sample.
	 *
	 * @return void
	 */
	public function testWithoutASeedOneIsChosenAndStored(): void {
		$drawn = $this->drawer(false)->draw('prot-2026', self::population(), 10);
		$this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', $drawn['reproducibleSeed']);
		$this->assertSame('system', $this->saved[0]['object']['extractedBy']);
		$this->assertSame('4000 posted lines', $this->saved[0]['object']['population']);

		$again = $this->drawer()->draw('prot-2026', self::population(), 10, (int)$drawn['reproducibleSeed']);
		$this->assertSame($drawn['sampledLineIds'], $again['sampledLineIds']);

	}//end testWithoutASeedOneIsChosenAndStored()

	/**
	 * Line records, duplicates and empty ids: the sample is drawn over the distinct ids.
	 *
	 * @return void
	 */
	public function testRecordsAndDuplicatesAreReducedToDistinctIds(): void {
		$drawn = $this->drawer()->draw('prot-2026', [['id' => 'gl-b'], 'gl-a', 'gl-b', '', ['amount' => 5], ['id' => 'gl-c']], 3, 7);

		$this->assertSame(['gl-a', 'gl-b', 'gl-c'], $this->sorted($drawn['sampledLineIds']));
		$this->assertSame('3 posted lines', $this->saved[0]['object']['population']);

	}//end testRecordsAndDuplicatesAreReducedToDistinctIds()

	/**
	 * A size outside 1..population is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testASizeOutsideThePopulationIsRefused(): void {
		$drawer = $this->drawer();
		foreach ([0, 4] as $size) {
			try {
				$drawer->draw('prot-2026', ['gl-1', 'gl-2', 'gl-3'], $size, 1);
				$this->fail('a sample of ' . $size . ' was drawn');
			} catch (DomainException $e) {
				$this->assertSame(sprintf('A sample must hold between 1 and 3 lines; %d were asked.', $size), $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);

	}//end testASizeOutsideThePopulationIsRefused()

	/**
	 * A draw for a protocol that does not exist is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownProtocolIsRefused(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Audit protocol prot-404 does not exist.');
		$this->drawer()->draw('prot-404', ['gl-1'], 1, 1);

	}//end testAnUnknownProtocolIsRefused()

	/**
	 * The values, sorted.
	 *
	 * @param array<int,string> $values The values.
	 *
	 * @return array<int,string>
	 */
	private function sorted(array $values): array {
		sort($values);
		return $values;

	}//end sorted()
}//end class
