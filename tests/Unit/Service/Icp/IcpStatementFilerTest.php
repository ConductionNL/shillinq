<?php

/**
 * Unit tests for IcpStatementFiler: the ICP statement filed through the
 * Digipoort hand-off.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Icp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Icp;

use DateTime;
use DomainException;
use OCA\Shillinq\Kernel\Contracts\IcpServiceInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult;
use OCA\Shillinq\Service\Icp\IcpStatementFiler;
use OCA\Shillinq\Service\Icp\IcpStatementPreparer;
use OCA\Shillinq\Service\IcpCalculator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The hand-off tax-digipoort-filing adds (task 3.1), named after its real
 * class: it records what it was handed and answers a fixed delivery status.
 */
final class IntegriqSbrHandoffAdapter implements DigipoortSbrAdapterInterface {

	/**
	 * The envelopes handed over.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $handed = [];

	/**
	 * Construct the hand-off.
	 *
	 * @param string $answer  The delivery status Digipoort answers.
	 * @param bool   $dormant Whether no Digipoort connection exists.
	 */
	public function __construct(
		private readonly string $answer='ACCEPTED',
		private readonly bool $dormant=false,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $payload The envelope.
	 *
	 * @return DigipoortSubmissionResult
	 */
	public function submit(array $payload): DigipoortSubmissionResult {
		$this->handed[] = $payload;

		return new DigipoortSubmissionResult(deliveryStatus: $this->answer, reference: 'DGP-2026-0042', dormant: $this->dormant);
	}//end submit()

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function isDormant(): bool {
		return $this->dormant;
	}//end isDormant()
}//end class

/**
 * The ICP statement is filed through Digipoort (REQ-ICR-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(IcpStatementFiler::class)]
#[UsesClass(IcpStatementPreparer::class)]
#[UsesClass(IcpCalculator::class)]
#[UsesClass(DigipoortSubmissionResult::class)]
final class IcpStatementFilerTest extends TestCase {

	use IcpObjectStore;

	/**
	 * The Q3 statement in a status.
	 *
	 * @param string $status The statement's status.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function rows(string $status): array {
		return [
			'IcpOpgaaf' => [
				[
					'@self' => ['id' => 'stmt-q3'],
					'administrationId' => 'adm-1',
					'period' => '2026-Q3',
					'periodicity' => 'quarterly',
					'status' => $status,
					'type' => 'initial',
					'lines' => [
						['buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 12000.0],
						['buyerVatId' => 'DE123456789', 'supplyType' => 'L', 'amountExclVat' => 3000.0],
					],
					'total' => 15000.0,
				],
			],
			'Administration' => [
				['@self' => ['id' => 'other'], 'name' => 'Other BV'],
				['@self' => ['id' => 'adm-1'], 'name' => 'Brouwerij De Molen BV', 'kvkNumber' => '12345678', 'rsin' => '001234567', 'vatNumber' => 'NL001234567B01'],
			],
		];
	}//end rows()

	/**
	 * The filer over the rows, the hand-off and a reconciliation answer.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows      Rows per schema.
	 * @param IntegriqSbrHandoffAdapter                    $digipoort The hand-off.
	 * @param bool                                         $matches   Whether the statement reconciles.
	 *
	 * @return IcpStatementFiler
	 */
	private function filer(array $rows, IntegriqSbrHandoffAdapter $digipoort, bool $matches=true): IcpStatementFiler {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$store = $this->objectStore(rows: $rows);
		$icp = $this->createMock(IcpServiceInterface::class);
		$icp->method('reconcile')->willReturn(['matches' => $matches, 'missing' => false]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20T09:30:00+00:00'));

		return new IcpStatementFiler(
			preparer: new IcpStatementPreparer(icpService: $icp, objectService: $store, appConfig: $config),
			icpService: $icp,
			calculator: new IcpCalculator(),
			digipoort: $digipoort,
			objectService: $store,
			appConfig: $config,
			time: $time,
		);
	}//end filer()

	/**
	 * A finalised statement is handed over as the ICP instance and shows submitted.
	 *
	 * @return void
	 */
	public function testAFinalisedStatementIsHandedOverAndShowsSubmitted(): void {
		$digipoort = new IntegriqSbrHandoffAdapter();

		$result = $this->filer(rows: self::rows('finalized'), digipoort: $digipoort)->file(administrationId: 'adm-1', period: '2026-Q3');

		$this->assertSame(['status' => 'submitted', 'deliveryStatus' => 'ACCEPTED', 'reference' => 'DGP-2026-0042'], $result);
		$this->assertCount(1, $digipoort->handed);
		$envelope = $digipoort->handed[0];
		$this->assertSame('icp-opgaaf', $envelope['filingType']);
		$this->assertSame(['2026-07-01', '2026-09-30'], [$envelope['reportingPeriodStartDate'], $envelope['reportingPeriodEndDate']]);
		$this->assertSame(['Brouwerij De Molen BV', '12345678', '001234567'], [$envelope['organizationLegalName'], $envelope['kvkNumber'], $envelope['fiscalNumber']]);
		$this->assertSame(['NT18', 'stmt-q3'], [$envelope['taxonomyVersion'], $envelope['correlationId']]);
		$this->assertSame(hash('sha256', $envelope['xbrlInstanceBytes']), $envelope['xbrlChecksum']);
		$this->assertStringContainsString('<gl-cor:periodIdentifier>2026-Q3</gl-cor:periodIdentifier>', $envelope['xbrlInstanceBytes']);
		$this->assertStringContainsString('<bd-i:filerVatId>NL001234567B01</bd-i:filerVatId>', $envelope['xbrlInstanceBytes']);
		$this->assertSame(2, substr_count($envelope['xbrlInstanceBytes'], '<bd-i:IntracommunautairePrestatieMutatieSpecificatie>'));

		$this->assertCount(1, $this->saves);
		$this->assertSame('stmt-q3', $this->saves[0]['uuid']);
		$this->assertSame('IcpOpgaaf', $this->saves[0]['schema']);
		$saved = $this->saves[0]['object'];
		$this->assertArrayNotHasKey('@self', $saved);
		$this->assertSame(['submitted', 'DGP-2026-0042', '2026-10-20T09:30:00+00:00'], [$saved['status'], $saved['taxAuthorityReference'], $saved['submittedAt']]);
		$this->assertSame($envelope['xbrlInstanceBytes'], $saved['xmlPayload']);
	}//end testAFinalisedStatementIsHandedOverAndShowsSubmitted()

	/**
	 * A refused delivery shows rejected; a deferred one leaves it finalised and unsaved.
	 *
	 * @return void
	 */
	public function testTheStatusFollowsOnlyWhatDigipoortAnswers(): void {
		$rejected = $this->filer(rows: self::rows('finalized'), digipoort: new IntegriqSbrHandoffAdapter('REJECTED'))
			->file(administrationId: 'adm-1', period: '2026-Q3');
		$this->assertSame('rejected', $rejected['status']);
		$this->assertSame('rejected', $this->saves[0]['object']['status']);
		$this->assertArrayNotHasKey('submittedAt', $this->saves[0]['object']);

		$this->saves = [];
		$deferred = $this->filer(rows: self::rows('finalized'), digipoort: new IntegriqSbrHandoffAdapter('deferred'))
			->file(administrationId: 'adm-1', period: '2026-Q3');
		$this->assertSame(['status' => 'finalized', 'deliveryStatus' => 'DEFERRED', 'reference' => 'DGP-2026-0042'], $deferred);
		$this->assertSame([], $this->saves);
	}//end testTheStatusFollowsOnlyWhatDigipoortAnswers()

	/**
	 * Filing is refused while the hand-off is dormant, before anything is read.
	 *
	 * @return void
	 */
	public function testFilingIsRefusedWhileTheHandOffIsDormant(): void {
		$digipoort = new IntegriqSbrHandoffAdapter('DEFERRED', true);
		$filer = $this->filer(rows: self::rows('finalized'), digipoort: $digipoort);

		$this->assertFalse($filer->isAvailable());
		$this->assertTrue($this->filer(rows: [], digipoort: new IntegriqSbrHandoffAdapter())->isAvailable());
		try {
			$filer->file(administrationId: 'adm-1', period: '2026-Q3');
			$this->fail('Filed through a dormant hand-off.');
		} catch (DomainException $e) {
			$this->assertSame('Filing through Digipoort is not available yet. Download the XML and file it yourself.', $e->getMessage());
		}

		$this->assertSame([], $digipoort->handed);
	}//end testFilingIsRefusedWhileTheHandOffIsDormant()

	/**
	 * A draft, a missing statement and one outside the tolerance are not filed.
	 *
	 * @return void
	 */
	public function testOnlyAFinalisedReconcilingStatementIsFiled(): void {
		$cases = [
			'Only a finalised ICP statement can be filed; this one is draft.' => [self::rows('draft'), true],
			'No ICP statement has been prepared for 2026-Q3. Prepare it on the ICP page first.' => [[], true],
			'The ICP statement does not reconcile with box 3b of the VAT return within EUR 1.' => [self::rows('finalized'), false],
		];
		foreach ($cases as $message => [$rows, $matches]) {
			$digipoort = new IntegriqSbrHandoffAdapter();
			try {
				$this->filer(rows: $rows, digipoort: $digipoort, matches: $matches)->file(administrationId: 'adm-1', period: '2026-Q3');
				$this->fail('Filed: ' . $message);
			} catch (DomainException $e) {
				$this->assertSame($message, $e->getMessage());
			}

			$this->assertSame([], $digipoort->handed);
		}

		$this->assertSame([], $this->saves);
	}//end testOnlyAFinalisedReconcilingStatementIsFiled()

	/**
	 * The instance of a monthly draft can be downloaded; without an administration the filer number is empty.
	 *
	 * @return void
	 */
	public function testTheInstanceOfAPreparedStatementCanBeDownloaded(): void {
		$rows = self::rows('draft');
		$rows['IcpOpgaaf'][0]['period'] = '2026-08';
		$rows['Administration'] = [];

		$xml = $this->filer(rows: $rows, digipoort: new IntegriqSbrHandoffAdapter())->instanceXml(administrationId: 'adm-1', period: '2026-08');

		$this->assertStringContainsString('<gl-cor:periodIdentifier>2026-08</gl-cor:periodIdentifier>', $xml);
		$this->assertStringContainsString('<bd-i:filerVatId></bd-i:filerVatId>', $xml);
		$this->assertStringContainsString('<bd-i:amount>12000.00</bd-i:amount>', $xml);
	}//end testTheInstanceOfAPreparedStatementCanBeDownloaded()
}//end class
