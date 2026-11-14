<?php

/**
 * Unit tests for PortalWithdrawalService.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Service\ContactCustomerResolver;
use OCA\Shillinq\Service\PortalWithdrawalService;
use OCA\Shillinq\Service\WithdrawalLinkSigner;
use OCA\Shillinq\Service\WithdrawalService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An in-memory stand-in for OpenRegister's object service.
 */
final class PortalWithdrawalObjectServiceFake {

	/**
	 * The current register.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The current schema.
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
	 * Pick the register.
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
	 * Pick the schema.
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
	 * One row.
	 *
	 * @param int|string $id            The id.
	 * @param array|null $_extend       Unused.
	 * @param bool       $files         Unused.
	 * @param mixed      $register      The register.
	 * @param mixed      $schema        The schema.
	 * @param bool       $_rbac         Unused.
	 * @param bool       $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DoesNotExistException When there is none.
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$row = ($this->data[(string)($register ?? $this->register)][(string)($schema ?? $this->schema)][(string)$id] ?? null);
		if ($row === null) {
			throw new DoesNotExistException('not found');
		}

		return $row + ['id' => (string)$id];
	}//end find()

	/**
	 * The rows matching the filters.
	 *
	 * @param array<string, mixed> $config        The filters.
	 * @param bool                 $_rbac         Unused.
	 * @param bool                 $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$out = [];
		foreach (($this->data[$this->register][$this->schema] ?? []) as $uuid => $row) {
			if (array_intersect_assoc(array_map('strval', array_filter($config['filters'] ?? [], 'is_scalar')), array_map('strval', array_filter($row, 'is_scalar'))) === array_map('strval', array_filter($config['filters'] ?? [], 'is_scalar'))) {
				$out[] = $row + ['id' => $uuid];
			}
		}

		return $out;
	}//end findAll()
}//end class

/**
 * A customer withdraws from their own appointment only; a guest from the one
 * appointment their link names, and from nothing with an altered link
 * (sales-cancellation REQ-SCX-004).
 */
#[CoversClass(PortalWithdrawalService::class)]
#[UsesClass(WithdrawalLinkSigner::class)]
#[UsesClass(PortalSubjectResolver::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PortalWithdrawalServiceTest extends TestCase {

	private const SUBJECT = 'subject-visser';

	private const CUSTOMER = 'cm-visser';

	private const APPOINTMENT = 'appt-visser';

	private const FOREIGN = 'appt-other';

	/**
	 * The object service.
	 *
	 * @var PortalWithdrawalObjectServiceFake
	 */
	private PortalWithdrawalObjectServiceFake $os;

	/**
	 * The withdrawal service double.
	 *
	 * @var WithdrawalService&MockObject
	 */
	private WithdrawalService&MockObject $withdrawals;

	/**
	 * The contact resolver double.
	 *
	 * @var ContactCustomerResolver&MockObject
	 */
	private ContactCustomerResolver&MockObject $contacts;

	/**
	 * The moment of the request.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Seed M. Visser's account, her appointment and another one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
		$this->os = new PortalWithdrawalObjectServiceFake();
		$this->os->data['portaliq']['portalAccount']['acc-1'] = [
			'subjectRef' => self::SUBJECT,
			'audience' => 'customer',
			'claims' => ['shillinq' => ['customerMasterId' => self::CUSTOMER]],
		];
		$this->os->data['shillinq']['CustomerMaster'][self::CUSTOMER] = ['name' => 'M. Visser', 'email' => 'M.Visser@example.nl'];
		$this->os->data['shillinq']['Appointment'] = [
			self::APPOINTMENT => ['title' => 'Knippen en kleuren', 'customerEmail' => 'm.visser@example.nl ', 'customerId' => 'contact-1'],
			self::FOREIGN => ['title' => 'Iemand anders', 'customerEmail' => 'ander@example.nl', 'customerId' => 'contact-2'],
		];
		$this->withdrawals = $this->createMock(WithdrawalService::class);
		$this->contacts = $this->createMock(ContactCustomerResolver::class);
	}//end setUp()

	/**
	 * The signer over a fixed instance secret.
	 *
	 * @return WithdrawalLinkSigner
	 */
	private function signer(): WithdrawalLinkSigner {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');

		return new WithdrawalLinkSigner(config: $config);
	}//end signer()

