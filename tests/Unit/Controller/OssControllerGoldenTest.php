<?php

/**
 * Golden tests for OssController: input checks, the membership check, service arguments, answers and log lines.
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
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\OssController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\OssRateResolver;
use OCA\Shillinq\Service\OssReturnGenerator;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins both actions, allowed and refused, and every call behind them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user (bool), params, destination (bool), resolved, member (bool), fail (Throwable).
	 *
	 * @return OssController
	 */
	private function controller(array $setup=[]): OssController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($setup['params'][$key] ?? $default);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$fail     = ($setup['fail'] ?? null);
		$resolver = $this->createMock(OssRateResolver::class);
		$resolver->method('isOssDestination')->willReturnCallback(
			function (string $countryCode) use ($setup): bool {
				$this->calls[] = ['isOssDestination', $countryCode];
				return ($setup['destination'] ?? true);
			}
		);
		$resolver->method('resolve')->willReturnCallback(
			function (string $countryCode, string $rateCategory, string $invoiceDate) use ($setup, $fail): ?array {
				$this->calls[] = ['resolve', $countryCode, $rateCategory, $invoiceDate];
				if ($fail !== null) {
					throw $fail;
				}

				return ($setup['resolved'] ?? null);
			}
		);
		$generator = $this->createMock(OssReturnGenerator::class);
		$generator->method('generateDraft')->willReturnCallback(
			function (string $administrationId, int $periodYear, string $periodQuarter, string $registrationId) use ($fail): array {
				$this->calls[] = ['generateDraft', $administrationId, $periodYear, $periodQuarter, $registrationId];
				if ($fail !== null) {
					throw $fail;
				}

				return ['draft' => true];
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new OssController($request, $resolver, $generator, $logger, $session, $context);
	}//end controller()

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
	 * Rate: 401, the three refusals in order, then the resolver call and its answers.
	 *
	 * @return void
	 */
	public function testResolveRate(): void {
		$actual = self::answer($this->controller(setup: ['user' => false])->resolveRate());
		self::assertSame(expected: [401, ['error' => 'Not logged in']], actual: $actual);
		self::assertSame(expected: [], actual: $this->calls);
		$badCountry = [400, ['error' => 'country must be an ISO 3166-1 alpha-2 code']];
		$actual = self::answer($this->controller(setup: ['params' => ['country' => 'deu', 'date' => 'x']])->resolveRate());
		self::assertSame(expected: $badCountry, actual: $actual);
		self::assertSame(
			expected: [['getParam', 'country', ''], ['getParam', 'category', 'standard'], ['getParam', 'date', '']],
			actual: $this->calls
		);
		$badDate = [400, ['error' => 'date must be YYYY-MM-DD']];
		$actual = self::answer($this->controller(setup: ['params' => ['country' => ' de ', 'date' => '2026-1-1'], 'destination' => false])->resolveRate());
		self::assertSame(expected: $badDate, actual: $actual);
		$this->calls = [];
		$notOss = [400, ['error' => 'country is not an OSS destination (EU, non-NL)']];
		$params = ['country' => ' de ', 'date' => ' 2026-01-15 ', 'category' => ' reduced '];
		$actual = self::answer($this->controller(setup: ['params' => $params, 'destination' => false])->resolveRate());
		self::assertSame(expected: $notOss, actual: $actual);
		self::assertSame(expected: ['isOssDestination', 'DE'], actual: end($this->calls));
		$missing = [404, ['error' => 'oss.rate.missing', 'hint' => 'Run the TEDB refresh job or contact support.']];
		self::assertSame(expected: $missing, actual: self::answer($this->controller(setup: ['params' => $params])->resolveRate()));
		self::assertSame(expected: ['resolve', 'DE', 'reduced', '2026-01-15'], actual: end($this->calls));
		$actual = self::answer($this->controller(setup: ['params' => $params, 'resolved' => ['rate' => 7]])->resolveRate());
		self::assertSame(expected: [200, ['rate' => 7]], actual: $actual);
		$actual = self::answer($this->controller(setup: ['params' => $params, 'fail' => new \RuntimeException('tedb')])->resolveRate());
		self::assertSame(expected: [500, ['error' => 'Failed to resolve VAT rate']], actual: $actual);
		self::assertSame(expected: ['error', 'OssController: rate resolution failed', ['exception' => 'tedb']], actual: end($this->calls));
	}//end testResolveRate()

	/**
	 * Return: 401, the four input refusals in order, then the masked 404, then the draft.
	 *
	 * @return void
	 */
	public function testGenerateReturn(): void {
		self::assertSame(expected: 401, actual: $this->controller(setup: ['user' => false])->generateReturn()->getStatus());
		$valid = ['administration_id' => ' adm ', 'period_year' => '2026', 'period_quarter' => ' Q2 ', 'registration_id' => ' r1 '];
		$cases = [
			[['administration_id' => 'a b'] + $valid, 'administration_id is required'],
			[['period_year' => '1999'] + $valid, 'period_year must be a valid year'],
			[['period_year' => 2201] + $valid, 'period_year must be a valid year'],
			[['period_quarter' => 'q2'] + $valid, 'period_quarter must be one of Q1..Q4'],
			[['registration_id' => ''] + $valid, 'registration_id is required'],
		];
		foreach ($cases as [$params, $message]) {
			$actual = self::answer($this->controller(setup: ['params' => $params, 'member' => false])->generateReturn());
			self::assertSame(expected: [400, ['error' => $message]], actual: $actual);
		}

		self::assertSame(expected: [], actual: array_values(array_filter($this->calls, fn (array $c): bool => $c[0] !== 'getParam')));
		$this->calls = [];
		$actual = self::answer($this->controller(setup: ['params' => $valid, 'member' => false])->generateReturn());
		self::assertSame(expected: [404, ['error' => 'Administration not found']], actual: $actual);
		self::assertSame(
			expected: [
				['getParam', 'administration_id', ''],
				['getParam', 'period_year', 0],
				['getParam', 'period_quarter', ''],
				['getParam', 'registration_id', ''],
				['canAccess', 'adm'],
			],
			actual: $this->calls
		);
		self::assertSame(expected: [200, ['draft' => true]], actual: self::answer($this->controller(setup: ['params' => $valid])->generateReturn()));
		self::assertSame(expected: ['generateDraft', 'adm', 2026, 'Q2', 'r1'], actual: end($this->calls));
		$actual = self::answer($this->controller(setup: ['params' => $valid, 'fail' => new \LogicException('gen')])->generateReturn());
		self::assertSame(expected: [500, ['error' => 'Failed to generate OSS return']], actual: $actual);
		self::assertSame(expected: ['error', 'OssController: return generation failed', ['exception' => 'gen']], actual: end($this->calls));
	}//end testGenerateReturn()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
