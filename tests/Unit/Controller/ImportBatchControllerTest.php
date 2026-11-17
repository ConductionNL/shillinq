<?php

/**
 * Tests for ImportBatchController.
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
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\ImportBatchController;
use OCA\Shillinq\Service\Import\ImportBatchNotFoundException;
use OCA\Shillinq\Service\Import\SuggestedMappingConfirmation;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The confirm-suggestions endpoint maps the service outcome to a status.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class ImportBatchControllerTest extends TestCase {

	/**
	 * Confirmed count, not found, wrong state.
	 *
	 * @return void
	 */
	public function testStatuses(): void {
		self::assertSame(['confirmed' => 3], $this->controller(result: 3)->confirmSuggestions('b-1')->getData());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(result: new ImportBatchNotFoundException('x'))->confirmSuggestions('b-1')->getStatus());
		self::assertSame(Http::STATUS_CONFLICT, $this->controller(result: new DomainException('x'))->confirmSuggestions('b-1')->getStatus());

	}//end testStatuses()

	/**
	 * Build the controller.
	 *
	 * @param int|\Throwable $result What the service answers.
	 *
	 * @return ImportBatchController
	 */
	private function controller(int|\Throwable $result): ImportBatchController {
		$service = $this->createMock(SuggestedMappingConfirmation::class);
		if ($result instanceof \Throwable) {
			$service->method('confirm')->willThrowException($result);
		} else {
			$service->method('confirm')->willReturn($result);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ImportBatchController(
			request: $this->createMock(IRequest::class),
			confirmation: $service,
			userSession: $session,
			l10n: $l10n,
			logger: new NullLogger(),
		);

	}//end controller()
}//end class
