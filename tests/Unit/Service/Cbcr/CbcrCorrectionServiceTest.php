<?php

/**
 * Unit tests for CbcrCorrectionService: which reports can be corrected,
 * what a correction takes over, and that an open correction is reused.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use InvalidArgumentException;
use OCA\Shillinq\Service\Cbcr\CbcrCorrectionService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The service over an in-memory register.
 */
#[CoversClass(CbcrCorrectionService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcrCorrectionServiceTest extends TestCase {

	/**
	 * The submitted 2026 report of the Voorbeeld group.
	 *
	 * @var array<string,mixed>
	 */
	private const SUBMITTED = [
		'id' => 'cbc-2026',
		'period' => 'FY2026',
		'reportingEntity' => 'ge-holding',
		'reportingRole' => 'CBC701',
		'submissionDeadline' => '2027-12-31',
		'mcaaPartnerJurisdictions' => ['DE', 'IE'],
		'jurisdictionSummaries' => ['sum-nl', 'sum-de'],
		'reconciliationResidual' => 12000.0,
		'cbcrXmlSubmission' => 'file-1',
		'taxAuthorityReference' => 'CBC-2027-0001',
		'docRefIds' => ['sum-de' => 'NL2027-CBC-DE-1'],
		'lastValidation' => ['valid' => true],
		'state' => 'submitted',
	];

	/**
	 * The service over the given CbcrReturn rows.
	 *
	 * @param array<int,array<string,mixed>> $returns The rows.
	 * @param InMemoryObjectServiceStub|null $store   Receives the store.
	 *
	 * @return CbcrCorrectionService
	 */
	private function service(array $returns, ?InMemoryObjectServiceStub &$store = null): CbcrCorrectionService {
		$store     = new InMemoryObjectServiceStub(['CbcrReturn' => $returns]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CbcrCorrectionService($store, $appConfig, $l10n);
	}//end service()

	/**
	 * A correction is a new draft for the same entity, year and role that
	 * points at the report; the figures, file and reference start empty.
	 *
	 * @return void
	 */
	public function testACorrectionCarriesTheReportingFieldsAndPointsAtTheReport(): void {
		$result = $this->service([self::SUBMITTED], $store)->createCorrection(self::SUBMITTED);

		$this->assertTrue($result['created']);
		$this->assertCount(1, $store->saved);
		$this->assertSame('CbcrReturn', $store->saved[0]['schema']);
		$saved = $store->saved[0]['object'];
		$this->assertSame('cbc-2026', $saved['correctsReturn']);
		$this->assertSame('draft', $saved['state']);
		$this->assertSame('FY2026', $saved['period']);
		$this->assertSame('ge-holding', $saved['reportingEntity']);
		$this->assertSame('CBC701', $saved['reportingRole']);
		$this->assertSame('2027-12-31', $saved['submissionDeadline']);
		$this->assertSame(['DE', 'IE'], $saved['mcaaPartnerJurisdictions']);
		foreach (['jurisdictionSummaries', 'reconciliationResidual', 'cbcrXmlSubmission', 'taxAuthorityReference', 'docRefIds', 'lastValidation'] as $own) {
			$this->assertArrayNotHasKey($own, $saved, $own . ' is the correction\'s own');
		}

		$this->assertSame($saved['id'], $result['correction']['id']);
		$this->assertSame('cbc-2026', $result['correction']['correctsReturn']);
	}//end testACorrectionCarriesTheReportingFieldsAndPointsAtTheReport()

	/**
	 * A locked report can be corrected too, and a field it lacks is not written.
	 *
	 * @return void
	 */
	public function testALockedReportCanBeCorrected(): void {
		$locked = ['id' => 'cbc-2025', 'period' => 'FY2025', 'reportingEntity' => 'ge-holding', 'state' => 'locked', 'reportingRole' => null];

		$result = $this->service([$locked], $store)->createCorrection($locked);

		$this->assertTrue($result['created']);
		$this->assertSame(
			['correctsReturn' => 'cbc-2025', 'state' => 'draft', 'period' => 'FY2025', 'reportingEntity' => 'ge-holding'],
			array_diff_key($store->saved[0]['object'], ['id' => true])
		);
	}//end testALockedReportCanBeCorrected()

	/**
	 * A second Create correction answers with the correction still open and
	 * saves nothing; one already filed does not count.
	 *
	 * @return void
	 */
	public function testAnOpenCorrectionIsReused(): void {
		$filedCorrection = ['id' => 'cbc-2026-c1', 'correctsReturn' => 'cbc-2026', 'state' => 'submitted'];
		$openCorrection  = ['id' => 'cbc-2026-c2', 'correctsReturn' => 'cbc-2026', 'state' => 'reconciled'];
		$otherReport     = ['id' => 'cbc-x-c1', 'correctsReturn' => 'cbc-x', 'state' => 'draft'];

		$result = $this->service([self::SUBMITTED, $filedCorrection, $otherReport, $openCorrection], $store)->createCorrection(self::SUBMITTED);

		$this->assertFalse($result['created']);
		$this->assertSame('cbc-2026-c2', $result['correction']['id']);
		$this->assertSame([], $store->saved);
	}//end testAnOpenCorrectionIsReused()

	/**
	 * A report in draft or reconciled, or one without an id, cannot be
	 * corrected, and nothing is saved.
	 *
	 * @return void
	 */
	public function testOnlyAFiledReportCanBeCorrected(): void {
		$notFiled = [['id' => 'cbc-d', 'state' => 'draft'], ['id' => 'cbc-r', 'state' => 'reconciled'], ['id' => 'cbc-n'], ['state' => 'submitted']];
		foreach ($notFiled as $return) {
			$service = $this->service([$return], $store);
			try {
				$service->createCorrection($return);
				$this->fail('A report in ' . json_encode($return) . ' was corrected.');
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Only a submitted country-by-country report can be corrected.', $e->getMessage());
			}

			$this->assertSame([], $store->saved);
		}
	}//end testOnlyAFiledReportCanBeCorrected()
}//end class
