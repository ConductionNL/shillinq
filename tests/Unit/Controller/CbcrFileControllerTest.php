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

use LogicException;
use OCA\Shillinq\Controller\CbcrFileController;
use OCA\Shillinq\Service\Cbcr\CbcrFileService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generate XML answers 201 with the file, 404 for a report the caller may not see, 422 on a refusal.
 */
#[CoversClass(CbcrFileController::class)]
final class CbcrFileControllerTest extends TestCase {

	/**
	 * A controller whose file service does what the callback does.
	 *
	 * @param callable(string):(array<string,mixed>|null) $files The file service's answer.
	 *
	 * @return CbcrFileController
	 */
	private function controller(callable $files): CbcrFileController {
		$service = $this->createMock(CbcrFileService::class);
		$service->method('generateCbc')->willReturnCallback($files);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CbcrFileController($this->createMock(IRequest::class), $service, $this->createMock(LoggerInterface::class), $l10n);
	}//end controller()

	/**
	 * A stored file answers 201 with the report and the return.
	 *
	 * @return void
	 */
	public function testAStoredFileAnswersCreated(): void {
		$asked    = [];
		$response = $this->controller(
			static function (string $returnId) use (&$asked): array {
				$asked[] = $returnId;
				return ['report' => ['id' => 'report-7'], 'return' => ['id' => $returnId]];
			}
		)->generateCbc('cbc-2026');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('report-7', $response->getData()['report']['id']);
		self::assertSame(['cbc-2026'], $asked);
	}//end testAStoredFileAnswersCreated()

	/**
	 * A report the caller may not see answers 404.
	 *
	 * @return void
	 */
	public function testAReportTheCallerMayNotSeeIsNotFound(): void {
		$response = $this->controller(static fn (): ?array => null)->generateCbc('cbc-other');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAReportTheCallerMayNotSeeIsNotFound()

	/**
	 * A refusal answers 422 with its reason; an unexpected failure answers 500 without details.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		$reason  = 'The country-by-country file is not made while a blocking check fails. cbc-entity-tin';
		$refused = $this->controller(static fn (): array => throw new RuntimeException($reason))->generateCbc('cbc-2026');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame($reason, $refused->getData()['error']);

		$failed = $this->controller(static fn (): array => throw new LogicException('database gone'))->generateCbc('cbc-2026');
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		self::assertSame('The action could not be completed.', $failed->getData()['error']);
	}//end testRefusalsAndFailures()
}//end class
