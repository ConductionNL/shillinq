<?php

/**
 * Unit tests for RechtmatigheidGuard.
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
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RechtmatigheidGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RechtmatigheidGuard per REQ-RV-002, REQ-RV-005, REQ-RV-006, REQ-RV-010.
 *
 * Covers:
 * - canFinaliseToets: voldoet permits; voldoet_niet needs >=50-char onderbouwing + bevinding FK.
 * - canResolveBevinding: requires correctieboeking_id.
 * - canVaststellenParagraaf: binnen tolerantie permits; buiten tolerantie needs verklaring.
 * - canExportParagraaf: only status definitief permitted.
 */
class RechtmatigheidGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->guard = new RechtmatigheidGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * A voldoet uitkomst may be finalised without onderbouwing or bevinding (REQ-RV-002).
	 *
	 * @return void
	 */
	public function testCanFinaliseToetsPermitsVoldoet(): void {
		$result = $this->guard->canFinaliseToets(toets: ['outcome' => 'voldoet']);
		self::assertTrue(condition: $result, message: 'voldoet uitkomst must be permitted');

	}//end testCanFinaliseToetsPermitsVoldoet()

	/**
	 * A niet_van_toepassing uitkomst has no substantiation gate (REQ-RV-002).
	 *
	 * @return void
	 */
	public function testCanFinaliseToetsPermitsNietVanToepassing(): void {
		$result = $this->guard->canFinaliseToets(toets: ['outcome' => 'niet_van_toepassing']);
		self::assertTrue(condition: $result, message: 'niet_van_toepassing must be permitted');

	}//end testCanFinaliseToetsPermitsNietVanToepassing()

	/**
	 * A voldoet_niet uitkomst with short onderbouwing is denied (REQ-RV-002).
	 *
	 * @return void
	 */
	public function testCanFinaliseToetsDeniesShortOnderbouwing(): void {
		$result = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Te kort.',
				'lawfulnessFinding' => 'bev-1',
			]
		);
		self::assertFalse(condition: $result, message: 'Short onderbouwing must deny finalisation');

	}//end testCanFinaliseToetsDeniesShortOnderbouwing()

	/**
	 * A voldoet_niet uitkomst without a linked bevinding is denied (REQ-RV-002).
	 *
	 * @return void
	 */
	public function testCanFinaliseToetsDeniesMissingBevinding(): void {
		$result = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => str_repeat('a', 60),
				'lawfulnessFinding' => '',
			]
		);
		self::assertFalse(condition: $result, message: 'Missing bevinding must deny finalisation');

	}//end testCanFinaliseToetsDeniesMissingBevinding()

	/**
	 * A voldoet_niet uitkomst with full onderbouwing + bevinding is permitted (REQ-RV-002).
	 *
	 * @return void
	 */
	public function testCanFinaliseToetsPermitsWhenComplete(): void {
		$result = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Factuur 2026-441 valt onder raamovereenkomst RO-2024-12 (eerder Europees aanbesteed).',
				'lawfulnessFinding' => 'bev-142',
			]
		);
		self::assertTrue(condition: $result, message: 'Complete negative toets must be permitted');

	}//end testCanFinaliseToetsPermitsWhenComplete()

	/**
	 * An onzeker uitkomst follows the same substantiation gate (REQ-RV-002).
	 *
	 * @return void
	 */
	public function testCanFinaliseToetsDeniesOnzekerWithoutBevinding(): void {
		$result = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'onzeker',
				'substantiation' => str_repeat('b', 55),
			]
		);
		self::assertFalse(condition: $result, message: 'onzeker without bevinding must be denied');

	}//end testCanFinaliseToetsDeniesOnzekerWithoutBevinding()

	/**
	 * A bevinding without correctieboeking_id cannot be resolved (REQ-RV-010).
	 *
	 * @return void
	 */
	public function testCanResolveBevindingDeniesWithoutCorrectie(): void {
		$result = $this->guard->canResolveBevinding(bevinding: ['findingNumber' => 'RV-2026-0142']);
		self::assertFalse(condition: $result, message: 'No correctieboeking must deny resolution');

	}//end testCanResolveBevindingDeniesWithoutCorrectie()

	/**
	 * A bevinding with correctieboeking_id may be resolved (REQ-RV-010).
	 *
	 * @return void
	 */
	public function testCanResolveBevindingPermitsWithCorrectie(): void {
		$result = $this->guard->canResolveBevinding(
			bevinding: [
				'findingNumber' => 'RV-2026-0142',
				'correction_entry_id' => 'je-2026-9001',
			]
		);
		self::assertTrue(condition: $result, message: 'Linked correctieboeking must permit resolution');

	}//end testCanResolveBevindingPermitsWithCorrectie()

	/**
	 * A paragraaf binnen tolerantie may be vastgesteld without a toelichting (REQ-RV-005).
	 *
	 * @return void
	 */
	public function testCanVaststellenParagraafPermitsBinnenTolerantie(): void {
		$result = $this->guard->canVaststellenParagraaf(paragraph: ['within_tolerance' => true]);
		self::assertTrue(condition: $result, message: 'Binnen tolerantie must permit vaststellen');

	}//end testCanVaststellenParagraafPermitsBinnenTolerantie()

	/**
	 * A paragraaf buiten tolerantie without verklaring is denied (REQ-RV-005).
	 *
	 * @return void
	 */
	public function testCanVaststellenParagraafDeniesBuitenTolerantieZonderToelichting(): void {
		$result = $this->guard->canVaststellenParagraaf(
			paragraph: [
				'within_tolerance' => false,
				'declaration_college' => '',
				'financialYear' => 2026,
			]
		);
		self::assertFalse(condition: $result, message: 'Buiten tolerantie zonder toelichting must be denied');

	}//end testCanVaststellenParagraafDeniesBuitenTolerantieZonderToelichting()

	/**
	 * A paragraaf buiten tolerantie with verklaring may be vastgesteld (REQ-RV-005).
	 *
	 * @return void
	 */
	public function testCanVaststellenParagraafPermitsBuitenTolerantieMetToelichting(): void {
		$result = $this->guard->canVaststellenParagraaf(
			paragraph: [
				'within_tolerance' => false,
				'declaration_college' => 'Het college licht de overschrijding nader toe en treft maatregelen.',
				'financialYear' => 2026,
			]
		);
		self::assertTrue(condition: $result, message: 'Buiten tolerantie met toelichting must be permitted');

	}//end testCanVaststellenParagraafPermitsBuitenTolerantieMetToelichting()

	/**
	 * Only a definitief paragraaf may be exported (REQ-RV-006).
	 *
	 * @return void
	 */
	public function testCanExportParagraafPermitsDefinitief(): void {
		$result = $this->guard->canExportParagraaf(paragraph: ['status' => 'final', 'financialYear' => 2026]);
		self::assertTrue(condition: $result, message: 'Definitief paragraaf must permit export');

	}//end testCanExportParagraafPermitsDefinitief()

	/**
	 * A concept paragraaf blocks export (REQ-RV-006).
	 *
	 * @return void
	 */
	public function testCanExportParagraafDeniesConcept(): void {
		$result = $this->guard->canExportParagraaf(paragraph: ['status' => 'draft', 'financialYear' => 2026]);
		self::assertFalse(condition: $result, message: 'Concept paragraaf must block export');

	}//end testCanExportParagraafDeniesConcept()

	/**
	 * A vastgesteld_college (not yet definitief) paragraaf blocks export (REQ-RV-006).
	 *
	 * @return void
	 */
	public function testCanExportParagraafDeniesVastgesteldCollege(): void {
		$result = $this->guard->canExportParagraaf(paragraph: ['status' => 'vastgesteld_college', 'financialYear' => 2026]);
		self::assertFalse(condition: $result, message: 'Non-definitief paragraaf must block export');

	}//end testCanExportParagraafDeniesVastgesteldCollege()
}//end class
