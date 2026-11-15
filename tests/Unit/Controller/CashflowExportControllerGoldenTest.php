<?php

/**
 * Golden test for CashflowExportController::exportPdf.
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CashflowExportController;
use OCA\Shillinq\Service\CashflowExportService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer of the cashflow PDF export and the calls behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CashflowExportControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Export the forecast.
	 *
	 * @param bool  $loggedIn Whether a user is logged in.
	 * @param mixed $bundle   What the service answers; 'throw' makes it throw.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 */
	private function export(bool $loggedIn, mixed $bundle=null): DataDownloadResponse|JSONResponse {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($loggedIn): ?IUser {
				$this->calls[] = ['getUser'];
				if ($loggedIn === true) {
					return $this->createMock(IUser::class);
				}

				return null;
			}
		);
		$service = $this->createMock(CashflowExportService::class);
		$service->method('buildHorizonExport')->willReturnCallback(
			function () use ($bundle): ?array {
				$this->calls[] = ['buildHorizonExport'];
				if ($bundle === 'throw') {
					throw new RuntimeException('pdf failed');
				}

				return $bundle;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return (new CashflowExportController($this->createMock(IRequest::class), $service, $session, $logger))->exportPdf();
	}//end export()

	/**
	 * No session is a 401 before the service; no horizon a 404; a failure a logged 500.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$response = $this->export(loggedIn: false);
		self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: [$response->getStatus(), $response->getData()]);
		$response = $this->export(loggedIn: true);
		self::assertSame(expected: [404, ['error' => 'no_cashflow_horizon']], actual: [$response->getStatus(), $response->getData()]);
		$response = $this->export(loggedIn: true, bundle: 'throw');
		self::assertSame(expected: [500, ['error' => 'export_failed']], actual: [$response->getStatus(), $response->getData()]);
		$expected = [
			['getUser'],
			['getUser'],
			['buildHorizonExport'],
			['getUser'],
			['buildHorizonExport'],
			['error', 'CashflowExportController: cashflow PDF export failed', ['exception' => 'pdf failed']],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * A horizon downloads under the service's file name and type.
	 *
	 * @return void
	 */
	public function testDownload(): void {
		$export   = ['payload' => '%PDF', 'filename' => 'cashflow.pdf', 'mimeType' => 'application/pdf', 'extra' => 1];
		$response = $this->export(loggedIn: true, bundle: $export);
		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: '%PDF', actual: $response->render());
		$headers = $response->getHeaders();
		self::assertSame(expected: 'application/pdf', actual: $headers['Content-Type']);
		self::assertSame(expected: 'attachment; filename=cashflow.pdf', actual: $headers['Content-Disposition']);
	}//end testDownload()
}//end class
