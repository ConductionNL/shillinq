<?php

/**
 * Golden tests for BadoControleprotocolController: answers, checks and log lines.
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BadoControleprotocolController;
use OCA\Shillinq\Service\AccountantsdossierExportService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of both actions, the checks made before the services and
 * the log lines, before the shared prelude is extracted.
 */
final class BadoControleprotocolGoldenTest extends TestCase {
	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param mixed $protocolId The protocol_id param (null = absent).
	 * @param array<string, mixed> $outcomes organisation (string|null|Throwable), access (bool), aggregation and export (array|Throwable), user (bool).
	 *
	 * @return BadoControleprotocolController
	 */
	private function controller(mixed $protocolId, array $outcomes = []): BadoControleprotocolController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'protocol_id' && $protocolId !== null ? $protocolId : $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($outcomes['user'] ?? true) === true ? $this->createMock(IUser::class) : null);

		$answer = function (string $call, mixed $outcome): mixed {
			$this->calls[] = $call;
			if ($outcome instanceof \Throwable) {
				throw $outcome;
			}

			return $outcome;
		};
		$service = $this->createMock(BadoControleprotocolService::class);
		$service->method('organisationIdFor')->willReturnCallback(
			fn (string $id): ?string => $answer('organisation:' . $id, array_key_exists('organisation', $outcomes) ? $outcomes['organisation'] : 'adm-1')
		);
		$service->method('computeAggregation')->willReturnCallback(
			fn (string $id): array => $answer('aggregate:' . $id, ($outcomes['aggregation'] ?? ['total' => 1]))
		);
		$exporter = $this->createMock(AccountantsdossierExportService::class);
		$exporter->method('exportDossier')->willReturnCallback(
			fn (string $id): array => $answer('export:' . $id, ($outcomes['export'] ?? ['files' => []]))
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			fn (string $administrationId): bool => $answer('canAccess:' . $administrationId, ($outcomes['access'] ?? true))
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		return new BadoControleprotocolController(
			request: $request,
			service: $service,
			logger: $logger,
			userSession: $session,
			exporter: $exporter,
			context: $context,
		);
	}//end controller()

	/**
	 * Both actions' answers for one setup.
	 *
	 * @param mixed                $protocolId The protocol_id param.
	 * @param array<string, mixed> $outcomes   The outcomes.
	 *
	 * @return array<int, array{0: int, 1: mixed}>
	 */
	private function both(mixed $protocolId, array $outcomes = []): array {
		$aggregation = $this->controller(protocolId: $protocolId, outcomes: $outcomes)->aggregation();
		$export = $this->controller(protocolId: $protocolId, outcomes: $outcomes)->exportAccountantsdossier();
		return [[$aggregation->getStatus(), $aggregation->getData()], [$export->getStatus(), $export->getData()]];
	}//end both()

	/**
	 * The refusals before any service call, the same for both actions.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$twice = static fn (int $status, string $error): array => [[$status, ['error' => $error]], [$status, ['error' => $error]]];

		$this->assertSame($twice(401, 'Not logged in'), $this->both(protocolId: 'p-1', outcomes: ['user' => false]));
		$this->assertSame($twice(400, 'protocol_id is required'), $this->both(protocolId: ' '));
		$this->assertSame($twice(400, 'protocol_id must be a valid protocol identifier'), $this->both(protocolId: 'a/b'));
		$this->assertSame([], $this->calls);

		$this->assertSame($twice(404, 'Controleprotocol not found'), $this->both(protocolId: 'p-1', outcomes: ['organisation' => new \RuntimeException('db')]));
		$this->assertSame($twice(404, 'Controleprotocol not found'), $this->both(protocolId: 'p-1', outcomes: ['organisation' => null]));
		$this->assertSame($twice(404, 'Controleprotocol not found'), $this->both(protocolId: ' p-1 ', outcomes: ['access' => false]));
		$tenantFailed = 'error:BadoControleprotocolController: failed to resolve protocol tenant{"protocolId":"p-1","exception":"db"}';
		$this->assertSame(
			[
				'organisation:p-1', $tenantFailed, 'organisation:p-1', $tenantFailed,
				'organisation:p-1', 'organisation:p-1',
				'organisation:p-1', 'canAccess:adm-1', 'organisation:p-1', 'canAccess:adm-1',
			],
			$this->calls
		);
	}//end testRefusals()

	/**
	 * The service answers and failures of both actions.
	 *
	 * @return void
	 */
	public function testOutcomes(): void {
		$this->assertSame([[200, ['total' => 1]], [200, ['files' => []]]], $this->both(protocolId: 'p-1'));

		$this->calls = [];
		$failed = $this->both(
			protocolId: 'p-1',
			outcomes: ['aggregation' => new \LogicException('agg'), 'export' => new \RuntimeException('no dossier')]
		);
		$this->assertSame(
			[[500, ['error' => 'Failed to compute audit aggregation']], [404, ['error' => 'Accountantsdossier not available for this protocol']]],
			$failed
		);
		$crashed = $this->controller(protocolId: 'p-1', outcomes: ['export' => new \LogicException('io')])->exportAccountantsdossier();
		$this->assertSame([500, ['error' => 'Failed to export accountantsdossier']], [$crashed->getStatus(), $crashed->getData()]);

		$this->assertSame(
			[
				'organisation:p-1', 'canAccess:adm-1', 'aggregate:p-1',
				'error:BadoControleprotocolController: failed to compute audit aggregation{"protocolId":"p-1","exception":"agg"}',
				'organisation:p-1', 'canAccess:adm-1', 'export:p-1',
				'info:BadoControleprotocolController: accountantsdossier export rejected{"protocolId":"p-1","reason":"no dossier"}',
				'organisation:p-1', 'canAccess:adm-1', 'export:p-1',
				'error:BadoControleprotocolController: failed to export accountantsdossier{"protocolId":"p-1","exception":"io"}',
			],
			$this->calls
		);
	}//end testOutcomes()
}//end class
