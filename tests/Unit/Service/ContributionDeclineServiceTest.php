<?php

/**
 * Unit tests for ContributionDeclineService.
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\ContributionDeclineService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An ObjectService fake with OpenRegister's real lookup semantics: `find()`
 * answers by uuid and throws on a miss, and `findAll()` filters on JSON
 * properties only, so a filter on `id` matches nothing.
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class DeclineObjectServiceFake {
	/**
	 * The selected register.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The selected schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Rows by register, schema and uuid.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	public array $data = [];

	/**
	 * Every save, in order.
	 *
	 * @var array<int, array{schema: mixed, object: array<string, mixed>, uuid: string|null, rbac: bool}>
	 */
	public array $saved = [];

	/**
	 * Make every read throw.
	 *
	 * @var bool
	 */
	public bool $down = false;

	public function setRegister(string $register): static {
		$this->register = $register;
		return $this;
	}//end setRegister()

	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * @param int|string $id The uuid.
	 * @param array|null $_extend Unused.
	 * @param bool $files Unused.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
		if ($this->down === true) {
			throw new RuntimeException('OpenRegister unavailable');
		}

		$row = ($this->data[(string)($register ?? $this->register)][(string)($schema ?? $this->schema)][(string)$id] ?? null);
		if ($row === null) {
			throw new DoesNotExistException('not found');
		}

		// A read entity's payload does not carry its own uuid.
		return $row;
	}//end find()

	/**
	 * @param array<string, mixed> $config The filters and limit.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		if ($this->down === true) {
			throw new RuntimeException('OpenRegister unavailable');
		}

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

	/**
	 * @param array|object $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array|object $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = ['schema' => $schema, 'object' => (array)$object, 'uuid' => $uuid, 'rbac' => $_rbac];
		if ($uuid !== null) {
			$this->data[(string)$register][(string)$schema][$uuid] = (array)$object;
		}

		return (array)$object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * A guardian closes their own open voluntary contribution, and nothing else.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 */
final class ContributionDeclineServiceTest extends TestCase {
	private const SUBJECT = '10000000-0000-4000-8000-000000000001';
	private const CUSTOMER = '20000000-0000-4000-8000-000000000002';
	private const OTHER = '20000000-0000-4000-8000-00000000dead';
	private const INVOICE = '30000000-0000-4000-8000-000000000003';

	/**
	 * The OpenRegister fake.
	 *
	 * @var DeclineObjectServiceFake
	 */
	private DeclineObjectServiceFake $os;

	protected function setUp(): void {
		parent::setUp();

		$this->os = new DeclineObjectServiceFake();
		$this->os->data['portaliq']['portalAccount']['acc-1'] = [
			'subjectRef' => self::SUBJECT,
			'audience' => 'parent',
			'claims' => ['shillinq' => ['customerMasterId' => self::CUSTOMER]],
		];
		$this->os->data['shillinq']['ARInvoice'][self::INVOICE] = $this->invoice();
		$this->os->data['shillinq']['PaymentRequest'] = [
			'pr-pending' => ['invoiceReference' => self::INVOICE, 'state' => 'pending', 'amount' => 60.0],
			'pr-failed' => ['invoiceReference' => self::INVOICE, 'state' => 'failed', 'amount' => 60.0],
			'pr-other' => ['invoiceReference' => 'another-invoice', 'state' => 'pending', 'amount' => 35.0],
		];
	}//end setUp()

	/**
	 * An overdue voluntary contribution owned by the guardian's customer.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The invoice.
	 */
	private function invoice(array $overrides = []): array {
		return array_replace_recursive(
			[
				'invoiceNumber' => 'CTB-2026-DEMO0001-0001',
				'customerId' => self::CUSTOMER,
				'lifecycleState' => 'overdue',
				'grossAmount' => 60.0,
				'contribution' => ['kind' => 'parental-contribution', 'voluntary' => true, 'language' => 'nl'],
			],
			$overrides
		);
	}//end invoice()

