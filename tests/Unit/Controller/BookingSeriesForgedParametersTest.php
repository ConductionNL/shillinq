<?php

/**
 * Forged request parameters cannot change who BookingDepthController::createSeries
 * acts for, or which administration the resource belongs to.
 *
 * The acting user comes from the session and the resource's administration
 * from the stored Resource, never from the request body.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BookingDepthController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\NoShowFeeCaptureService;
use OCA\Shillinq\Service\RecurringSeriesService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Forged-parameter cases for the recurring series create.
 *
 * @covers \OCA\Shillinq\Controller\BookingDepthController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BookingSeriesForgedParametersTest extends TestCase {

	private const BODY = [
		'administrationId' => 'adm-1',
		'seriesId' => 'ser-1',
		'serviceId' => 'svc-1',
		'resourceId' => 'room-2',
		'startTime' => '2026-06-01T09:00:00Z',
		'durationMinutes' => '30',
		'recurrenceRule' => 'FREQ=WEEKLY;COUNT=2',
	];

	/**
	 * The object service double of the current case.
	 *
	 * @var ForgedSeriesObjects|null
	 */
	private ?ForgedSeriesObjects $objects = null;

	/**
	 * An anonymous caller stays anonymous when the body names a user.
	 *
	 * @return void
	 */
	public function testAnonymousCallerNamingAUserGets401(): void {
		$response = $this->controller(user: null)->createSeries();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame([], $this->objects?->saved);

	}//end testAnonymousCallerNamingAUserGets401()

	/**
	 * A resource of another administration stays foreign when the body claims
	 * it belongs to the caller's administration.
	 *
	 * @return void
	 */
	public function testForeignResourceClaimedAsOwnGets403(): void {
		$response = $this->controller(user: 'alice')->createSeries();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([], $this->objects?->saved);

	}//end testForeignResourceClaimedAsOwnGets403()

	/**
	 * The controller for one session user with the forged body.
	 *
	 * @param string|null $user The session user.
	 *
	 * @return BookingDepthController
	 */
	private function controller(?string $user): BookingDepthController {
		$this->objects = new ForgedSeriesObjects();
		$body        = self::BODY + ['userId' => 'alice', 'resourceAdministrationId' => 'adm-1'];

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default)
		);

		$context = $this->createStub(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $user === 'alice' && $administrationId === 'adm-1'
		);

		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('books');

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$series = $this->createStub(RecurringSeriesService::class);
		$series->method('planSeries')->willReturn(['generated' => [['recurrenceIndex' => 0]], 'skipped' => []]);

		return new BookingDepthController(
			request: $request,
			container: $container,
			settings: $settings,
			context: $context,
			noShowFee: $this->createStub(NoShowFeeCaptureService::class),
			series: $series,
			logger: new NullLogger(),
			l10n: $this->createStub(IL10N::class),
		);

	}//end controller()
}//end class

/**
 * Object service double: one Resource of administration adm-2, recorded saves.
 */
final class ForgedSeriesObjects {

	/**
	 * The current schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Every saved object.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema name.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * The resource of adm-2; no appointments.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->schema === 'Resource') {
			return [['resourceId' => 'room-2', 'administrationId' => 'adm-2']];
		}

		return [];
	}//end findAll()

	/**
	 * Record a save.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		$this->saved[] = $object;
		return $object;
	}//end saveObject()
}//end class
