<?php

/**
 * Unit tests for IntercompanyEliminationGuard.
 *
 * Correctness proof for tasks.md Task 16 (REQ-GLTAX-002): an intercompany
 * pair that does not reconcile MUST NOT be eliminated, and a pair whose
 * counter-side cannot be resolved MUST fail closed.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\IntercompanyEliminationGuard;
use OCA\Shillinq\Service\IntercompanyJournalService;
use OCA\Shillinq\Service\IntercompanyLinkService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests the `eliminate` precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class IntercompanyEliminationGuardTest extends TestCase {

	/**
	 * The in-memory ObjectService backing the chain.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * The guard under test.
	 *
	 * @var IntercompanyEliminationGuard
	 */
	private IntercompanyEliminationGuard $guard;

	/**
	 * Set up the guard over the real services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = new InMemoryObjectService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$journalService = new IntercompanyJournalService();
		$linkService = new IntercompanyLinkService(
			appConfig: $appConfig,
			journalService: $journalService,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objects),
		);

		$this->guard = new IntercompanyEliminationGuard(
			linkService: $linkService,
			journalService: $journalService,
		);

	}//end setUp()

	/**
	 * The source side of the pair.
	 *
	 * @return array<string,mixed>
	 */
	private function source(): array {
		return [
			'id' => 'ic-src',
			'intercompanyNumber' => 'IC-2026-0001',
			'sourceAdministrationId' => 'adm-werk',
			'destinationAdministrationId' => 'adm-beheer',
			'amount' => 1000.0,
			'status' => 'bevestigd_beide',
		];

	}//end source()

	/**
	 * A reconciled pair may be eliminated (REQ-GLTAX-002).
	 *
	 * @return void
	 */
	public function testBalancedPairIsAllowed(): void {
		$this->objects->seed(
			'IntercompanyJournalEntry',
			[
				$this->source(),
				[
					'id' => 'ic-dst',
					'intercompanyNumber' => 'IC-2026-0001',
					'sourceAdministrationId' => 'adm-beheer',
					'destinationAdministrationId' => 'adm-werk',
					'amount' => 1000.0,
					'status' => 'bevestigd_beide',
				],
			]
		);

		self::assertTrue($this->guard->requireReconciledPair(entry: $this->source()));

	}//end testBalancedPairIsAllowed()

	/**
	 * An out-of-balance pair may NOT be eliminated (REQ-GLTAX-002).
	 *
	 * @return void
	 */
	public function testUnbalancedPairIsDenied(): void {
		$this->objects->seed(
			'IntercompanyJournalEntry',
			[
				$this->source(),
				[
					'id' => 'ic-dst',
					'intercompanyNumber' => 'IC-2026-0001',
					'sourceAdministrationId' => 'adm-beheer',
					'destinationAdministrationId' => 'adm-werk',
					'amount' => 999.99,
					'status' => 'bevestigd_beide',
				],
			]
		);

		self::assertFalse($this->guard->requireReconciledPair(entry: $this->source()));

	}//end testUnbalancedPairIsDenied()

	/**
	 * No counter-side entry: fail closed (REQ-GLTAX-002).
	 *
	 * @return void
	 */
	public function testMissingCounterSideIsDenied(): void {
		$this->objects->seed('IntercompanyJournalEntry', [$this->source()]);

		self::assertFalse($this->guard->requireReconciledPair(entry: $this->source()));

	}//end testMissingCounterSideIsDenied()
}//end class
