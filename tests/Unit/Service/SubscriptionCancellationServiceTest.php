<?php

/**
 * Unit tests for SubscriptionCancellationService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SubscriptionCancellationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An object service double: rows per register and schema, read by id or filter.
 */
final class SubscriptionObjectServiceFake {

	/**
	 * The register the next read targets.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The schema the next read targets.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Rows by register, schema and id.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	public array $data = [];

	/**
	 * Target a register.
	 *
	 * @param string $register The register.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		$this->register = $register;
		return $this;
	}//end setRegister()

	/**
	 * Target a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * One row by id.
	 *
	 * @param int|string $id            The id.
	 * @param array|null $_extend       Unused.
	 * @param bool       $files         Unused.
	 * @param mixed      $register      The register, or the targeted one.
	 * @param mixed      $schema        The schema, or the targeted one.
	 * @param bool       $_rbac         Unused.
	 * @param bool       $_multitenancy Unused.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$row = ($this->data[(string)($register ?? $this->register)][(string)($schema ?? $this->schema)][(string)$id] ?? null);
		if ($row === null) {
			throw new DoesNotExistException('not found');
		}

		return $row;
	}//end find()

	/**
	 * Rows matching every filter.
	 *
	 * @param array $config        The query, with `filters`.
	 * @param bool  $_rbac         Unused.
	 * @param bool  $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$out = [];
		foreach (($this->data[$this->register][$this->schema] ?? []) as $uuid => $row) {
			$match = true;
			foreach (($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					$match = false;
				}
			}

			if ($match === true) {
				$out[] = $row + ['id' => $uuid];
			}
		}

		return $out;
	}//end findAll()
}//end class

/**
 * A transition engine double that records each system transition.
 */
final class SubscriptionTransitionEngineFake {

	/**
	 * The transitions made: [objectId, action, app, data].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
	 */
	public array $made = [];

	/**
	 * Whether the engine refuses.
	 *
	 * @var bool
	 */
	public bool $refuses = false;

	/**
	 * Record a transition as the app.
	 *
	 * @param string               $objectId The object.
	 * @param string               $action   The transition.
	 * @param string               $app      The app.
	 * @param array<string, mixed> $data     The inputs.
	 *
	 * @return object The transitioned object.
	 */
	public function transitionAsSystem(string $objectId, string $action, string $app, array $data = []): object {
		if ($this->refuses === true) {
			throw new RuntimeException('Transition refused');
		}

		$this->made[] = [$objectId, $action, $app, $data];
		return new \stdClass();
	}//end transitionAsSystem()
}//end class

/**
 * A customer ends their own running subscription; nobody else's.
 *
 * @covers \OCA\Shillinq\Service\SubscriptionCancellationService
 * @uses   \OCA\Shillinq\Portal\PortalSubjectResolver
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Lifecycle\CancellationReasonGuard
 */
final class SubscriptionCancellationServiceTest extends TestCase {
	private const SUBJECT = '10000000-0000-4000-8000-000000000001';
	private const CUSTOMER = '20000000-0000-4000-8000-000000000002';
	private const OTHER = '20000000-0000-4000-8000-00000000dead';
	private const PROFILE = '40000000-0000-4000-8000-000000000004';
	private const FOREIGN = '40000000-0000-4000-8000-00000000beef';

	/**
	 * The OpenRegister double.
	 *
	 * @var SubscriptionObjectServiceFake
	 */
	private SubscriptionObjectServiceFake $os;

	/**
	 * The transition engine double.
	 *
	 * @var SubscriptionTransitionEngineFake
	 */
	private SubscriptionTransitionEngineFake $engine;

	protected function setUp(): void {
		parent::setUp();

		$this->os = new SubscriptionObjectServiceFake();
		$this->engine = new SubscriptionTransitionEngineFake();
		$this->os->data['portaliq']['portalAccount']['acc-1'] = [
			'subjectRef' => self::SUBJECT,
			'audience' => 'customer',
			'claims' => ['shillinq' => ['customerMasterId' => self::CUSTOMER]],
		];
		$this->os->data['shillinq']['RecurringInvoiceProfile'] = [
			self::PROFILE => ['name' => 'Maandabonnement Kracht Basis', 'status' => 'active', 'customerMasterId' => self::CUSTOMER],
			self::FOREIGN => ['name' => 'Iemand anders', 'status' => 'active', 'customerMasterId' => self::OTHER],
		];
	}//end setUp()

	/**
	 * The service wired to the doubles.
	 *
	 * @return SubscriptionCancellationService
	 */
	private function service(): SubscriptionCancellationService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => str_contains($id, 'TransitionEngine') === true ? $this->engine : $this->os
		);

