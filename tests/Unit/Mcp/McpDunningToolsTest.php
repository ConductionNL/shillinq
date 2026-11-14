<?php

/**
 * shillinq.dunning.* refuse exactly where their REST twins do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use OCA\Shillinq\Controller\DunningController;
use OCA\Shillinq\Controller\DunningPauseRequest;
use OCA\Shillinq\Controller\IncassoTransferRequest;
use OCA\Shillinq\Controller\PauseResumptionRequest;
use OCA\Shillinq\Kernel\Contracts\BIKStaffelCalculatorInterface;
use OCA\Shillinq\Kernel\Contracts\DunningRunServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IncassoDossierComposerInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpDunningTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The tools and DunningController run against the same caller,
 * administration access, pause records and services, and must agree on
 * every outcome.
 */
#[CoversClass(McpDunningTools::class)]
#[CoversClass(McpAdministrationGate::class)]
#[CoversClass(McpResult::class)]
#[UsesClass(DunningController::class)]
#[UsesClass(DunningPauseRequest::class)]
#[UsesClass(PauseResumptionRequest::class)]
#[UsesClass(IncassoTransferRequest::class)]
#[UsesClass(ObjectIdentifier::class)]
class McpDunningToolsTest extends TestCase {

	/**
	 * HTTP status of the REST twin to the tool's outcome.
	 */
	private const OUTCOME = [200 => 'ok', 201 => 'ok', 400 => 'invalid_arguments', 401 => 'unauthorised', 404 => 'not_found', 409 => 'conflict', 500 => 'failed'];

	/**
	 * The pause records the object-service double serves, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $pauses = [];

	/**
	 * The run-service double.
	 *
	 * @var DunningRunServiceInterface&MockObject
	 */
	private DunningRunServiceInterface $runs;

	/**
	 * The dossier-composer double.
	 *
	 * @var IncassoDossierComposerInterface&MockObject
	 */
	private IncassoDossierComposerInterface $dossier;

	/**
	 * The situations every administration-scoped action faces.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: \Throwable|null}>
	 */
	public static function situations(): array {
		return [
			'permitted'              => [true, true, null],
			'not signed in'          => [false, true, null],
			'foreign administration' => [true, false, null],
			'service refuses'        => [true, true, new RuntimeException('Cannot execute DunningRun: invoice inv-1 is paused.')],
		];
	}//end situations()

	/**
	 * execute_run: both surfaces agree; a refusal by the service is a conflict.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testExecuteRunAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown): void {
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, params: ['administration_id' => 'adm-1', 'invoiceId' => 'inv-1']);
		$calls = [];
		$this->runs->method('executeStage')->willReturnCallback(
			static function (string $administrationId, array $params) use (&$calls, $thrown): array {
				$calls[] = [$administrationId, $params['invoiceId']];
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => 'run-1'];
			}
		);

		$rest = self::OUTCOME[$controller->executeRun()->getStatus()];
		$tool = $tools->executeRun(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'stageNr' => 2]);

		$this->assertSame($rest, $this->outcomeOf(result: $tool));
		$this->assertSame(self::expected(signedIn: $signedIn, canAccess: $canAccess, thrown: $thrown, refusal: 'conflict'), $rest);
		$reached = ($signedIn === true && $canAccess === true);
		$this->assertSame($reached === true ? [['adm-1', 'inv-1'], ['adm-1', 'inv-1']] : [], $calls);
	}//end testExecuteRunAgreesWithTheController()

	/**
	 * execute_run passes only the fields it declares to the service.
	 *
	 * @return void
	 */
	public function testExecuteRunPassesOnlyItsOwnFields(): void {
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, params: []);
		$this->runs->expects($this->once())->method('executeStage')
			->with('adm-1', ['invoiceId' => 'inv-1', 'ladderId' => 'standard', 'stageNr' => 2])
			->willReturn(['id' => 'run-1']);

