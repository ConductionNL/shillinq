<?php

/**
 * Unit tests for CbcrFileController.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CbcrFileController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrFileService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generate XML is for members of the group's administration; refusals answer 422.
 */
#[CoversClass(CbcrFileController::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcrFileControllerTest extends TestCase {

	/**
	 * The calls the file service received: [returnId, administrationId].
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $calls = [];

	/**
	 * A controller whose file service does what the callback does.
	 *
	 * @param callable(array<string,mixed>,string):array<string,mixed> $files The file service's answer.
	 *
	 * @return CbcrFileController
	 */
	private function controller(callable $files): CbcrFileController {
		$store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					['id' => 'ge-holding', 'administrationId' => 'adm-holding'],
					['id' => 'ge-productie', 'administrationId' => 'adm-productie', 'ultimateParentEntity' => 'ge-holding'],
					['id' => 'ge-other', 'administrationId' => 'adm-other'],
				],
				'CbcrReturn' => [
					['id' => 'cbc-2026', 'reportingEntity' => 'ge-productie', 'period' => 'FY2026'],
					['id' => 'cbc-other', 'reportingEntity' => 'ge-other', 'period' => 'FY2026'],
					['id' => 'cbc-orphan', 'period' => 'FY2026'],
				],
			]
		);
		$service = $this->createMock(CbcrFileService::class);
		$service->method('generateCbc')->willReturnCallback(
			function (array $return, string $administrationId) use ($files): array {
				$this->calls[] = [(string)$return['id'], $administrationId];
				return $files($return, $administrationId);
			}
		);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-holding');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CbcrFileController(
			$this->createMock(IRequest::class),
			$service,
			$store,
			$context,
			$appConfig,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A member of the ultimate parent's administration gets the file, filed under that administration.
	 *
	 * @return void
	 */
	public function testAMemberOfTheGroupGetsTheFile(): void {
		$response = $this->controller(static fn (array $return): array => ['report' => ['id' => 'report-7'], 'return' => $return])->generateCbc('cbc-2026');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('report-7', $response->getData()['report']['id']);
		self::assertSame([['cbc-2026', 'adm-holding']], $this->calls);
	}//end testAMemberOfTheGroupGetsTheFile()

	/**
	 * Another group's report, an unknown one, one without an entity or a malformed id reads as not found.
	 *
	 * @return void
	 */
	public function testOtherReportsReadAsNotFound(): void {
		$controller = $this->controller(static fn (): array => []);
		foreach (['cbc-other', 'cbc-missing', 'cbc-orphan', '../etc'] as $returnId) {
			self::assertSame(Http::STATUS_NOT_FOUND, $controller->generateCbc($returnId)->getStatus(), $returnId);
		}

		self::assertSame([], $this->calls);
	}//end testOtherReportsReadAsNotFound()

	/**
	 * A refusal answers 422 with its reason; an unexpected failure answers 500 without details.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		$refused = $this->controller(
			static fn (): array => throw new RuntimeException('The country-by-country file is not made while a blocking check fails. cbc-entity-tin')
		)->generateCbc('cbc-2026');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertStringContainsString('cbc-entity-tin', $refused->getData()['error']);

		$failed = $this->controller(static fn (): array => throw new \LogicException('database gone'))->generateCbc('cbc-2026');
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		self::assertSame('The action could not be completed.', $failed->getData()['error']);
	}//end testRefusalsAndFailures()
}//end class
