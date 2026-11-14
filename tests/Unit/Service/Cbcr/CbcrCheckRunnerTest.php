<?php

/**
 * Unit tests for CbcrCheckRunner and the CbcrPillar2Guard submit and
 * approve guards built on it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\CbcrPillar2Guard;
use OCA\Shillinq\Lifecycle\GuardObjectLookup;
use OCA\Shillinq\Lifecycle\GuardSubject;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Standards\Checks\CbcrChecks;
use OCA\Shillinq\Standards\Checks\GirChecks;
use OCA\Shillinq\Standards\RuleCatalogue;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Standards\Violation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The runner and the guards over an in-memory register.
 */
#[CoversClass(CbcrCheckRunner::class)]
#[CoversClass(CbcrPillar2Guard::class)]
#[UsesClass(CbcrChecks::class)]
#[UsesClass(GirChecks::class)]
#[UsesClass(RuleEngine::class)]
#[UsesClass(RuleCatalogue::class)]
#[UsesClass(Violation::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(GuardObjectLookup::class)]
#[UsesClass(GuardSubject::class)]
final class CbcrCheckRunnerTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Set up the Voorbeeld group for 2026.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					['id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'tin' => '001234567'],
					['id' => 'ge-productie', 'entityName' => 'Voorbeeld Productie B.V.', 'tin' => '009876543', 'ultimateParentEntity' => 'ge-holding'],
					['id' => 'ge-dormant', 'entityName' => 'Slapend B.V.', 'ultimateParentEntity' => 'ge-holding', 'cbcrIncluded' => false],
					['id' => 'ge-other', 'entityName' => 'Andere Groep', 'tin' => '1'],
				],
				'CbcrEntityFigures' => [
					['id' => 'f-h', 'entity' => 'ge-holding', 'fiscalYear' => 2026, 'source' => 'books', 'unmappedAccounts' => []],
					['id' => 'f-p', 'entity' => 'ge-productie', 'fiscalYear' => 2026, 'source' => 'books', 'unmappedAccounts' => ['4500']],
				],
				'CbcrJurisdictionSummary' => [
					[
						'id' => 's-nl', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
						'jurisdiction' => 'NL', 'mainBusinessActivities' => ['CBC504'], 'unrelatedPartyRevenue' => 300.0,
					],
					['id' => 's-other', 'ultimateParentEntity' => 'ge-other', 'period' => 'FY2026', 'jurisdiction' => 'NL', 'mainBusinessActivities' => []],
				],
				'CbcrReturn' => [],
				'GlobeInformationReturn' => [],
				'Pillar2SafeHarbour' => [
					[
						'id' => 'sh-nl', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
						'jurisdiction' => 'NL', 'testApplied' => 'simplified-etr', 'testResult' => 'pass',
					],
				],
				'Pillar2JurisdictionComputation' => [],
			]
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
	}//end setUp()

	/**
	 * The runner over the store.
	 *
	 * @return CbcrCheckRunner
	 */
	private function runner(): CbcrCheckRunner {
		return new CbcrCheckRunner($this->store, $this->appConfig);
	}//end runner()

	/**
	 * The guard over the store.
	 *
	 * @param ObjectServiceInterface|null $objects The object surface; the store when null.
	 * @param LoggerInterface|null        $logger  The logger.
	 *
	 * @return CbcrPillar2Guard
	 */
	private function guard(?ObjectServiceInterface $objects = null, ?LoggerInterface $logger = null): CbcrPillar2Guard {
		return new CbcrPillar2Guard($this->appConfig, ($logger ?? $this->createMock(LoggerInterface::class)), ($objects ?? $this->store));
	}//end guard()

	/**
	 * "An unmapped account is named": the check fails for the group's figures,
	 * the dormant entity and another group's summary are not judged.
	 *
	 * @return void
	 */
	public function testTheCbcChecksJudgeTheGroupsRecords(): void {
		$results = $this->runner()->runCbc(['id' => 'r-2026', 'reportingEntity' => 'ge-productie', 'period' => 'FY2026']);

		$byId = array_column($results, 'passed', 'id');
		$this->assertSame(
			[
				'cbc-figures-every-entity' => true, 'cbc-accounts-mapped' => false, 'cbc-entity-tin' => true, 'cbc-jurisdiction-activity' => true,
				'cbc-revenue-total' => true, 'cbc-residual-explained' => true, 'cbc-figures-from-books' => true,
			],
			$byId
		);
		$this->assertSame(['cbc-accounts-mapped'], CbcrCheckRunner::blockingFailures($results));
		$this->assertSame('mandatory', $results[1]['severity']);
		$this->assertStringContainsString('country-by-country category', $results[1]['statement']);
	}//end testTheCbcChecksJudgeTheGroupsRecords()

	/**
	 * The GIR checks see a jurisdiction with no safe harbour and no approved computation.
	 *
	 * @return void
	 */
	public function testTheGirChecksSeeAnUncoveredJurisdiction(): void {
		$this->store->setSchema('CbcrJurisdictionSummary');
		$this->store->saveObject([
			'id' => 's-ie', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
			'jurisdiction' => 'IE', 'mainBusinessActivities' => ['CBC508'],
		]);
		$return = ['id' => 'g-2026', 'ultimateParent' => 'ge-holding', 'period' => 'FY2026', 'missingElements' => []];

		$this->assertSame(['gir-jurisdiction-covered'], CbcrCheckRunner::blockingFailures($this->runner()->runGir($return)));

		$this->store->setSchema('Pillar2JurisdictionComputation');
		$this->store->saveObject([
			'id' => 'c-ie', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
			'jurisdiction' => 'IE', 'state' => 'approved',
		]);
		$this->assertSame([], CbcrCheckRunner::blockingFailures($this->runner()->runGir($return)));
	}//end testTheGirChecksSeeAnUncoveredJurisdiction()

	/**
	 * The fingerprint moves with the figures, not with the state or the filing bookkeeping.
	 *
	 * @return void
	 */
	public function testTheFingerprintFollowsTheFiguresOnly(): void {
		$return = ['id' => 'r-2026', 'reportingEntity' => 'ge-holding', 'period' => 'FY2026', 'state' => 'reconciled'];
		$before = $this->runner()->cbcFingerprint($return);

		$this->assertSame($before, $this->runner()->cbcFingerprint($return + ['taxAuthorityReference' => 'CBC-2027-0001', 'cbcrXmlSubmission' => 'gr-1']));
		$this->assertSame($before, $this->runner()->cbcFingerprint(['state' => 'submitted'] + $return));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $before);

		$this->store->setSchema('CbcrJurisdictionSummary');
		$this->store->saveObject([
			'id' => 's-nl', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
			'jurisdiction' => 'NL', 'mainBusinessActivities' => ['CBC504'], 'unrelatedPartyRevenue' => 301.0,
		]);
		$this->assertNotSame($before, $this->runner()->cbcFingerprint($return));
	}//end testTheFingerprintFollowsTheFiguresOnly()

	/**
	 * Submit needs a stored valid file of the current records and no failing mandatory check.
	 *
	 * @return void
	 */
	public function testSubmittingNeedsAValidCurrentFileAndPassingChecks(): void {
		$this->store->setSchema('CbcrEntityFigures');
		$this->store->saveObject(['id' => 'f-p', 'entity' => 'ge-productie', 'fiscalYear' => 2026, 'source' => 'books', 'unmappedAccounts' => []]);
		$return      = ['id' => 'r-2026', 'reportingEntity' => 'ge-holding', 'period' => 'FY2026', 'state' => 'reconciled'];
		$fingerprint = $this->runner()->cbcFingerprint($return);
		$guard       = $this->guard();

		$this->assertFalse($guard->canSubmitCbcrReturn('r-2026', $return), 'no file');
		$this->assertFalse(
			$guard->canSubmitCbcrReturn(
				'r-2026',
				$return + ['cbcrXmlSubmission' => 'gr-1', 'lastValidation' => ['valid' => false, 'fingerprint' => $fingerprint]]
			),
			'invalid file'
		);
		$this->assertFalse(
			$guard->canSubmitCbcrReturn(
				'r-2026',
				$return + ['cbcrXmlSubmission' => 'gr-1', 'lastValidation' => ['valid' => true, 'fingerprint' => str_repeat('0', 64)]]
			),
			'stale file'
		);
		$this->assertTrue($guard->canSubmitCbcrReturn(
			'r-2026',
			$return + ['cbcrXmlSubmission' => 'gr-1', 'lastValidation' => ['valid' => true, 'fingerprint' => $fingerprint]]
		));

		$this->store->setSchema('CbcrEntityFigures');
		$this->store->saveObject(['id' => 'f-p', 'entity' => 'ge-productie', 'fiscalYear' => 2026, 'source' => 'books', 'unmappedAccounts' => ['4500']]);
		$stale = $return + [
			'cbcrXmlSubmission' => 'gr-1',
			'lastValidation' => ['valid' => true, 'fingerprint' => $this->runner()->cbcFingerprint($return)],
		];
		$this->assertFalse($guard->canSubmitCbcrReturn('r-2026', $stale), 'failing mandatory check');
	}//end testSubmittingNeedsAValidCurrentFileAndPassingChecks()

	/**
	 * Approving a GIR needs a valid current file, and a broken register denies (fail-closed).
	 *
	 * @return void
	 */
	public function testApprovingAGirNeedsAValidFileAndFailsClosed(): void {
		$return      = ['id' => 'g-2026', 'ultimateParent' => 'ge-holding', 'period' => 'FY2026', 'missingElements' => []];
		$fingerprint = $this->runner()->girFingerprint($return);
		$valid       = $return + ['globeXmlSubmission' => 'gr-2', 'lastValidation' => ['valid' => true, 'fingerprint' => $fingerprint]];

		$this->assertTrue($this->guard()->canApproveGir('g-2026', $valid));
		$this->assertFalse($this->guard()->canApproveGir('g-2026', $return), 'no file');
		$this->assertFalse(
			$this->guard()->canApproveGir('g-2026', array_replace($valid, ['missingElements' => [['jurisdiction' => 'NL', 'element' => 'X']]])),
			'missing element'
		);

		$broken = $this->createMock(ObjectServiceInterface::class);
		$broken->method('setRegister')->willReturnSelf();
		$broken->method('setSchema')->willReturnSelf();
		$broken->method('findAll')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'CbcrPillar2Guard: GIR approve check failed — denying transition (fail-closed)',
			['returnId' => 'g-2026', 'exception' => 'register down']
		);
		$this->assertFalse($this->guard($broken, $logger)->canApproveGir('g-2026', $valid));
	}//end testApprovingAGirNeedsAValidFileAndFailsClosed()
}//end class
