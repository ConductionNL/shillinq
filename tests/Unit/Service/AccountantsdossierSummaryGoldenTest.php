<?php

/**
 * Golden test for the Accountantsdossier HTML summary.
 *
 * Renders the PDF/A-oriented summary for two fixed bundles (with and without
 * a declaration draft, with characters that must be escaped) and compares the
 * exact HTML with a recorded golden, so restructuring the renderer cannot
 * change a byte of the document.
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AccountantsdossierExportService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\OpenRegisterFaithfulObjectService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Compares the rendered summary with the recorded golden.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AccountantsdossierSummaryGoldenTest extends TestCase {

	/**
	 * Path of the recorded golden.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/fixtures/accountantsdossier-summary-golden.json';

	/**
	 * A full bundle.
	 *
	 * @return array<string, mixed> The bundle.
	 */
	private static function bundle(): array {
		return [
			'auditProtocol' => [
				'auditYear' => '2026',
				'organisationId' => 'gem-<0363>',
				'organisationType' => 'gemeente',
				'effectiveFrom' => '2026-01-01',
				'effectiveTo' => '2026-12-31',
				'version' => 'v2 "final"',
				'adoptionDecision' => ['decisionType' => 'raadsbesluit', 'decisionNumber' => 'RB-7', 'date' => '2026-02-01'],
			],
			'verklaringDraft' => [
				'proposedOpinion' => 'goedkeurend & schoon',
				'opinionRationale' => "Geen bevindingen >1%",
				'signOff' => ['auditor' => "O'Brien", 'afmPermitNumber' => 'AFM-1', 'date' => '2026-03-01', 'place' => 'Utrecht'],
			],
			'auditFindings' => [
				['transaction' => 'T-1', 'findingType' => 'fout', 'topic' => 'rechtm.', 'amount' => 1234.5, 'severity' => 'hoog', 'status' => 'open', 'controllerResponse' => '<b>ok</b>', 'auditorConclusion' => 'akkoord'],
				[],
			],
			'toleranceMatrix' => [
				['topic' => 'lasten', 'lawfulnessApprovalCeiling' => 1, 'lawfulnessQualificationCeiling' => '3', 'faithfulnessApprovalCeiling' => 1.5, 'faithfulnessQualificationCeiling' => null, 'uncertaintyCeiling' => 'x'],
			],
			'sisaAssurance' => [
				['schemeCode' => 'A1', 'accountableParty' => 'gem', 'specificBenefit' => 'SPUK', 'assuranceLevel' => 'redelijk', 'findings' => [1, 2]],
				['schemeCode' => 'B2'],
			],
			'auditSamples' => [
				['population' => 'crediteuren', 'selectionMethod' => 'MUS', 'sampleSize' => '25', 'reproducibleSeed' => 's-1', 'extractedAt' => '2026-02-02'],
			],
			'materialiteit' => [
				['scope' => 'jaarrekening', 'base' => 1000000, 'percentage' => 1, 'calculatedAmount' => 10000, 'status' => 'vastgesteld'],
				['scope' => 'sisa', 'baseLabel' => 'lasten', 'base' => '5', 'status' => 'concept'],
			],
		];

	}//end bundle()

	/**
	 * Bundles to render.
	 *
	 * @return array<string, array{0: array<string, mixed>}> Cases.
	 */
	public static function bundles(): array {
		$withoutDeclaration = self::bundle();
		$withoutDeclaration['verklaringDraft'] = null;
		$withoutDeclaration['auditProtocol'] = [];
		$empty = ['auditProtocol' => null, 'verklaringDraft' => null, 'auditFindings' => [], 'toleranceMatrix' => [], 'sisaAssurance' => [], 'auditSamples' => [], 'materialiteit' => []];

		return [
			'full'                => [self::bundle()],
			'without declaration' => [$withoutDeclaration],
			'empty'               => [$empty],
		];

	}//end bundles()

	/**
	 * The summary equals the golden.
	 *
	 * @param array<string, mixed> $bundle The collected bundle.
	 *
	 * @return void
	 */
	#[DataProvider('bundles')]
	public function testSummaryMatchesGolden(array $bundle): void {
		$service = new AccountantsdossierExportService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ObjectServiceInterface::class)
		);
		$render = new ReflectionMethod($service, 'renderSummaryHtml');
		$render->setAccessible(true);
		$manifest = ['generatedAt' => '2026-04-01T10:00:00Z', 'sha256' => 'abc<>123', 'packageId' => 'BADO-2026-<x>'];
		$html = $render->invoke($service, $manifest, $bundle);

		$key = (string)json_encode($bundle);
		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			$golden[$key] = $html;
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		self::assertSame($golden[$key], $html);

	}//end testSummaryMatchesGolden()

	/**
	 * The whole dossier envelope over a seeded register: collection order,
	 * finding selection, manifest, ledger, hash and summary. The generation
	 * timestamp and the package id derived from it are masked.
	 *
	 * @return void
	 */
	public function testDossierEnvelopeMatchesGolden(): void {
		$store = new OpenRegisterFaithfulObjectService();
		$store->seed(schema: 'Controleprotocol', rows: [['id' => 'cp-1', 'auditYear' => 2026, 'organisationId' => 'gem-1', 'organisationType' => 'gemeente', 'version' => 'v1']]);
		$store->seed(schema: 'ToleranceMatrix', rows: [['id' => 't2', 'protocol' => 'cp-1', 'topic' => 'z'], ['id' => 't1', 'protocol' => 'cp-1', 'topic' => 'a'], ['id' => 't3', 'protocol' => 'other', 'topic' => 'm']]);
		$store->seed(schema: 'Materialiteit', rows: [['id' => 'm2', 'protocol' => 'cp-1', 'scope' => 'sisa'], ['id' => 'm1', 'protocol' => 'cp-1', 'scope' => 'jaarrekening', 'base' => 100]]);
		$store->seed(schema: 'AuditSample', rows: [['id' => 's2', 'protocol' => 'cp-1', 'extractedAt' => '2026-02-02'], ['id' => 's1', 'protocol' => 'cp-1', 'extractedAt' => '2026-01-01']]);
		$store->seed(schema: 'AuditFinding', rows: [['id' => 'f3', 'sample' => 's2', 'amount' => 3], ['id' => 'f1', 'sample' => 's1', 'amount' => 1], ['id' => 'f9', 'sample' => 'sx'], ['id' => 'f0', 'sample' => '']]);
		$store->seed(schema: 'VerklaringDraft', rows: [['id' => 'v1', 'protocol' => 'cp-1', 'proposedOpinion' => 'goedkeurend']]);
		$store->seed(schema: 'SiSaAssurance', rows: [['id' => 'q2', 'protocol' => 'cp-1', 'schemeCode' => 'B'], ['id' => 'q1', 'protocol' => 'cp-1', 'schemeCode' => 'A']]);

		$service = new AccountantsdossierExportService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			new DuckObjectServiceAdapter($store)
		);
		$envelope = $service->buildDossier(protocolId: 'cp-1');
		$masked = str_replace(
			[$envelope['packageId'], $envelope['generatedAt']],
			['<packageId>', '<generatedAt>'],
			(string)json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		$masked = (string)preg_replace('/urn:uuid:[0-9a-f-]{36}/', 'urn:uuid:<uuid>', $masked);

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			$golden['dossier envelope'] = json_decode($masked, true);
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		self::assertSame($golden['dossier envelope'], json_decode($masked, true));

	}//end testDossierEnvelopeMatchesGolden()
}//end class
