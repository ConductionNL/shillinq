<?php

/**
 * Golden test for FieldRequirementController::fields.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCA\Shillinq\Controller\FieldRequirementController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the fields answer, allowed and refused, and every lookup behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class FieldRequirementControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Ask for the fields of requirement q1.
	 *
	 * @param array<string,mixed> $setup user, requirement (array|null|'throw'), member.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function fields(array $setup=[]): array {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(
			function () use ($setup): ?string {
				$this->calls[] = ['currentUserId'];
				if (array_key_exists('user', $setup) === true) {
					return $setup['user'];
				}

				return 'u1';
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$requirement = ['id' => 'q1', 'administrationId' => 'a1'];
		if (array_key_exists('requirement', $setup) === true) {
			$requirement = $setup['requirement'];
		}

		$requirements = $this->createMock(FieldRequirements::class);
		$requirements->method('find')->willReturnCallback(
			function (string $id) use ($requirement): ?array {
				$this->calls[] = ['find', $id];
				if ($requirement === 'throw') {
					throw new RuntimeException('store down');
				}

				return $requirement;
			}
		);
		$requirements->method('formFields')->willReturnCallback(
			function (array $given): array {
				$this->calls[] = ['formFields', $given['id']];
				return [['field' => 'kvk']];
			}
		);

		$response = (new FieldRequirementController($this->createMock(IRequest::class), $context, $requirements))->fields('q1');
		return [$response->getStatus(), $response->getData()];
	}//end fields()

	/**
	 * No session is a 401; an unknown, unreadable or foreign requirement a 404; else its form fields.
	 *
	 * @return void
	 */
	public function testFields(): void {
		$notFound = [404, ['error' => 'Not found']];
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: $this->fields(setup: ['user' => null]));
		self::assertSame(expected: $notFound, actual: $this->fields(setup: ['requirement' => null]));
		self::assertSame(expected: $notFound, actual: $this->fields(setup: ['requirement' => 'throw']));
		self::assertSame(expected: $notFound, actual: $this->fields(setup: ['member' => false]));
		self::assertSame(expected: $notFound, actual: $this->fields(setup: ['requirement' => ['id' => 'q1'], 'member' => false]));
		self::assertSame(expected: [200, ['rows' => [['field' => 'kvk']]]], actual: $this->fields());
		$expected = [
			['currentUserId'],
			['currentUserId'],
			['find', 'q1'],
			['currentUserId'],
			['find', 'q1'],
			['currentUserId'],
			['find', 'q1'],
			['canAccess', 'a1'],
			['currentUserId'],
			['find', 'q1'],
			['canAccess', ''],
			['currentUserId'],
			['find', 'q1'],
			['canAccess', 'a1'],
			['formFields', 'q1'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testFields()
}//end class
