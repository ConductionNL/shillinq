<?php

/**
 * Unit tests for RechtmatigheidGuard's logging and fail-closed paths.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RechtmatigheidGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Every refusal is logged with the record it concerns, and a record whose
 * fields cannot be read as text is refused and logged as an error.
 *
 * @covers \OCA\Shillinq\Lifecycle\RechtmatigheidGuard
 */
final class RechtmatigheidGuardFailClosedTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var RechtmatigheidGuard
	 */
	private RechtmatigheidGuard $guard;

	/**
	 * Set up the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard  = new RechtmatigheidGuard($this->logger);
	}//end setUp()

	/**
	 * A short substantiation is logged with its length, counted in characters.
	 *
	 * @return void
	 */
	public function testAShortSubstantiationIsLoggedWithItsLength(): void {
		$this->logger->expects($this->once())->method('info')->with(
			'RechtmatigheidGuard: onderbouwing too short for negative uitkomst — denying afronden',
			['toetsId' => 't-1', 'outcome' => 'onzeker', 'length' => 49]
		);

		$this->assertFalse(
			$this->guard->canFinaliseToets(
				['id' => 't-1', 'outcome' => 'onzeker', 'substantiation' => ' ' . str_repeat('é', 49) . ' ', 'lawfulnessFinding' => 'f-1']
			)
		);
	}//end testAShortSubstantiationIsLoggedWithItsLength()

	/**
	 * Fifty multibyte characters are enough.
	 *
	 * @return void
	 */
	public function testFiftyMultibyteCharactersAreEnough(): void {
		$this->logger->expects($this->never())->method('info');

		$this->assertTrue(
			$this->guard->canFinaliseToets(
				['outcome' => 'voldoet_niet', 'substantiation' => str_repeat('é', 50), 'lawfulnessFinding' => 'f-1']
			)
		);
	}//end testFiftyMultibyteCharactersAreEnough()

	/**
	 * A missing finding link is logged; a toets without id reads as unknown.
	 *
	 * @return void
	 */
	public function testAMissingFindingIsLoggedForAnUnknownToets(): void {
		$this->logger->expects($this->once())->method('info')->with(
			'RechtmatigheidGuard: negative uitkomst without linked bevinding — denying afronden',
			['toetsId' => 'unknown', 'outcome' => 'voldoet_niet']
		);

		$this->assertFalse(
			$this->guard->canFinaliseToets(['outcome' => 'voldoet_niet', 'substantiation' => str_repeat('x', 50), 'lawfulnessFinding' => '  '])
		);
	}//end testAMissingFindingIsLoggedForAnUnknownToets()

	/**
	 * Each paragraph and finding refusal is logged with its year or number.
	 *
	 * @return void
	 */
	public function testParagraphAndFindingRefusalsAreLogged(): void {
		$this->logger->expects($this->exactly(3))->method('info')->willReturnCallback(
			function (string $message, array $context): void {
				$expected = [
					'RechtmatigheidGuard: bevinding without correctieboeking_id — denying oplossen' => ['findingNumber' => 'B-7'],
					'RechtmatigheidGuard: paragraaf buiten tolerantie zonder toelichting — denying vaststellen' => ['financialYear' => 2025],
					'RechtmatigheidGuard: paragraaf not definitief — denying jaarrekening-export' => ['financialYear' => 'unknown', 'status' => ''],
				];
				$this->assertArrayHasKey($message, $expected);
				$this->assertSame($expected[$message], $context);
			}
		);

		$this->assertFalse($this->guard->canResolveBevinding(['findingNumber' => 'B-7', 'correction_entry_id' => ' ']));
		$this->assertFalse($this->guard->canVaststellenParagraaf(['financialYear' => 2025, 'within_tolerance' => 0]));
		$this->assertFalse($this->guard->canExportParagraaf([]));
	}//end testParagraphAndFindingRefusalsAreLogged()

	/**
	 * A paragraph without a tolerance flag is within tolerance.
	 *
	 * @return void
	 */
	public function testAParagraphWithoutToleranceFlagIsWithinTolerance(): void {
		$this->assertTrue($this->guard->canVaststellenParagraaf([]));
	}//end testAParagraphWithoutToleranceFlagIsWithinTolerance()

	/**
	 * A field that cannot be read as text refuses each check and logs an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableFieldIsRefusedAndLogged(): void {
		$messages = [];
		$this->logger->expects($this->exactly(4))->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = $message;
				$this->assertSame('ref-1', current(array_filter([$context['toetsId'] ?? null, $context['findingNumber'] ?? null, $context['financialYear'] ?? null])));
				$this->assertStringContainsString('stdClass', $context['exception']);
			}
		);
		$broken = new stdClass();

		$this->assertFalse($this->guard->canFinaliseToets(['id' => 'ref-1', 'outcome' => $broken]));
		$this->assertFalse($this->guard->canResolveBevinding(['findingNumber' => 'ref-1', 'correction_entry_id' => $broken]));
		$this->assertFalse($this->guard->canVaststellenParagraaf(['financialYear' => 'ref-1', 'within_tolerance' => false, 'declaration_college' => $broken]));
		$this->assertFalse($this->guard->canExportParagraaf(['financialYear' => 'ref-1', 'status' => $broken]));

		$this->assertSame(
			[
				'RechtmatigheidGuard: canFinaliseToets failed — denying afronden (fail-closed)',
				'RechtmatigheidGuard: canResolveBevinding failed — denying oplossen (fail-closed)',
				'RechtmatigheidGuard: canVaststellenParagraaf failed — denying vaststellen (fail-closed)',
				'RechtmatigheidGuard: canExportParagraaf failed — denying export (fail-closed)',
			],
			$messages
		);
	}//end testAnUnreadableFieldIsRefusedAndLogged()
}//end class
