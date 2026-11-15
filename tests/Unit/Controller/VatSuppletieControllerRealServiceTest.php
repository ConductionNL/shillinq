<?php

/**
 * VatSuppletieController over the real detection service (REQ-VPS-002).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VatSuppletieController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCA\Shillinq\Tests\Unit\Service\Support\VatLedgerObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Detect and prepare through the controller, with the real VATReturnService
 * and VatSuppletieDetectionService over one in-memory ledger: a sale booked
 * into a filed quarter afterwards becomes a correction, and that correction
 * is a valid VatCorrection of the merged register.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Controller\VatSuppletieController
 * @uses   \OCA\Shillinq\Service\VATReturnService
 * @uses   \OCA\Shillinq\Service\VatSuppletieDetectionService
 */
final class VatSuppletieControllerRealServiceTest extends TestCase {

	/**
	 * The ledger the services read and write.
	 *
	 * @var VatLedgerObjectService
	 */
	private VatLedgerObjectService $store;

	/**
	 * Q1 2026 of administration adm-1: a filed return over one sale, and a
	 * second sale booked into March after it was filed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$line = static fn (string $tx, int $n, string $account, float $amount, string $kind): array => [
			'id' => $tx . '-' . $n, 'transactionId' => $tx, 'lineNumber' => $n, 'accountNumber' => $account, 'side' => 'credit',
			'amount' => $amount, 'currency' => 'EUR', 'periodId' => ($tx === 'gl-1' ? '2026-01' : '2026-03'), 'administrationId' => 'adm-1',
			'vatTariffCode' => 'high', 'vatReturnBox' => '1a', 'vatAmountKind' => $kind,
		];
		$transaction = static fn (string $id, string $date): array => [
			'id' => $id, 'transactionNumber' => strtoupper($id), 'postingDate' => $date, 'periodId' => substr($date, 0, 7),
			'currency' => 'EUR', 'description' => 'Verkoop', 'state' => 'posted', 'administrationId' => 'adm-1',
		];

		$this->store = new VatLedgerObjectService(
			[
				'GLTransaction' => [$transaction('gl-1', '2026-01-15'), $transaction('gl-2', '2026-03-01')],
				'GLLine' => [
					$line('gl-1', 1, '8000', 15000.0, 'base'),
					$line('gl-1', 2, '2110', 3150.0, 'vat'),
					$line('gl-2', 1, '8000', 500.0, 'base'),
					$line('gl-2', 2, '2110', 105.0, 'vat'),
				],
				'BtwAangifte' => [
					[
						'id' => 'vat-1', 'returnNumber' => 'NL-2026-Q1', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 1,
						'startDate' => '2026-01-01', 'endDate' => '2026-03-31', 'regime' => 'standard', 'administrationId' => 'adm-1',
						'statusCode' => 'submitted', 'totalVATCollected' => 3150.0, 'totalVATPaid' => 0.0, 'vatBalance' => -3150.0,
						'totalTaxableAmount' => 15000.0,
					],
					[
						'id' => 'vat-9', 'returnNumber' => 'NL-2026-Q1-B', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 1,
						'startDate' => '2026-01-01', 'endDate' => '2026-03-31', 'regime' => 'standard', 'administrationId' => 'adm-2',
						'statusCode' => 'submitted',
					],
				],
				'VATLine' => [
					[
						'id' => 'line-1', 'returnId' => 'vat-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0,
						'glAccountNumber' => '8000', 'taxableAmount' => 15000.0, 'vatAmount' => 0.0,
					],
					[
						'id' => 'line-2', 'returnId' => 'vat-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0,
						'glAccountNumber' => '2110', 'taxableAmount' => 0.0, 'vatAmount' => 3150.0,
					],
				],
				'VATDeclaration' => [
					[
						'id' => 'decl-1', 'returnId' => 'vat-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0,
						'totalVATAmount' => 3150.0, 'totalTaxableAmount' => 15000.0, 'lineCount' => 2,
					],
				],
			]
		);
	}//end setUp()

	/**
	 * The controller over the real services, for a signed-in user of adm-1.
	 *
	 * @return VatSuppletieController
	 */
	private function controller(): VatSuppletieController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$vatReturns = new VATReturnService(appConfig: $appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter($this->store));
		$suppletie = new VatSuppletieDetectionService(
			appConfig: $appConfig,
			logger: $logger,
			vatReturnService: $vatReturns,
			objectService: new DuckObjectServiceAdapter($this->store),
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-1');

		return new VatSuppletieController(
			request: $this->createMock(IRequest::class),
			suppletie: $suppletie,
			vatReturns: $vatReturns,
			session: $session,
			context: $context,
			logger: $logger,
		);
	}//end controller()

