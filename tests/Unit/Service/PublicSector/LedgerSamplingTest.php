<?php

/**
 * Unit tests for LedgerSampling.
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

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use DateTime;
use DomainException;
use OCA\Shillinq\Service\LedgerActuals;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\AuditSampleDrawer;
use OCA\Shillinq\Service\PublicSector\LedgerSampling;
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
 * REQ-011: a sample of the protocol's posted lines, drawn reproducibly, over
 * the real records service, drawer and an in-memory register.
 */
#[CoversClass(LedgerSampling::class)]
#[UsesClass(AuditSampleDrawer::class)]
#[UsesClass(PublicSectorRecords::class)]
#[UsesClass(LedgerActuals::class)]
#[UsesClass(ObjectIdentifier::class)]
final class LedgerSamplingTest extends TestCase {

	/**
	 * What the store saved.
	 *
	 * @var array<int, mixed>
	 */
	private array $saved = [];

	/**
	 * Protocol 2026 of administration "gem": 40 purchase lines (account 4000)
	 * and 10 revenue lines (8000) in posted 2026 transactions, plus lines of a
	 * draft transaction, of 2025 and of another administration.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function data(): array {
		$transactions = [
			['id' => 'tx-a', 'administrationId' => 'gem', 'state' => 'posted', 'postingDate' => '2026-02-01'],
			['id' => 'tx-b', 'administrationId' => 'gem', 'state' => 'posted', 'postingDate' => '2026-09-30'],
			['id' => 'tx-draft', 'administrationId' => 'gem', 'state' => 'draft', 'postingDate' => '2026-03-01'],
			['id' => 'tx-2025', 'administrationId' => 'gem', 'state' => 'posted', 'postingDate' => '2025-12-31'],
			['id' => 'tx-other', 'administrationId' => 'other', 'state' => 'posted', 'postingDate' => '2026-05-01'],
		];
		$lines = [];
		for ($i = 1; $i <= 40; $i++) {
			$lines[] = ['id' => sprintf('p%02d', $i), 'transactionId' => ($i <= 20 ? 'tx-a' : 'tx-b'), 'accountNumber' => '4000', 'amount' => 100];
		}

		for ($i = 1; $i <= 10; $i++) {
			$lines[] = ['id' => sprintf('r%02d', $i), 'transactionId' => 'tx-b', 'accountNumber' => '8000', 'amount' => 100];
		}

		foreach (['tx-draft', 'tx-2025', 'tx-other'] as $outside) {
			$lines[] = ['id' => 'x-' . $outside, 'transactionId' => $outside, 'accountNumber' => '4000', 'amount' => 100];
		}

		return [
			'Controleprotocol' => [['id' => 'prot-2026', 'organisationId' => 'gem', 'auditYear' => 2026]],
			'GLTransaction' => $transactions,
			'GLLine' => $lines,
		];
	}//end data()

	/**
	 * The sampling over the data, with auditor anna.
	 *
	 * @return LedgerSampling
	 */
	private function sampling(): LedgerSampling {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(self::data(), $this->saved);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$records = new PublicSectorRecords($store, $this->createMock(ObjectTransitionRunner::class), $location);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new LedgerSampling(records: $records, drawer: new AuditSampleDrawer($records, $time, $session));
	}//end sampling()

	/**
	 * 25 purchase lines with seed 20261 are drawn from the 40 posted ones, and
	 * the same seed draws the same 25 again.
	 *
	 * @return void
	 */
	public function testTheSameSeedDrawsTheSamePurchaseLines(): void {
		$sampling = $this->sampling();
		$first = $sampling->draw(protocolId: 'prot-2026', request: ['size' => 25, 'seed' => 20261, 'accountPrefix' => '40']);
		$second = $sampling->draw(protocolId: 'prot-2026', request: ['size' => 25, 'seed' => 20261, 'accountPrefix' => '40']);

		$this->assertSame(40, $first['populationSize']);
		$this->assertCount(25, $first['sampledLineIds']);
		$this->assertSame($first['sampledLineIds'], $second['sampledLineIds']);
		$this->assertSame('20261', $first['reproducibleSeed']);
		foreach ($first['sampledLineIds'] as $id) {
			$this->assertMatchesRegularExpression('/^p\d\d$/', $id);
		}
	}//end testTheSameSeedDrawsTheSamePurchaseLines()

	/**
	 * Without a prefix the population is every posted 2026 line of the
	 * administration, and the stored sample describes it and validates.
	 *
	 * @return void
	 */
	public function testThePopulationIsThePostedLinesOfTheYear(): void {
		$sample = $this->sampling()->draw(protocolId: 'prot-2026', request: ['size' => 50]);

		$this->assertSame(50, $sample['populationSize']);
		$this->assertCount(50, $sample['sampledLineIds']);
		$this->assertSame([], array_values(array_filter($sample['sampledLineIds'], static fn (string $id): bool => str_starts_with($id, 'x-'))));

		$stored = end($this->saved);
		$object = (is_array($stored) === true && isset($stored['object']) === true) ? $stored['object'] : $stored;
		$this->assertSame('Posted ledger lines of 2026', $object['population']);
		$this->assertSame('prot-2026', $object['protocol']);
		$this->assertSame([], RegisterSchema::errors(slug: 'AuditSample', object: $object));
	}//end testThePopulationIsThePostedLinesOfTheYear()

	/**
	 * A size beyond the population, and an unknown protocol, are refused.
	 *
	 * @return void
	 */
	public function testAnImpossibleDrawIsRefused(): void {
		$sampling = $this->sampling();
		try {
			$sampling->draw(protocolId: 'prot-2026', request: ['size' => 11, 'accountPrefix' => '8']);
			$this->fail('Eleven lines were drawn from ten.');
		} catch (DomainException $e) {
			$this->assertSame('A sample must hold between 1 and 10 lines; 11 were asked.', $e->getMessage());
		}

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Audit protocol prot-nope does not exist.');
		$sampling->draw(protocolId: 'prot-nope', request: ['size' => 1]);
	}//end testAnImpossibleDrawIsRefused()
}//end class
