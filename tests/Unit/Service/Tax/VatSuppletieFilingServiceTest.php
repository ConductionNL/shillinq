<?php

/**
 * Unit tests for VatSuppletieFilingService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult;
use OCA\Shillinq\Service\Tax\SuppletieFilingUnavailableException;
use OCA\Shillinq\Service\Tax\VatSuppletieFilingService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The supplementary return of a prepared correction: its document, its download state and its filing.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(VatSuppletieFilingService::class)]
#[CoversClass(SuppletieFilingUnavailableException::class)]
#[UsesClass(DigipoortSubmissionResult::class)]
final class VatSuppletieFilingServiceTest extends TestCase {

	/**
	 * Every write the store received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The Digipoort port.
	 *
	 * @var DigipoortSbrAdapterInterface&MockObject
	 */
	private DigipoortSbrAdapterInterface&MockObject $digipoort;

	/**
	 * The service over an empty store.
	 *
	 * @param bool $dormant Whether only the log adapter is bound.
	 *
	 * @return VatSuppletieFilingService
	 */
	private function service(bool $dormant): VatSuppletieFilingService {
		$this->saved = [];
		$this->digipoort = $this->createMock(DigipoortSbrAdapterInterface::class);
		$this->digipoort->method('isDormant')->willReturn($dormant);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new VatSuppletieFilingService(
			new InMemoryObjectServiceStub(['VatCorrection' => []], $this->saved),
			$this->digipoort,
			$appConfig,
			$this->createStub(LoggerInterface::class)
		);
	}//end service()

	/**
	 * The Q2 2026 correction for the late June purchase invoice, prepared.
	 *
	 * @return array<string,mixed>
	 */
	private function prepared(): array {
		return [
			'id' => 'corr-q2',
			'administrationId' => 'adm-1',
			'originalVatReturnId' => 'q2-2026',
			'periodType' => 'quarter',
			'periodYear' => 2026,
			'periodQuarter' => 2,
			'currency' => 'EUR',
			'state' => 'draft',
			'categoryDeltas' => [
				[
					'returnBox' => '5b',
					'type' => 'input',
					'taxRate' => 21.0,
					'deltaTaxableAmount' => 8571.43,
					'deltaVATAmount' => 1800.0,
					'glAccountNumber' => '1520',
				],
			],
			'correctionAmount' => -1800.0,
			'thresholdExceeded' => true,
			'filingDeadline' => '2026-12-28',
			'preparedAt' => '2026-11-02T10:00:00+00:00',
		];
	}//end prepared()

	/**
	 * The document carries the return it corrects, the per-box differences and the threshold decision.
	 *
	 * @return void
	 */
	public function testTheDocumentCarriesThePerBoxDifferences(): void {
		$document = $this->service(dormant: true)->document(correction: $this->prepared());

		$this->assertSame('btw-suppletie', $document['filingType']);
		$this->assertSame('q2-2026', $document['originalReturnId']);
		$this->assertSame(2, $document['periodQuarter']);
		$this->assertSame(
			[['returnBox' => '5b', 'type' => 'input', 'taxRate' => 21.0, 'deltaTaxableAmount' => 8571.43, 'deltaVATAmount' => 1800.0]],
			$document['boxes']
		);
		$this->assertSame(-1800.0, $document['netCorrection']);
		$this->assertTrue($document['thresholdExceeded']);
		$this->assertSame('2026-12-28', $document['filingDeadline']);
	}//end testTheDocumentCarriesThePerBoxDifferences()

	/**
	 * A correction not prepared yet has no supplementary return.
	 *
	 * @return void
	 */
	public function testAnUnpreparedCorrectionHasNoDocument(): void {
		$correction = array_merge($this->prepared(), ['preparedAt' => null]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Prepare the supplementary return before downloading or filing it.');

		$this->service(dormant: true)->document(correction: $correction);
	}//end testAnUnpreparedCorrectionHasNoDocument()

	/**
	 * While Digipoort is not connected, filing is refused, nothing is sent and nothing is saved.
	 *
	 * @return void
	 */
	public function testWithoutAConnectionFilingIsRefusedAndNothingIsSent(): void {
		$service = $this->service(dormant: true);
		$this->digipoort->expects($this->never())->method('submit');

		$this->assertFalse($service->canFile());
		try {
			$service->file(correction: $this->prepared());
			$this->fail('Filing without a connection was not refused.');
		} catch (SuppletieFilingUnavailableException $e) {
			$this->assertStringContainsString('Download the supplementary return', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testWithoutAConnectionFilingIsRefusedAndNothingIsSent()

	/**
	 * With a connection the document is handed over, and the correction is saved submitted with the reference.
	 *
	 * @return void
	 */
	public function testFilingHandsTheReturnOverAndSubmitsTheCorrection(): void {
		$service = $this->service(dormant: false);
		$sent = null;
		$this->digipoort->expects($this->once())->method('submit')->willReturnCallback(
			static function (array $payload) use (&$sent): DigipoortSubmissionResult {
				$sent = $payload;
				return new DigipoortSubmissionResult(deliveryStatus: 'DELIVERED', reference: 'dp-77', dormant: false);
			}
		);

		$filed = $service->file(correction: $this->prepared());

		$this->assertSame('btw-suppletie', $sent['filingType']);
		$this->assertSame('corr-q2', $sent['correlationId']);
		$this->assertSame('q2-2026', $sent['suppletie']['originalReturnId']);
		$this->assertSame(hash('sha256', $sent['xbrlInstanceBytes']), $sent['xbrlChecksum']);
		$this->assertSame('submitted', $filed['state']);
		$this->assertSame('dp-77', $filed['digipoortMessageId']);
		$this->assertNotEmpty($filed['submittedAt']);
		$this->assertCount(1, $this->saved);
	}//end testFilingHandsTheReturnOverAndSubmitsTheCorrection()

	/**
	 * A delivery Digipoort refused leaves the correction in draft.
	 *
	 * @return void
	 */
	public function testARefusedDeliveryLeavesTheCorrectionInDraft(): void {
		$service = $this->service(dormant: false);
		$this->digipoort->method('submit')->willReturn(new DigipoortSubmissionResult(deliveryStatus: 'FAILED', reference: '', dormant: false));

		try {
			$service->file(correction: $this->prepared());
			$this->fail('A refused delivery was reported as filed.');
		} catch (RuntimeException $e) {
			$this->assertSame('Digipoort did not accept the supplementary return (FAILED).', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testARefusedDeliveryLeavesTheCorrectionInDraft()

	/**
	 * A correction already submitted is not filed again.
	 *
	 * @return void
	 */
	public function testASubmittedCorrectionIsNotFiledAgain(): void {
		$service = $this->service(dormant: false);
		$this->digipoort->expects($this->never())->method('submit');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Only a supplementary return still in draft can be filed.');

		$service->file(correction: array_merge($this->prepared(), ['state' => 'submitted']));
	}//end testASubmittedCorrectionIsNotFiledAgain()
}//end class