	/**
	 * A late sale is detected as a draft correction of the filed return, and
	 * the stored VatCorrection validates against the merged register.
	 *
	 * @return void
	 */
	public function testALateSaleIsDetectedAsAValidCorrection(): void {
		$response = $this->controller()->detectDrift('vat-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertTrue($response->getData()['drift']);
		$correction = $response->getData()['data'];
		$this->assertSame('vat-1', $correction['originalVatReturnId']);
		$this->assertSame('draft', $correction['state']);

		$stored = $this->store->dump('VatCorrection');
		$this->assertCount(1, $stored);
		$this->assertSame($correction['id'], $stored[0]['id']);
		$this->assertSame([], RegisterSchema::errors(slug: 'VatCorrection', object: $stored[0]));
	}//end testALateSaleIsDetectedAsAValidCorrection()

	/**
	 * Preparing the detected correction answers its box delta and keeps the
	 * prepared correction valid.
	 *
	 * @return void
	 */
	public function testTheDetectedCorrectionIsPrepared(): void {
		$controller = $this->controller();
		$correctionId = (string)$controller->detectDrift('vat-1')->getData()['data']['id'];

		$response = $controller->prepare($correctionId);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$prepared = $response->getData()['data'];
		$this->assertSame(105.0, $prepared['correctionAmount']);
		$this->assertSame('1a', $prepared['categoryDeltas'][0]['returnBox']);
		$this->assertSame('2110', $prepared['categoryDeltas'][0]['glAccountNumber']);
		$this->assertSame(105.0, $prepared['categoryDeltas'][0]['deltaVATAmount']);
		$this->assertSame([], RegisterSchema::errors(slug: 'VatCorrection', object: $this->store->dump('VatCorrection')[0]));
	}//end testTheDetectedCorrectionIsPrepared()

	/**
	 * A delta in a box the filed return carried no line for keeps an empty
	 * account, books on the clearing account, and still validates.
	 *
	 * @return void
	 */
	public function testADeltaWithoutAFiledLineStillValidates(): void {
		$this->store->setSchema('VATLine');
		foreach ($this->store->dump('VATLine') as $line) {
			$this->store->saveObject(['returnId' => 'none'] + $line);
		}

		$controller = $this->controller();
		$correctionId = (string)$controller->detectDrift('vat-1')->getData()['data']['id'];
		$prepared = $controller->prepare($correctionId)->getData()['data'];

		$this->assertSame('', $prepared['categoryDeltas'][0]['glAccountNumber']);
		$this->assertSame([], RegisterSchema::errors(slug: 'VatCorrection', object: $this->store->dump('VatCorrection')[0]));
	}//end testADeltaWithoutAFiledLineStillValidates()

	/**
	 * Another administration's return is not found, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsReturnIsNotFound(): void {
		$response = $this->controller()->detectDrift('vat-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->store->dump('VatCorrection'));
	}//end testAnotherAdministrationsReturnIsNotFound()

	/**
	 * A return whose ledger still matches what was filed has no drift.
	 *
	 * @return void
	 */
	public function testAnUnchangedReturnHasNoDrift(): void {
		$this->store->setSchema('GLTransaction');
		$this->store->saveObject(['id' => 'gl-2', 'state' => 'draft', 'administrationId' => 'adm-1', 'postingDate' => '2026-03-01']);

		$response = $this->controller()->detectDrift('vat-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['drift' => false, 'data' => null], $response->getData());
	}//end testAnUnchangedReturnHasNoDrift()
}//end class
