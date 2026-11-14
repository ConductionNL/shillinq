<?php

/**
 * Unit tests for IcpStatementController: prepare, download and file the
 * ICP statement of a period.
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
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use InvalidArgumentException;
use OCA\Shillinq\Controller\IcpStatementController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Icp\IcpStatementFiledException;
use OCA\Shillinq\Service\Icp\IcpStatementFiler;
use OCA\Shillinq\Service\Icp\IcpStatementPreparer;
use OCA\Shillinq\Service\Jurisdiction\JurisdictionRefusedException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ICP page's endpoints (REQ-ICR-002, REQ-ICR-003, REQ-ICP-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(IcpStatementController::class)]
#[UsesClass(IcpStatementFiledException::class)]
#[UsesClass(JurisdictionRefusedException::class)]
final class IcpStatementControllerTest extends TestCase {

	/**
	 * The preparer.
	 *
	 * @var IcpStatementPreparer&MockObject
	 */
	private IcpStatementPreparer&MockObject $preparer;

	/**
	 * The filer.
	 *
	 * @var IcpStatementFiler&MockObject
	 */
	private IcpStatementFiler&MockObject $filer;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->preparer = $this->createMock(IcpStatementPreparer::class);
		$this->filer = $this->createMock(IcpStatementFiler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller for a request, a member or not, logged in or not.
	 *
	 * @param array<string,string> $params   The request parameters.
	 * @param bool                 $member   Whether the caller belongs to the administration.
	 * @param bool                 $loggedIn Whether there is a user.
	 *
	 * @return IcpStatementController
	 */
	private function controller(array $params, bool $member=true, bool $loggedIn=true): IcpStatementController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);
		$administrations = $this->createMock(AdministrationContextServiceInterface::class);
		$administrations->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $member && $administrationId === 'adm-1'
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);

		return new IcpStatementController(
			request: $request,
			preparer: $this->preparer,
			filer: $this->filer,
			administrations: $administrations,
			userSession: $session,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Prepare answers what the preparer wrote, for the caller's administration and period.
	 *
	 * @return void
	 */
	public function testPrepareAnswersThePreparedStatement(): void {
		$prepared = ['statement' => ['period' => '2026-Q3'], 'reconciliation' => ['matches' => true], 'replaced' => false];
		$this->preparer->expects($this->once())->method('prepare')->with('adm-1', '2026-Q3')->willReturn($prepared);

		$response = $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-1'])->prepare();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($prepared, $response->getData());
	}//end testPrepareAnswersThePreparedStatement()

	/**
	 * The legacy parameter names are read too.
	 *
	 * @return void
	 */
	public function testThePeriodIdAndAdministrationIdSpellingsAreRead(): void {
		$this->preparer->expects($this->once())->method('prepare')->with('adm-1', '2026-08')->willReturn([]);

		$this->assertSame(200, $this->controller(['period_id' => '2026-08', 'administrationId' => 'adm-1'])->prepare()->getStatus());
	}//end testThePeriodIdAndAdministrationIdSpellingsAreRead()

	/**
	 * Not logged in, a missing or malformed parameter, or another administration is refused before preparing.
	 *
	 * @return void
	 */
	public function testRefusalsComeBeforeAnythingIsPrepared(): void {
		$this->preparer->expects($this->never())->method('prepare');
		$this->filer->expects($this->never())->method('file');
		$this->filer->expects($this->never())->method('instanceXml');

		$cases = [
			[401, ['error' => 'Authentication required'], $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-1'], true, false)],
			[400, ['error' => 'period is required'], $this->controller(['administration_id' => 'adm-1'])],
			[400, ['error' => 'administration_id must be a valid identifier'], $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm 1'])],
			[404, ['error' => 'Not found'], $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-2'])],
			[404, ['error' => 'Not found'], $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-1'], false)],
		];
		foreach ($cases as [$status, $body, $controller]) {
			foreach ([$controller->prepare(), $controller->file(), $controller->xml()] as $response) {
				$this->assertInstanceOf(JSONResponse::class, $response);
				$this->assertSame($status, $response->getStatus());
				$this->assertSame($body, $response->getData());
			}
		}
	}//end testRefusalsComeBeforeAnythingIsPrepared()

	/**
	 * A filed statement is a 409, a bad period a 400, another jurisdiction a 422, a failure a logged 500.
	 *
	 * @return void
	 */
	public function testPrepareMapsItsRefusals(): void {
		$this->preparer->method('prepare')->willReturnOnConsecutiveCalls(
			$this->throwException(new IcpStatementFiledException(period: '2026-Q3', status: 'submitted')),
			$this->throwException(new InvalidArgumentException('The period must be a quarter (2026-Q3) or a month (2026-07).')),
			$this->throwException(new JurisdictionRefusedException(output: 'Dutch ICP return', jurisdiction: 'BE', required: 'NL')),
			$this->throwException(new \RuntimeException('register gone')),
		);
		$this->logger->expects($this->once())->method('error')->with(
			'IcpStatementController: failed to prepare the ICP statement',
			['period' => '2026-Q3', 'administrationId' => 'adm-1', 'exception' => 'register gone']
		);
		$controller = $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-1']);

		$filed = $controller->prepare();
		$this->assertSame(409, $filed->getStatus());
		$this->assertSame('statement-filed', $filed->getData()['error']);
		$this->assertSame('The ICP statement of 2026-Q3 is submitted and is not replaced. File a correction instead.', $filed->getData()['message']);
		$this->assertSame(400, $controller->prepare()->getStatus());
		$refused = $controller->prepare();
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame(['jurisdiction-refused', 'BE'], [$refused->getData()['error'], $refused->getData()['jurisdiction']]);
		$failed = $controller->prepare();
		$this->assertSame([500, ['error' => 'Failed to prepare the ICP statement']], [$failed->getStatus(), $failed->getData()]);
	}//end testPrepareMapsItsRefusals()

	/**
	 * The XML downloads as a file; nothing prepared is a 404.
	 *
	 * @return void
	 */
	public function testTheXmlDownloadsAsAFile(): void {
		$this->filer->method('instanceXml')->willReturnOnConsecutiveCalls(
			'<xbrli:xbrl/>',
			$this->throwException(new DomainException('No ICP statement has been prepared for 2026-Q3. Prepare it on the ICP page first.')),
			$this->throwException(new \RuntimeException('boom')),
		);
		$controller = $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-1']);

		$download = $controller->xml();
		$this->assertInstanceOf(DataDownloadResponse::class, $download);
		$this->assertSame('<xbrli:xbrl/>', $download->render());
		$this->assertStringContainsString('icp-opgaaf-2026-Q3.xml', $download->getHeaders()['Content-Disposition']);
		$missing = $controller->xml();
		$this->assertSame([404, ['error' => 'No ICP statement has been prepared for 2026-Q3. Prepare it on the ICP page first.']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame(500, $controller->xml()->getStatus());
	}//end testTheXmlDownloadsAsAFile()

	/**
	 * Filing answers Digipoort's outcome; a refusal is a 409 naming why.
	 *
	 * @return void
	 */
	public function testFilingAnswersTheOutcomeOrWhyNot(): void {
		$this->filer->method('file')->willReturnOnConsecutiveCalls(
			['status' => 'submitted', 'deliveryStatus' => 'ACCEPTED', 'reference' => 'DGP-1'],
			$this->throwException(new DomainException('Only a finalised ICP statement can be filed; this one is draft.')),
			$this->throwException(new \RuntimeException('down')),
		);
		$controller = $this->controller(['period' => '2026-Q3', 'administration_id' => 'adm-1']);

		$filed = $controller->file();
		$this->assertSame([200, ['status' => 'submitted', 'deliveryStatus' => 'ACCEPTED', 'reference' => 'DGP-1']], [$filed->getStatus(), $filed->getData()]);
		$refused = $controller->file();
		$this->assertSame([409, ['error' => 'not-filed', 'message' => 'Only a finalised ICP statement can be filed; this one is draft.']], [$refused->getStatus(), $refused->getData()]);
		$failed = $controller->file();
		$this->assertSame([500, ['error' => 'Failed to file the ICP statement']], [$failed->getStatus(), $failed->getData()]);
	}//end testFilingAnswersTheOutcomeOrWhyNot()

	/**
	 * The page asks whether filing is available; not logged in is a 401.
	 *
	 * @return void
	 */
	public function testThePageAsksWhetherFilingIsAvailable(): void {
		$this->filer->method('isAvailable')->willReturn(false);

		$this->assertSame(['available' => false], $this->controller([])->filing()->getData());
		$this->assertSame(401, $this->controller([], true, false)->filing()->getStatus());
	}//end testThePageAsksWhetherFilingIsAvailable()
}//end class
