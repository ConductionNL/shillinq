<?php

/**
 * Golden tests for QuickDraftInvoiceController::create: the membership check, the defaulted scope, the draft call and answers.
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
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\QuickDraftInvoiceController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\QuickDraftInvoiceService;
use OCA\Shillinq\Service\Sales\QuickDraftRefusedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of the create action, allowed and refused, and every call behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class QuickDraftInvoiceControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call create once.
	 *
	 * @param array<string,mixed> $setup params, member (bool), context, fail (Throwable).
	 *
	 * @return JSONResponse
	 */
	private function create(array $setup=[]): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				return ($setup['params'][$key] ?? $default);
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$context->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ($setup['context'] ?? ['activeAdministrationId' => 'active-1']);
			}
		);
		$service = $this->createMock(QuickDraftInvoiceService::class);
		$service->method('draft')->willReturnCallback(
			function (string $administrationId, array $request) use ($setup): array {
				$this->calls[] = ['draft', $administrationId, $request];
				if (isset($setup['fail']) === true) {
					throw $setup['fail'];
				}

				return ['id' => 'inv-1'];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['error', $message];
			}
		);

		return (new QuickDraftInvoiceController($request, $service, $context, $l10n, $logger))->create();
	}//end create()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * A named administration the caller is not a member of is a 403 before anything else is read.
	 *
	 * @return void
	 */
	public function testForeignAdministrationRefused(): void {
		$response = $this->create(setup: ['params' => ['administrationId' => ' a2 '], 'member' => false]);
		self::assertSame(expected: [403, ['message' => 'T:You cannot invoice in this administration.']], actual: self::answer($response));
		self::assertSame(expected: [['getParam', 'administrationId'], ['canAccess', 'a2']], actual: $this->calls);
	}//end testForeignAdministrationRefused()

	/**
	 * A member's draft gets the six fields in order; without an administration the active one is used, unchecked.
	 *
	 * @return void
	 */
	public function testDrafts(): void {
		$params = ['administrationId' => 'a1', 'customerId' => 'c1', 'lines' => [['q' => 1]], 'reference' => 'R'];
		self::assertSame(expected: [201, ['id' => 'inv-1']], actual: self::answer($this->create(setup: ['params' => $params])));
		$fields = [
			'customerId' => 'c1',
			'invoiceDate' => null,
			'dueDate' => null,
			'reference' => 'R',
			'glAccount' => null,
			'lines' => [['q' => 1]],
		];
		self::assertSame(
			expected: [
				['getParam', 'administrationId'],
				['canAccess', 'a1'],
				['getParam', 'customerId'],
				['getParam', 'invoiceDate'],
				['getParam', 'dueDate'],
				['getParam', 'reference'],
				['getParam', 'glAccount'],
				['getParam', 'lines'],
				['draft', 'a1', $fields],
			],
			actual: $this->calls
		);
		$this->calls = [];
		$this->create(setup: ['member' => false]);
		self::assertSame(expected: [['getParam', 'administrationId'], ['buildContext']], actual: array_slice($this->calls, 0, 2));
		self::assertSame(expected: 'active-1', actual: end($this->calls)[1]);
		$this->create(setup: ['context' => []]);
		self::assertSame(expected: '', actual: end($this->calls)[1]);
	}//end testDrafts()

	/**
	 * A refused draft is a translated 422; anything else is logged and a 500.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$refused = new QuickDraftRefusedException('Customer is unknown');
		self::assertSame(expected: [422, ['message' => 'T:Customer is unknown']], actual: self::answer($this->create(setup: ['fail' => $refused])));
		$this->calls = [];
		$actual = self::answer($this->create(setup: ['fail' => new \RuntimeException('db')]));
		self::assertSame(expected: [500, ['message' => 'T:Failed to create draft invoice.']], actual: $actual);
		self::assertSame(expected: ['error', 'QuickDraftInvoiceController.create failed: db'], actual: end($this->calls));
	}//end testFailures()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