		return new SubscriptionCancellationService(container: $container, logger: new NullLogger());
	}//end service()

	/**
	 * The claims of the customer's subject.
	 *
	 * @param array<string, mixed> $overrides Claims to change.
	 *
	 * @return array<string, mixed> The claims.
	 */
	private function claims(array $overrides = []): array {
		return array_merge(['sub' => self::SUBJECT, 'audience' => 'customer', 'trust' => 'low'], $overrides);
	}//end claims()

	/**
	 * The customer ends their own active subscription, with their reason, as the app.
	 *
	 * @return void
	 */
	public function testACustomerEndsTheirOwnSubscription(): void {
		$result = $this->service()->cancel(claims: $this->claims(), profileId: self::PROFILE, reasonCode: 'switched-provider', reasonText: ' Bij de huisarts ');

		$this->assertSame('cancelled', $result);
		$this->assertSame(
			[[self::PROFILE, 'endFromActive', 'shillinq', ['cancellationReasonCode' => 'switched-provider', 'cancellationReasonText' => 'Bij de huisarts']]],
			$this->engine->made
		);
	}//end testACustomerEndsTheirOwnSubscription()

	/**
	 * A paused one ends through endFromPaused, and an unlisted reason becomes not-given.
	 *
	 * @return void
	 */
	public function testAPausedSubscriptionEndsWithoutAReason(): void {
		$this->os->data['shillinq']['RecurringInvoiceProfile'][self::PROFILE]['status'] = 'paused';

		$this->assertSame('cancelled', $this->service()->cancel(claims: $this->claims(), profileId: self::PROFILE, reasonCode: 'whatever'));
		$this->assertSame('endFromPaused', $this->engine->made[0][1]);
		$this->assertSame(['cancellationReasonCode' => 'not-given', 'cancellationReasonText' => null], $this->engine->made[0][3]);
	}//end testAPausedSubscriptionEndsWithoutAReason()

	/**
	 * Another customer's profile is refused, and nothing is ended.
	 *
	 * @return void
	 */
	public function testAnotherCustomersProfileIsRefused(): void {
		$this->assertSame('forbidden', $this->service()->cancel(claims: $this->claims(), profileId: self::FOREIGN));
		$this->assertSame([], $this->engine->made);
	}//end testAnotherCustomersProfileIsRefused()

	/**
	 * Wrong audience, unknown subject, unknown profile, a crafted id or a draft: refused.
	 *
	 * @return void
	 */
	public function testEverythingButTheirOwnRunningSubscriptionIsRefused(): void {
		$this->os->data['shillinq']['RecurringInvoiceProfile']['draft-1'] = ['status' => 'draft', 'customerMasterId' => self::CUSTOMER];
		$service = $this->service();

		$this->assertSame('forbidden', $service->cancel(claims: $this->claims(['audience' => 'parent']), profileId: self::PROFILE));
		$this->assertSame('forbidden', $service->cancel(claims: $this->claims(['sub' => '10000000-0000-4000-8000-00000000f00d']), profileId: self::PROFILE));
		$this->assertSame('forbidden', $service->cancel(claims: $this->claims(), profileId: 'missing'));
		$this->assertSame('forbidden', $service->cancel(claims: $this->claims(), profileId: '../'.self::PROFILE));
		$this->assertSame('forbidden', $service->cancel(claims: $this->claims(), profileId: 'draft-1'));
		$this->assertSame([], $this->engine->made);
	}//end testEverythingButTheirOwnRunningSubscriptionIsRefused()

	/**
	 * An ended subscription answers cancelled again without a second transition.
	 *
	 * @return void
	 */
	public function testASecondRequestIsAnsweredWithoutAnotherEnd(): void {
		$this->os->data['shillinq']['RecurringInvoiceProfile'][self::PROFILE]['status'] = 'ended';

		$this->assertSame('cancelled', $this->service()->cancel(claims: $this->claims(), profileId: self::PROFILE));
		$this->assertSame([], $this->engine->made);
	}//end testASecondRequestIsAnsweredWithoutAnotherEnd()

	/**
	 * A refused transition is a downstream error, not a cancellation.
	 *
	 * @return void
	 */
	public function testARefusedTransitionIsADownstreamError(): void {
		$this->engine->refuses = true;

		$this->assertSame('downstream_error', $this->service()->cancel(claims: $this->claims(), profileId: self::PROFILE));
	}//end testARefusedTransitionIsADownstreamError()
}//end class
