<?php

/**
 * Unit tests for EnsiaCycleController.
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
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\EnsiaCycleController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\ENSIABevindingGenerator;
use OCA\Shillinq\Service\ENSIAVerklaringGenerator;
use OCA\Shillinq\Service\ENSIAXmlExporter;
use OCA\Shillinq\Service\PublicSector\EnsiaCycleSteps;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-ENSIA-012: the submission XML and the statement download, each refused
 * outside the caller's administrations.
 *
 * @covers \OCA\Shillinq\Controller\EnsiaCycleController
 * @uses   \OCA\Shillinq\Service\PublicSector\EnsiaCycleSteps
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class EnsiaCycleControllerTest extends TestCase {

	/**
	 * The administrations canAccess() was asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The cycles the controller reads.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function records(): array {
		return [
			'ENSIAJaarcyclus' => [
				[
					'id' => 'c-sub',
					'administrationId' => 'adm-1',
					'year' => 2026,
					'status' => 'submitted',
					'submissionXml' => '<ensiaZelfevaluatie/>',
					'declarationDocx' => base64_encode('PK-docx'),
				],
				['id' => 'c-open', 'administrationId' => 'adm-1', 'year' => 2027, 'status' => 'peer-review'],
				['id' => 'c-other', 'administrationId' => 'adm-2', 'year' => 2026, 'status' => 'submitted', 'submissionXml' => '<x/>'],
			],
		];
	}//end records()

	/**
	 * The controller over the cycles, with or without a signed-in user.
	 *
	 * @param bool $user Whether a user is signed in.
	 *
	 * @return EnsiaCycleController
	 */
	private function controller(bool $user=true): EnsiaCycleController {
		$store = new InMemoryObjectServiceStub(data: self::records(), findAllRendersEntities: true);
		$location = $this->createConfiguredMock(originalClassName: RegisterLocation::class, configuration: ['getRegisterSlug' => 'shillinq']);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->asked[] = $administrationId;
				return $administrationId === 'adm-1';
			}
		);

		return new EnsiaCycleController(
			request: $this->createMock(IRequest::class),
			steps: new EnsiaCycleSteps(
				records: $this->createMock(PublicSectorRecords::class),
				findings: new ENSIABevindingGenerator(),
				statement: new ENSIAVerklaringGenerator(),
				exporter: new ENSIAXmlExporter()
			),
			administrations: $context,
			objectService: $store,
			registerLocation: $location,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A submitted cycle of the caller's administration downloads its XML.
	 *
	 * @return void
	 */
	public function testASubmittedCycleDownloadsItsXml(): void {
		$response = $this->controller()->xml(id: 'c-sub');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('<ensiaZelfevaluatie/>', $response->render());
		$this->assertSame('text/xml', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('ensia-adm-1-2026.xml', $response->getHeaders()['Content-Disposition']);
		$this->assertSame(['adm-1'], $this->asked);
	}//end testASubmittedCycleDownloadsItsXml()

	/**
	 * The statement downloads as a DOCX.
	 *
	 * @return void
	 */
	public function testTheStatementDownloads(): void {
		$response = $this->controller()->statement(id: 'c-sub');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('PK-docx', $response->render());
		$this->assertSame(EnsiaCycleSteps::DOCX_MIME, $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('college-verklaring-ensia-adm-1-2026.docx', $response->getHeaders()['Content-Disposition']);
	}//end testTheStatementDownloads()

	/**
	 * Another administration's cycle, and a missing one, answer the same 404.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsCycleIsMaskedAsNotFound(): void {
		$other = $this->controller()->xml(id: 'c-other');
		$missing = $this->controller()->statement(id: 'c-nope');

		$this->assertInstanceOf(JSONResponse::class, $other);
		$this->assertSame(Http::STATUS_NOT_FOUND, $other->getStatus());
		$this->assertSame(['error' => 'Not found'], $other->getData());
		$this->assertInstanceOf(JSONResponse::class, $missing);
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['adm-2'], $this->asked);
	}//end testAnotherAdministrationsCycleIsMaskedAsNotFound()

	/**
	 * A cycle without its files answers 409 for both.
	 *
	 * @return void
	 */
	public function testACycleWithoutItsFilesIsAConflict(): void {
		$xml = $this->controller()->xml(id: 'c-open');
		$statement = $this->controller()->statement(id: 'c-open');

		$this->assertInstanceOf(JSONResponse::class, $xml);
		$this->assertSame(Http::STATUS_CONFLICT, $xml->getStatus());
		$this->assertSame(['error' => 'not-generated'], $xml->getData());
		$this->assertInstanceOf(JSONResponse::class, $statement);
		$this->assertSame(Http::STATUS_CONFLICT, $statement->getStatus());
	}//end testACycleWithoutItsFilesIsAConflict()

	/**
	 * Without a user nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsRead(): void {
		$response = $this->controller(user: false)->xml(id: 'c-sub');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->asked);
	}//end testWithoutAUserNothingIsRead()
}//end class
