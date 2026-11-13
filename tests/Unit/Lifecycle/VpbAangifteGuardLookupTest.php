<?php

/**
 * Unit tests for VpbAangifteGuard's record lookups.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\VpbAangifteGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guard reads the return, the annual report, the taxpayer, the
 * innovation box claims, the assessment and the fiscal unit from the
 * configured register when the transition does not pass them complete.
 *
 * @covers \OCA\Shillinq\Lifecycle\VpbAangifteGuard
 */
final class VpbAangifteGuardLookupTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every read, as [register, schema, query].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $reads = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard, over a register holding these records per schema.
	 *
	 * @param array<string, array<int, mixed>> $records  The records per schema.
	 * @param string                           $register The configured register slug.
	 *
	 * @return VpbAangifteGuard The guard.
	 */
	private function guard(array $records, string $register = 'books'): VpbAangifteGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);

		$current = ['', ''];
		$this->objects->method('setRegister')->willReturnCallback(
			function (string $slug) use (&$current) {
				$current[0] = $slug;
				return $this->objects;
			}
		);
		$this->objects->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$current) {
				$current[1] = $schema;
				return $this->objects;
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $query) use (&$current, $records): array {
				$this->reads[] = [$current[0], $current[1], $query];
				return $records[$current[1]] ?? [];
			}
		);

		return new VpbAangifteGuard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * The records of a return that may be filed.
	 *
	 * @return array<string, array<int, mixed>> The records per schema.
	 */
	private function fileable(): array {
		return [
			'VpbAangifte'        => ['x', ['id' => 'ret-1', 'taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1', 'status' => 'submitted']],
			'AnnualReport'       => [['status' => 'gedeponeerd']],
			'Belastingplichtige' => [['eRecognitionLevel' => 'EH4', 'digipoortCertificate' => 'cert']],
			'Innovatiebox'       => ['skipped', ['soDeclarationReference' => 'SO-1']],
		];
	}//end fileable()

	/**
	 * A return passed without taxpayer is read, and each linked record is read in turn.
	 *
	 * @return void
	 */
	public function testAnIncompleteReturnIsReadWithEveryLinkedRecord(): void {
		$this->assertTrue($this->guard($this->fileable())->canIndienen('ret-1', ['status' => 'draft']));

		$this->assertSame(
			[
				['books', 'VpbAangifte', ['filters' => ['id' => 'ret-1']]],
				['books', 'AnnualReport', ['filters' => ['id' => 'ar-1']]],
				['books', 'Belastingplichtige', ['filters' => ['id' => 'tp-1']]],
				['books', 'Innovatiebox', ['filters' => ['taxReturn' => 'ret-1']]],
			],
			$this->reads
		);
	}//end testAnIncompleteReturnIsReadWithEveryLinkedRecord()

	/**
	 * A return without its own id takes the claims of the transition id, in the default register.
	 *
	 * @return void
	 */
	public function testAPassedReturnWithoutIdUsesTheTransitionIdForTheClaims(): void {
		$records = $this->fileable();
		$guard   = $this->guard($records, '');

		$this->assertTrue($guard->canIndienen('ret-9', ['taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1']));
		$this->assertSame(['shillinq', 'Innovatiebox', ['filters' => ['taxReturn' => 'ret-9']]], end($this->reads));
	}//end testAPassedReturnWithoutIdUsesTheTransitionIdForTheClaims()

	/**
	 * Each missing link refuses the filing.
	 *
	 * @return void
	 */
	public function testEachMissingLinkRefusesTheFiling(): void {
		$cases = [
			'no return'          => [['VpbAangifte' => []], 'ret-1', null],
			'no report link'     => [[], 'ret-1', ['taxpayer' => 'tp-1']],
			'report not found'   => [[], 'ret-1', ['taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1']],
			'report not final'   => [['AnnualReport' => [['status' => 'draft']]], 'ret-1', ['taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1']],
			'no taxpayer'        => [['AnnualReport' => [['status' => 'determined']]], 'ret-1', ['taxpayer' => '', 'commercialProfit' => 'ar-1']],
			'taxpayer not found' => [['AnnualReport' => [['status' => 'determined']]], 'ret-1', ['taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1']],
			'no certificate'     => [
				['AnnualReport' => [['status' => 'determined']], 'Belastingplichtige' => [['eRecognitionLevel' => 'EH3']]],
				'ret-1',
				['taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1'],
			],
			'no return id'       => [
				['AnnualReport' => [['status' => 'determined']], 'Belastingplichtige' => [['eRecognitionLevel' => 'EH3', 'digipoortCertificate' => 'c']]],
				'',
				['taxpayer' => 'tp-1', 'commercialProfit' => 'ar-1'],
			],
		];

		foreach ($cases as $label => [$records, $id, $object]) {
			$this->setUp();
			$this->assertFalse($this->guard($records)->canIndienen($id, $object), $label);
		}
	}//end testEachMissingLinkRefusesTheFiling()

	/**
	 * A failing read refuses the filing and is logged.
	 *
	 * @return void
	 */
	public function testAFailingReadRefusesTheFilingAndIsLogged(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('books');
		$this->logger->expects($this->once())->method('error')->with(
			'VpbAangifteGuard: canIndienen check failed — denying indienen transition (fail-closed)',
			['taxReturnId' => 'ret-1', 'exception' => 'store down']
		);

		$this->assertFalse((new VpbAangifteGuard($config, $this->logger, $this->objects))->canIndienen('ret-1'));
	}//end testAFailingReadRefusesTheFilingAndIsLogged()

	/**
	 * An assessment passed without its return is read, then its return is read.
	 *
	 * @return void
	 */
	public function testAnIncompleteAssessmentIsReadWithItsReturn(): void {
		$guard = $this->guard(
			[
				'DefinitieveAanslag' => [['taxReturn' => 'ret-1']],
				'VpbAangifte'        => [['status' => 'onherroepelijk']],
			]
		);

		$this->assertTrue($guard->canAanslagOntvangen('as-1', ['amount' => 10]));
		$this->assertSame(
			[
				['books', 'DefinitieveAanslag', ['filters' => ['id' => 'as-1']]],
				['books', 'VpbAangifte', ['filters' => ['id' => 'ret-1']]],
			],
			$this->reads
		);
	}//end testAnIncompleteAssessmentIsReadWithItsReturn()

	/**
	 * An unknown assessment or return refuses receiving the assessment.
	 *
	 * @return void
	 */
	public function testAnUnknownAssessmentOrReturnIsRefused(): void {
		$guard = $this->guard([]);

		$this->assertFalse($guard->canAanslagOntvangen('as-1'));
		$this->assertFalse($guard->canAanslagOntvangen('as-1', ['taxReturn' => 'ret-1']));
		$this->assertFalse($guard->canAanslagOntvangen('as-1', ['taxReturn' => '']));
		$this->assertCount(2, $this->reads, 'a blank return id is not read');
	}//end testAnUnknownAssessmentOrReturnIsRefused()

	/**
	 * A fiscal unit passed without holding percentage is read; exactly 95 percent qualifies.
	 *
	 * @return void
	 */
	public function testAnIncompleteFiscalUnitIsReadAndNinetyFivePercentQualifies(): void {
		$guard = $this->guard(
			['FiscaleEenheid' => [['holdingPercentage' => '95', 'equalFinancialYears' => true, 'establishmentNetherlands' => true]]]
		);

		$this->assertTrue($guard->canVoegen('fe-1', []));
		$this->assertFalse($guard->canVoegen('', null));
		$this->assertFalse(
			$guard->canVoegen('fe-1', ['holdingPercentage' => 100, 'equalFinancialYears' => 'yes', 'establishmentNetherlands' => true]),
			'only a real true counts as equal financial years'
		);
		$this->assertSame([['books', 'FiscaleEenheid', ['filters' => ['id' => 'fe-1']]]], $this->reads);
	}//end testAnIncompleteFiscalUnitIsReadAndNinetyFivePercentQualifies()

	/**
	 * A failing read refuses receiving an assessment and joining a fiscal unit, logged.
	 *
	 * @return void
	 */
	public function testFailingReadsForAssessmentAndUnitAreLogged(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('books');
		$messages = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);
		$guard = new VpbAangifteGuard($config, $this->logger, $this->objects);

		$this->assertFalse($guard->canAanslagOntvangen('as-1'));
		$this->assertFalse($guard->canVoegen('fe-1'));
		$this->assertSame(
			[
				['VpbAangifteGuard: canAanslagOntvangen check failed — denying transition (fail-closed)', ['aanslagId' => 'as-1', 'exception' => 'store down']],
				['VpbAangifteGuard: canVoegen check failed — denying voeging (fail-closed)', ['eenheidId' => 'fe-1', 'exception' => 'store down']],
			],
			$messages
		);
	}//end testFailingReadsForAssessmentAndUnitAreLogged()
}//end class
