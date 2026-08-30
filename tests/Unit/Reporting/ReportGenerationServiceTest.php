<?php

/**
 * Unit tests for ReportGenerationService's docudesk-unavailable handling.
 *
 * Proves reports-via-docudesk REQ-RVD-005 (ADR-081 rule 7): when a document
 * generator throws DocudeskUnavailableException, ReportGenerationService::
 * generate() records a GeneratedReport with `status: 'unavailable'` BEFORE
 * returning, and the returned envelope carries the distinguishable
 * `error: 'docudesk-unavailable'` code -- distinct from the generic
 * `error: 'generation-failed'` / no-record path any OTHER exception type
 * still takes (regression check). Before this change, EVERY generator
 * exception -- including a docudesk-absent probe failure -- collapsed into
 * the undiagnostic generic path with no record written at all.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * A fake OpenRegister ObjectService capturing every saveObject() call.
 */
final class FakeRgsObjectService {

	/**
	 * @var array<int, array{object: array<string, mixed>, register: string, schema: string}>
	 */
	public array $saved = [];

	/**
	 * @param array<string, mixed> $object The record.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
		return array_merge($object, ['id' => 'saved-' . count($this->saved)]);
	}//end saveObject()
}//end class

/**
 * A minimal PSR container resolving only the OpenRegister ObjectService.
 */
final class FakeRgsContainer implements ContainerInterface {

	/**
	 * @param FakeRgsObjectService $objectService The fake ObjectService.
	 */
	public function __construct(
		private FakeRgsObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * @param string $id Service id.
	 *
	 * @return mixed
	 */
	public function get(string $id): mixed {
		if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
			return $this->objectService;
		}

		throw new \RuntimeException('Unknown service: ' . $id);
	}//end get()

	/**
	 * @param string $id Service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return $id === 'OCA\\OpenRegister\\Service\\ObjectService';
	}//end has()
}//end class

/**
 * A generator stub that always throws the given exception.
 */
final class ThrowingGenerator implements ReportGeneratorInterface {

	/**
	 * @param \Throwable $exception The exception generate() throws.
	 */
	public function __construct(
		private readonly \Throwable $exception,
	) {

	}//end __construct()

	public static function reportType(): string {
		return 'balance-sheet';
	}//end reportType()

	public static function supportedFormats(): array {
		return ['odt', 'pdf'];
	}//end supportedFormats()

	public function generate(array $context, string $format): GeneratedFile {
		throw $this->exception;
	}//end generate()
}//end class

/**
 * Tests ReportGenerationService's docudesk-unavailable outcome.
 */
final class ReportGenerationServiceTest extends TestCase {

	/**
	 * Build a service wired to a fake container/objectService, with the
	 * generator index pre-seeded via reflection (bypassing glob discovery,
	 * which only scans real files under lib/Reporting/Generator/).
	 *
	 * @param ReportGeneratorInterface $generator The single generator to seed under its reportType().
	 * @param FakeRgsObjectService $objectService The fake ObjectService (for assertions).
	 *
	 * @return ReportGenerationService
	 */
	private function service(ReportGeneratorInterface $generator, FakeRgsObjectService $objectService): ReportGenerationService {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$service = new ReportGenerationService(
			new FakeRgsContainer($objectService),
			$this->createMock(IRootFolder::class),
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$userSession,
			new NullLogger(),
		);

		$reflection = new ReflectionClass($service);
		$property = $reflection->getProperty('generators');
		$property->setAccessible(true);
		$property->setValue($service, [$generator::reportType() => $generator]);

		return $service;
	}//end service()

	/**
	 * REQ-RVD-005: docudesk-unavailable is a visible, distinguishable outcome.
	 */
	public function testDocudeskUnavailableRecordsVisibleStatusAndDistinctError(): void {
		$objectService = new FakeRgsObjectService();
		$generator = new ThrowingGenerator(new DocudeskUnavailableException('docudesk not installed'));
		$service = $this->service($generator, $objectService);

		$result = $service->generate('balance-sheet', '2026', 'admin-1', 'pdf');

		$this->assertSame('docudesk-unavailable', $result['error']);
		$this->assertArrayHasKey('record', $result);

		$this->assertCount(1, $objectService->saved, 'A GeneratedReport record must be saved for a docudesk-unavailable attempt.');
		$this->assertSame('unavailable', $objectService->saved[0]['object']['status']);
		$this->assertSame('balance-sheet', $objectService->saved[0]['object']['reportType']);
		$this->assertSame('GeneratedReport', $objectService->saved[0]['schema']);
	}//end testDocudeskUnavailableRecordsVisibleStatusAndDistinctError()

	/**
	 * Regression: any OTHER exception type keeps the pre-existing generic
	 * behaviour -- no record written, `error: 'generation-failed'`.
	 */
	public function testOtherExceptionKeepsGenericBehaviourAndWritesNoRecord(): void {
		$objectService = new FakeRgsObjectService();
		$generator = new ThrowingGenerator(new \RuntimeException('template lookup exploded'));
		$service = $this->service($generator, $objectService);

		$result = $service->generate('balance-sheet', '2026', 'admin-1', 'pdf');

		$this->assertSame('generation-failed', $result['error']);
		$this->assertCount(0, $objectService->saved, 'A generic generation failure must not write a GeneratedReport record.');
	}//end testOtherExceptionKeepsGenericBehaviourAndWritesNoRecord()
}//end class
