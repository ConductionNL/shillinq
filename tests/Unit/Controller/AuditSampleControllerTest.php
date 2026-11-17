<?php

/**
 * Unit tests for AuditSampleController.
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
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\AuditSampleController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BadoControleprotocolServiceInterface;
use OCA\Shillinq\Service\PublicSector\LedgerSampling;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-011: the draw action, refused outside the caller's administrations.
 *
 * @covers \OCA\Shillinq\Controller\AuditSampleController
 */
class AuditSampleControllerTest extends TestCase {

	/**
	 * The draws the sampling was asked for, as [protocolId, request].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $draws = [];

	/**
	 * The controller over a request; protocol prot-1 is of adm-1, prot-2 of
	 * adm-2, and the caller may act on adm-1 only.
	 *
	 * @param array<string, mixed> $params  The request parameters.
	 * @param bool                 $user    Whether a user is signed in.
	 * @param \Throwable|null      $failure What the sampling throws.
	 *
	 * @return AuditSampleController
	 */
	private function controller(array $params, bool $user=true, ?\Throwable $failure=null): AuditSampleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);
		$protocols = $this->createMock(BadoControleprotocolServiceInterface::class);
		$protocols->method('organisationIdFor')->willReturnCallback(
			static fn (string $protocolId): ?string => (['prot-1' => 'adm-1', 'prot-2' => 'adm-2'][$protocolId] ?? null)
		);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-1');
		$sampling = $this->createMock(LedgerSampling::class);
		$sampling->method('draw')->willReturnCallback(
			function (string $protocolId, array $request) use ($failure): array {
				$this->draws[] = [$protocolId, $request];
				if ($failure !== null) {
					throw $failure;
				}

				return ['id' => 's1', 'sampledLineIds' => ['l1'], 'reproducibleSeed' => '20261', 'populationSize' => 4000];
			}
		);

		return new AuditSampleController(
			request: $request,
			sampling: $sampling,
			protocols: $protocols,
			context: $context,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A draw on the caller's protocol answers 201 with the sample.
	 *
	 * @return void
	 */
	public function testADrawOnTheCallersProtocolIsStored(): void {
		$response = $this->controller(['protocol_id' => 'prot-1', 'size' => '25', 'seed' => '20261', 'account_prefix' => ' 40 '])->draw();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 's1', 'sampledLineIds' => ['l1'], 'reproducibleSeed' => '20261', 'populationSize' => 4000], $response->getData());
		$this->assertSame([['prot-1', ['size' => 25, 'seed' => 20261, 'accountPrefix' => '40']]], $this->draws);
	}//end testADrawOnTheCallersProtocolIsStored()

	/**
	 * Without a seed a new one is asked for.
	 *
	 * @return void
	 */
	public function testWithoutASeedANewOneIsAskedFor(): void {
		$this->controller(['protocol_id' => 'prot-1', 'size' => '5'])->draw();

		$this->assertSame([['prot-1', ['size' => 5, 'seed' => null, 'accountPrefix' => '']]], $this->draws);
	}//end testWithoutASeedANewOneIsAskedFor()

	/**
	 * Another administration's protocol and an unknown one answer the same 404, drawing nothing.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsProtocolIsMaskedAsNotFound(): void {
		foreach (['prot-2', 'prot-nope'] as $protocolId) {
			$response = $this->controller(['protocol_id' => $protocolId, 'size' => '5'])->draw();
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['error' => 'Controleprotocol not found'], $response->getData());
		}

		$this->assertSame([], $this->draws);
	}//end testAnotherAdministrationsProtocolIsMaskedAsNotFound()

	/**
	 * Malformed requests answer 400 and no user answers 401, drawing nothing.
	 *
	 * @return void
	 */
	public function testMalformedRequestsAreRefused(): void {
		$bad = [
			['size' => '5'],
			['protocol_id' => 'prot 1', 'size' => '5'],
			['protocol_id' => 'prot-1', 'size' => '0'],
			['protocol_id' => 'prot-1', 'size' => 'ten'],
			['protocol_id' => 'prot-1', 'size' => '5', 'seed' => '-3'],
			['protocol_id' => 'prot-1', 'size' => '5', 'seed' => '9999999999'],
		];
		foreach ($bad as $params) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($params)->draw()->getStatus(), json_encode($params));
		}

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(['protocol_id' => 'prot-1', 'size' => '5'], user: false)->draw()->getStatus());
		$this->assertSame([], $this->draws);
	}//end testMalformedRequestsAreRefused()

	/**
	 * A size that does not fit answers 422 with the reason; an unexpected failure 500 without it.
	 *
	 * @return void
	 */
	public function testFailuresAreTranslated(): void {
		$refused = $this->controller(['protocol_id' => 'prot-1', 'size' => '11'], failure: new DomainException('A sample must hold between 1 and 10 lines; 11 were asked.'))->draw();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'A sample must hold between 1 and 10 lines; 11 were asked.'], $refused->getData());

		$failed = $this->controller(['protocol_id' => 'prot-1', 'size' => '3'], failure: new RuntimeException('database gone'))->draw();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'The sample could not be drawn.'], $failed->getData());
	}//end testFailuresAreTranslated()
}//end class