	/**
	 * The service under test.
	 *
	 * @return PortalWithdrawalService
	 */
	private function service(): PortalWithdrawalService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->os);

		return new PortalWithdrawalService(
			container: $container,
			withdrawals: $this->withdrawals,
			links: $this->signer(),
			customers: $this->contacts,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * The subject claims.
	 *
	 * @param array<string, mixed> $overrides Claims to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function claims(array $overrides = []): array {
		return array_merge(['sub' => self::SUBJECT, 'audience' => 'customer', 'trust' => 'low'], $overrides);
	}//end claims()

	/**
	 * What WithdrawalService::withdraw() returns for a done withdrawal.
	 *
	 * @return array<string, mixed>
	 */
	private static function withdrawn(): array {
		return ['withdrawn' => true, 'code' => 'withdrawable', 'reason' => null, 'withdrawal' => ['id' => 'w-1'], 'appointment' => ['id' => self::APPOINTMENT]];
	}//end withdrawn()

	/**
	 * The customer withdraws from the appointment booked with their e-mail
	 * address, through the portal channel.
	 *
	 * @return void
	 */
	public function testACustomerWithdrawsFromTheirOwnAppointment(): void {
		$this->contacts->expects(self::never())->method('resolve');
		$this->withdrawals->expects(self::once())->method('withdraw')
			->with(self::APPOINTMENT, 'portal', $this->now)
			->willReturn(self::withdrawn());

		self::assertSame(
			['status' => 'withdrawn', 'code' => null, 'reason' => null],
			$this->service()->withdrawForSubject(claims: self::claims(), appointmentId: ' ' . self::APPOINTMENT, now: $this->now)
		);
	}//end testACustomerWithdrawsFromTheirOwnAppointment()

	/**
	 * An appointment booked under the customer's contact counts as theirs
	 * too; one that is neither is refused without withdrawing anything.
	 *
	 * @return void
	 */
	public function testTheContactDecidesWhenTheEmailDoesNot(): void {
		$this->os->data['shillinq']['Appointment'][self::APPOINTMENT]['customerEmail'] = '';
		$this->contacts->method('resolve')->willReturnCallback(
			static fn (string $reference, array $customers): ?string => $reference === 'contact-1' ? (string)$customers[0]['id'] : null
		);
		$this->withdrawals->expects(self::once())->method('withdraw')->with(self::APPOINTMENT, 'portal', $this->now)->willReturn(self::withdrawn());

		self::assertSame('withdrawn', $this->service()->withdrawForSubject(claims: self::claims(), appointmentId: self::APPOINTMENT, now: $this->now)['status']);
		self::assertSame('forbidden', $this->service()->withdrawForSubject(claims: self::claims(), appointmentId: self::FOREIGN, now: $this->now)['status']);
	}//end testTheContactDecidesWhenTheEmailDoesNot()

	/**
	 * Another customer's appointment, another audience, an unknown subject,
	 * a missing appointment or a path-shaped id are refused.
	 *
	 * @return void
	 */
	public function testEverythingButTheirOwnAppointmentIsRefused(): void {
		$this->withdrawals->expects(self::never())->method('withdraw');
		$service = $this->service();

		foreach ([
			[self::claims(), self::FOREIGN],
			[self::claims(overrides: ['audience' => 'supplier']), self::APPOINTMENT],
			[self::claims(overrides: ['sub' => 'subject-unknown']), self::APPOINTMENT],
			[self::claims(), 'appt-missing'],
			[self::claims(), '../' . self::APPOINTMENT],
			[self::claims(), ''],
		] as [$claims, $id]) {
			self::assertSame(['status' => 'forbidden', 'code' => null, 'reason' => null], $service->withdrawForSubject(claims: $claims, appointmentId: $id, now: $this->now), $id);
		}
	}//end testEverythingButTheirOwnAppointmentIsRefused()

	/**
	 * A guest's valid link withdraws its appointment through the guest
	 * channel; an altered or expired one withdraws nothing.
	 *
	 * @return void
	 */
	public function testAGuestLinkOpensOnlyItsOwnAppointmentWhileValid(): void {
		$token = (string)$this->signer()->tokenFor(appointmentId: self::APPOINTMENT, expiresAt: new DateTimeImmutable('2026-10-04T23:59:59+00:00'));
		$this->withdrawals->expects(self::once())->method('withdraw')->with(self::APPOINTMENT, 'guest-link', $this->now)->willReturn(self::withdrawn());
		$service = $this->service();

		self::assertSame('withdrawn', $service->withdrawWithLink(token: $token, now: $this->now)['status']);

		[, $signature] = explode('.', $token);
		$tampered = rtrim(strtr(base64_encode(self::FOREIGN . '|' . strtotime('2026-10-04T23:59:59Z')), '+/', '-_'), '=') . '.' . $signature;
		self::assertSame('forbidden', $service->withdrawWithLink(token: $tampered, now: $this->now)['status']);
		self::assertSame('forbidden', $service->withdrawWithLink(token: $token, now: new DateTimeImmutable('2026-10-05T00:00:00+00:00'))['status']);
	}//end testAGuestLinkOpensOnlyItsOwnAppointmentWhileValid()

	/**
	 * A withdrawal the rule refuses carries its code and reason; a missing
	 * appointment says no more than another's; a failure is a downstream error.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresAreTold(): void {
		$token = (string)$this->signer()->tokenFor(appointmentId: self::APPOINTMENT, expiresAt: new DateTimeImmutable('2026-10-04T23:59:59+00:00'));
		$this->withdrawals->method('withdraw')->willReturnOnConsecutiveCalls(
			['withdrawn' => false, 'code' => 'started', 'reason' => 'The appointment has started.', 'withdrawal' => null, 'appointment' => ['id' => self::APPOINTMENT]],
			['withdrawn' => false, 'code' => 'not-booked-online', 'reason' => null, 'withdrawal' => null, 'appointment' => null],
			self::throwException(new RuntimeException('OpenRegister is down'))
		);
		$service = $this->service();

		self::assertSame(['status' => 'refused', 'code' => 'started', 'reason' => 'The appointment has started.'], $service->withdrawWithLink(token: $token, now: $this->now));
		self::assertSame(['status' => 'forbidden', 'code' => null, 'reason' => null], $service->withdrawWithLink(token: $token, now: $this->now));
		self::assertSame(['status' => 'downstream_error', 'code' => null, 'reason' => null], $service->withdrawWithLink(token: $token, now: $this->now));
	}//end testRefusalsAndFailuresAreTold()
}//end class
