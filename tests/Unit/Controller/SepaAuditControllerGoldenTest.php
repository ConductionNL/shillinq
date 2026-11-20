<?php

/**
 * Golden test for SepaAuditController::exportMandate.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SepaAuditController;
use OCA\Shillinq\Service\SepaAuditService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer of the mandate export and the calls behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SepaAuditControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Export one mandate.
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
		$service = $this->createMock(SepaAuditService::class);
		$service->method('buildMandateDossier')->willReturnCallback(
			function (string $mandateId) use ($bundle): ?array {
				$this->calls[] = ['buildMandateDossier', $mandateId];
				if ($bundle === 'throw') {
					throw new RuntimeException('zip failed');
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

		return (new SepaAuditController($this->createMock(IRequest::class), $service, $session, $logger))->exportMandate('m1');
	}//end export()

	/**
	 * No session is a 401 before the service; an unreadable mandate a 404; a failure a logged 500.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$response = $this->export(loggedIn: false);
		self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: [$response->getStatus(), $response->getData()]);
		$response = $this->export(loggedIn: true);
		self::assertSame(expected: [404, ['error' => 'mandate_not_found']], actual: [$response->getStatus(), $response->getData()]);
		$response = $this->export(loggedIn: true, bundle: 'throw');
		self::assertSame(expected: [500, ['error' => 'export_failed']], actual: [$response->getStatus(), $response->getData()]);
		$expected = [
			['getUser'],
			['getUser'],
			['buildMandateDossier', 'm1'],
			['getUser'],
			['buildMandateDossier', 'm1'],
			['error', 'SepaAuditController: mandate export failed', ['exception' => 'zip failed']],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * A readable mandate downloads as a ZIP under the service's file name.
	 *
	 * @return void
	 */
	public function testDownload(): void {
		$response = $this->export(loggedIn: true, bundle: ['data' => 'PK..', 'filename' => 'mandate-m1.zip', 'extra' => 1]);
		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: 'PK..', actual: $response->render());
		$headers = $response->getHeaders();
		self::assertSame(expected: 'application/zip', actual: $headers['Content-Type']);
		self::assertSame(expected: 'attachment; filename=mandate-m1.zip', actual: $headers['Content-Disposition']);
	}//end testDownload()
}//end class