		$result = $tools->executeRun(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'ladderId' => 'standard', 'stageNr' => 2]);

		$this->assertSame(['dunningRun' => ['id' => 'run-1']], $result);
	}//end testExecuteRunPassesOnlyItsOwnFields()

	/**
	 * pause: both surfaces agree, and the pause is stamped with the signed-in user.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testPauseAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown): void {
		$params = ['administration_id' => 'adm-1', 'invoiceId' => 'inv-1', 'reason' => 'DISPUTED', 'details' => 'Wrong amount'];
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, params: $params);
		$calls = [];
		$this->runs->method('pause')->willReturnCallback(
			static function (string $administrationId, string $invoiceId, string $reason, string $details, string $pausedBy) use (&$calls, $thrown): array {
				$calls[] = [$administrationId, $invoiceId, $reason, $details, $pausedBy];
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => 'pause-1'];
			}
		);

		$rest = self::OUTCOME[$controller->pause()->getStatus()];
		$tool = $tools->pause(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'reason' => 'DISPUTED', 'details' => 'Wrong amount']);

		$this->assertSame($rest, $this->outcomeOf(result: $tool));
		$this->assertSame(self::expected(signedIn: $signedIn, canAccess: $canAccess, thrown: $thrown, refusal: 'failed'), $rest);
		if ($signedIn === true && $canAccess === true) {
			$this->assertSame(array_fill(0, 2, ['adm-1', 'inv-1', 'DISPUTED', 'Wrong amount', 'alice']), $calls);
		}
	}//end testPauseAgreesWithTheController()

	/**
	 * write_off: both surfaces agree.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testWriteOffAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown): void {
		$params = ['administration_id' => 'adm-1', 'invoiceId' => 'inv-1', 'principalDepreciated' => 120.5, 'art29OBDeclaration' => 'Bankrupt'];
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, params: $params);
		$this->runs->method('writeOff')->willReturnCallback(
			static function () use ($thrown): array {
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => 'wo-1'];
			}
		);

		$rest = self::OUTCOME[$controller->writeOff()->getStatus()];
		$tool = $tools->writeOff(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'principalDepreciated' => 120.5, 'art29OBDeclaration' => 'Bankrupt']);

		$this->assertSame($rest, $this->outcomeOf(result: $tool));
		$this->assertSame(self::expected(signedIn: $signedIn, canAccess: $canAccess, thrown: $thrown, refusal: 'failed'), $rest);
	}//end testWriteOffAgreesWithTheController()

	/**
	 * write_off refuses a zero principal or an empty declaration, as the controller does, before the service.
	 *
	 * @return void
	 */
	public function testWriteOffRefusesAnIncompleteWriteOff(): void {
		$params = ['administration_id' => 'adm-1', 'invoiceId' => 'inv-1', 'principalDepreciated' => 0, 'art29OBDeclaration' => 'Bankrupt'];
		[$controller, $tools] = $this->surfaces(signedIn: true, canAccess: true, params: $params);
		$this->runs->expects($this->never())->method('writeOff');

		$this->assertSame(400, $controller->writeOff()->getStatus());
		$this->assertSame('invalid_arguments', $tools->writeOff(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'principalDepreciated' => 0, 'art29OBDeclaration' => 'Bankrupt'])['error']);
		$this->assertSame('invalid_arguments', $tools->writeOff(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'principalDepreciated' => 10, 'art29OBDeclaration' => '  '])['error']);
	}//end testWriteOffRefusesAnIncompleteWriteOff()

	/**
	 * dossier: both surfaces agree, and a composed dossier is returned as is.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the composer throws.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testDossierAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown): void {
		$params = ['administration_id' => 'adm-1', 'invoiceId' => 'inv-1', 'customerId' => 'cus-1'];
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, params: $params);
		$this->dossier->method('compose')->willReturnCallback(
			static function (string $administrationId, string $invoiceId, string $customerId) use ($thrown): array {
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['for' => [$administrationId, $invoiceId, $customerId]];
			}
		);

		$rest = self::OUTCOME[$controller->dossier()->getStatus()];
		$tool = $tools->dossier(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'customerId' => 'cus-1']);

		$this->assertSame($rest, $this->outcomeOf(result: $tool));
		$this->assertSame(self::expected(signedIn: $signedIn, canAccess: $canAccess, thrown: $thrown, refusal: 'failed'), $rest);
		if ($rest === 'ok') {
			$this->assertSame(['dossier' => ['for' => ['adm-1', 'inv-1', 'cus-1']]], $tool);
		}
	}//end testDossierAgreesWithTheController()

	/**
	 * The resume situations: the pause's own administration decides.
	 *
	 * @return array<string, array{0: bool, 1: array<string, mixed>|null, 2: \Throwable|null, 3: string}>
	 */
	public static function resumptions(): array {
		$own = ['id' => 'pause-1', 'administrationId' => 'adm-1'];
		return [
			'resolved'           => [true, $own, null, 'ok'],
			'not signed in'      => [false, $own, null, 'unauthorised'],
			'pause not found'    => [true, null, null, 'not_found'],
			'foreign pause'      => [true, ['id' => 'pause-1', 'administrationId' => 'adm-9'], null, 'not_found'],
			'service refuses'    => [true, $own, new RuntimeException('Pause pause-1 not found'), 'not_found'],
		];
	}//end resumptions()

	/**
	 * resume: both surfaces agree, and a pause of another administration never reaches the service.
	 *
	 * @param bool                      $signedIn Whether a user is signed in.
	 * @param array<string, mixed>|null $pause    The stored pause, or null.
	 * @param \Throwable|null           $thrown   What the service throws.
	 * @param string                    $expected The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('resumptions')]
	public function testResumeAgreesWithTheController(bool $signedIn, ?array $pause, ?\Throwable $thrown, string $expected): void {
		if ($pause !== null) {
			$this->pauses['pause-1'] = $pause;
		}

		$params = ['administration_id' => 'adm-1', 'resolution' => 'resolve', 'partialSettlement' => 50];
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: true, params: $params, accessible: ['adm-1']);
		$calls = [];
		$this->runs->method('resumePause')->willReturnCallback(
			static function (string $administrationId, string $pauseId, string $resolution, ?float $partialSettlement) use (&$calls, $thrown): array {
				$calls[] = [$administrationId, $pauseId, $resolution, $partialSettlement];
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => $pauseId, 'status' => 'RESOLVED'];
			}
		);

		$rest = self::OUTCOME[$controller->resumePause('pause-1')->getStatus()];
		$tool = $tools->resume(['administrationId' => 'adm-1', 'pauseId' => 'pause-1', 'resolution' => 'resolve', 'partialSettlement' => 50]);

		$this->assertSame($expected, $rest, 'the REST twin');
		$this->assertSame($expected, $this->outcomeOf(result: $tool), 'the tool');
		$reached = ($expected === 'ok' || $thrown !== null);
		$this->assertSame($reached === true ? array_fill(0, 2, ['adm-1', 'pause-1', 'resolve', 50.0]) : [], $calls);
	}//end testResumeAgreesWithTheController()

	/**
	 * A pause of another administration the caller may also reach is still
	 * refused: it must belong to the administration the call names.
	 *
	 * @return void
	 */
	public function testResumeRefusesAPauseOfAnotherReachableAdministration(): void {
		$this->pauses['pause-1'] = ['id' => 'pause-1', 'administrationId' => 'adm-2'];
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, params: [], accessible: ['adm-1', 'adm-2']);
		$this->runs->expects($this->never())->method('resumePause');

		$this->assertSame(
			McpResult::error(code: 'not_found', message: McpDunningTools::PAUSE_NOT_FOUND),
			$tools->resume(['administrationId' => 'adm-1', 'pauseId' => 'pause-1'])
		);
	}//end testResumeRefusesAPauseOfAnotherReachableAdministration()

	/**
	 * The outcome both surfaces must reach.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 * @param string          $refusal   The code a service failure maps to.
	 *
	 * @return string
	 */
	private static function expected(bool $signedIn, bool $canAccess, ?\Throwable $thrown, string $refusal): string {
		if ($signedIn === false) {
			return 'unauthorised';
		}

		if ($canAccess === false) {
			return 'not_found';
		}

		return $thrown === null ? 'ok' : $refusal;
	}//end expected()

	/**
	 * A tool answer as an outcome.
	 *
	 * @param array<string, mixed> $result The answer.
	 *
	 * @return string
	 */
	private function outcomeOf(array $result): string {
		return McpResult::isError($result) === true ? $result['error'] : 'ok';
	}//end outcomeOf()

	/**
	 * The controller and the tools over the same doubles.
	 *
	 * @param bool                 $signedIn   Whether a user is signed in.
	 * @param bool                 $canAccess  Whether the listed administrations are accessible.
	 * @param array<string, mixed> $params     The REST request's parameters.
	 * @param list<string>         $accessible The administrations that exist for the caller.
	 *
	 * @return array{0: DunningController, 1: McpDunningTools}
	 */
	private function surfaces(bool $signedIn, bool $canAccess, array $params, array $accessible = ['adm-1']): array {
		$this->runs = $this->createMock(DunningRunServiceInterface::class);
		$this->dossier = $this->createMock(IncassoDossierComposerInterface::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($signedIn === true ? 'alice' : null);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && in_array($id, $accessible, true));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$objects = new DuckObjectServiceAdapter($this->pauseStore());
		$logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$controller = new DunningController(
			$request,
			$this->createMock(BIKStaffelCalculatorInterface::class),
			$this->runs,
			$this->dossier,
			$context,
			$logger,
			$appConfig,
			$objects,
			$l10n
		);
		$tools = new McpDunningTools(new McpAdministrationGate($session, $context), $this->runs, $this->dossier, $objects, $appConfig, $logger);

		return [$controller, $tools];
	}//end surfaces()

	/**
	 * A duck-typed DunningPauseDispute store over {@see $pauses}; find() throws on a miss, as the real one does.
	 *
	 * @return object
	 */
	private function pauseStore(): object {
		return new class($this) {
			/**
			 * Constructor.
			 *
			 * @param McpDunningToolsTest $test The test whose pauses it serves.
			 */
			public function __construct(private readonly McpDunningToolsTest $test) {
			}

			/**
			 * Fluent register selector.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * Fluent schema selector.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * The pause by id.
			 *
			 * @param string $id The id.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				if (isset($this->test->pauses[$id]) === true) {
					return $this->test->pauses[$id];
				}

				throw new RuntimeException("Object with identifier '{$id}' not found");
			}
		};
	}//end pauseStore()
}//end class