	/**
	 * The service wired to the fake.
	 *
	 * @return ContributionDeclineService
	 */
	private function service(): ContributionDeclineService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->os);

		return new ContributionDeclineService(container: $container, logger: new NullLogger());
	}//end service()

	/**
	 * Verified parent claims.
	 *
	 * @param array<string, mixed> $overrides Claim overrides.
	 *
	 * @return array<string, mixed> The claims.
	 */
	private function claims(array $overrides = []): array {
		return array_merge(['sub' => self::SUBJECT, 'audience' => 'parent', 'trust' => 'low'], $overrides);
	}//end claims()

	/**
	 * The guardian's own overdue voluntary contribution becomes declined with
	 * the moment stamped, its pending request is voided and the others are
	 * left alone; a second call answers the same and writes nothing
	 * (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testAGuardianDeclinesTheirOwnVoluntaryContribution(): void {
		$now = new DateTimeImmutable('2026-11-12T09:30:00+00:00');

		$result = $this->service()->decline(claims: $this->claims(), target: self::INVOICE, now: $now);

		self::assertSame(ContributionDeclineService::DECLINED, $result);
		$invoice = $this->os->data['shillinq']['ARInvoice'][self::INVOICE];
		self::assertSame('declined', $invoice['lifecycleState']);
		self::assertSame('2026-11-12T09:30:00+00:00', $invoice['contribution']['declinedAt']);
		self::assertTrue($invoice['contribution']['voluntary']);
		self::assertArrayNotHasKey('id', $invoice);
		self::assertSame('voided', $this->os->data['shillinq']['PaymentRequest']['pr-pending']['state']);
		self::assertSame(['invoiceReference' => self::INVOICE, 'state' => 'voided', 'amount' => 60.0], $this->os->data['shillinq']['PaymentRequest']['pr-pending']);
		self::assertSame('failed', $this->os->data['shillinq']['PaymentRequest']['pr-failed']['state']);
		self::assertSame('pending', $this->os->data['shillinq']['PaymentRequest']['pr-other']['state']);
		self::assertCount(2, $this->os->saved);
		foreach ($this->os->saved as $save) {
			self::assertFalse($save['rbac'], 'a portal write must not depend on a Nextcloud user');
		}

		$again = $this->service()->decline(claims: $this->claims(), target: self::INVOICE, now: $now->modify('+1 day'));
		self::assertSame(ContributionDeclineService::DECLINED, $again);
		self::assertCount(2, $this->os->saved);
		self::assertSame('2026-11-12T09:30:00+00:00', $this->os->data['shillinq']['ARInvoice'][self::INVOICE]['contribution']['declinedAt']);
	}//end testAGuardianDeclinesTheirOwnVoluntaryContribution()

	/**
	 * A compulsory, a foreign, a paid and a missing invoice, a URL-shaped id,
	 * a supplier and a subject without the claim all get the same forbidden
	 * answer, and nothing is saved (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testEverythingButAnOpenOwnVoluntaryContributionIsForbidden(): void {
		$this->os->data['shillinq']['ARInvoice']['inv-compulsory'] = $this->invoice(['contribution' => ['voluntary' => false]]);
		$this->os->data['shillinq']['ARInvoice']['inv-foreign'] = $this->invoice(['customerId' => self::OTHER]);
		$this->os->data['shillinq']['ARInvoice']['inv-paid'] = $this->invoice(['lifecycleState' => 'paid']);
		$this->os->data['shillinq']['ARInvoice']['inv-plain'] = ['customerId' => self::CUSTOMER, 'lifecycleState' => 'issued'];

		$cases = [
			['inv-compulsory', $this->claims()],
			['inv-foreign', $this->claims()],
			['inv-paid', $this->claims()],
			['inv-plain', $this->claims()],
			['inv-missing', $this->claims()],
			['https://attacker.example/x', $this->claims()],
			['../' . self::INVOICE, $this->claims()],
			['', $this->claims()],
			[self::INVOICE, $this->claims(['audience' => 'supplier'])],
			[self::INVOICE, $this->claims(['sub' => '10000000-0000-4000-8000-00000000beef'])],
		];
		foreach ($cases as [$target, $claims]) {
			self::assertSame(
				ContributionDeclineService::FORBIDDEN,
				$this->service()->decline(claims: $claims, target: $target),
				'not forbidden: ' . $target . ' as ' . $claims['audience']
			);
		}

		self::assertSame([], $this->os->saved);
	}//end testEverythingButAnOpenOwnVoluntaryContributionIsForbidden()

	/**
	 * An issued contribution declines as well, and a customer-audience debtor
	 * may decline their own voluntary contribution (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testAnIssuedContributionDeclinesForACustomerToo(): void {
		$this->os->data['portaliq']['portalAccount']['acc-1']['audience'] = 'customer';
		$this->os->data['shillinq']['ARInvoice'][self::INVOICE]['lifecycleState'] = 'issued';

		$result = $this->service()->decline(claims: $this->claims(['audience' => 'customer']), target: self::INVOICE);

		self::assertSame(ContributionDeclineService::DECLINED, $result);
		self::assertSame('declined', $this->os->data['shillinq']['ARInvoice'][self::INVOICE]['lifecycleState']);
	}//end testAnIssuedContributionDeclinesForACustomerToo()

	/**
	 * When OpenRegister is down the answer is a downstream error, never a
	 * decline and never a forbidden that hides the outage (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testAnOutageIsADownstreamError(): void {
		$this->os->down = true;
		self::assertSame(ContributionDeclineService::DOWNSTREAM_ERROR, $this->service()->decline(claims: $this->claims(), target: self::INVOICE));

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$service = new ContributionDeclineService(container: $container, logger: new NullLogger());
		self::assertSame(ContributionDeclineService::DOWNSTREAM_ERROR, $service->decline(claims: $this->claims(), target: self::INVOICE));
	}//end testAnOutageIsADownstreamError()
}//end class
