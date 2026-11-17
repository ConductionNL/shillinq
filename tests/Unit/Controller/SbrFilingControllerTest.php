<?php

/**
 * Unit tests for SbrFilingController: downloading a filing's XBRL.
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
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-4.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SbrFilingController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SbrFilingController::class)]
final class SbrFilingControllerTest extends TestCase {

	private function controller(?array $instance, bool $member = true, bool $signedIn = true, bool $readFails = false): SbrFilingController {
		$records = $this->createMock(SbrRecords::class);
		$find = $records->method('find')->with('XbrlInstance', 'xbrl-1');
		$readFails ? $find->willThrowException(new RuntimeException('db')) : $find->willReturn($instance);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $member && $id === 'adm-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $this->createMock(IUser::class) : null);

		return new SbrFilingController($this->createMock(IRequest::class), $records, $context, $session);
	}

	public function testAMemberDownloadsTheFilingsXbrl(): void {
		$response = $this->controller(['administrationId' => 'adm-1', 'instanceNumber' => 'OB-BTW 2026/Q3', 'instanceXml' => '<xbrli:xbrl/>'])->download('xbrl-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('<xbrli:xbrl/>', $response->render());
		$this->assertSame('application/xml', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('OB-BTW-2026-Q3.xbrl', $response->getHeaders()['Content-Disposition']);
	}

	public function testAFilingOfAnotherAdministrationReadsAsNotFound(): void {
		$response = $this->controller(['administrationId' => 'adm-2', 'instanceXml' => '<x/>'])->download('xbrl-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAMissingFilingOrOneWithoutADocumentReadsAsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(null)->download('xbrl-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['administrationId' => 'adm-1', 'instanceXml' => ' '])->download('xbrl-1')->getStatus());
	}

	public function testWithoutASignedInUserItIsUnauthorized(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(['administrationId' => 'adm-1', 'instanceXml' => '<x/>'], true, false)->download('xbrl-1')->getStatus());
	}

	public function testAFailedReadIsAServerError(): void {
		$response = $this->controller(null, true, true, true)->download('xbrl-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The filing could not be read.'], $response->getData());
	}
}
