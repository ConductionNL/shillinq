<?php

/**
 * Unit tests for SelfBilledInvoiceReview and SelfBillingAgreementGuard (REQ-SEIX-007).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Lifecycle\SelfBillingAgreementGuard;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceReview;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the issue guard (a current agreement issues, an expired, absent or
 * rejected one refuses with the reason, an ordinary invoice passes) and the
 * rejection (the reason is kept and the invoice stays an unbooked draft; another
 * administration's invoice reads as not found).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SelfBilledInvoiceReviewTest extends TestCase {
	/**
	 * Schema => rows, the register behind the review.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * Seed the self-billed draft SB-2026-00042 for De Kuil, with an agreement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'CustomerMaster' => [
				[
					'id' => 'cm-dekuil',
					'customerId' => 'DEB-0042',
					'administrationId' => 'adm-jansen',
					'selfBillingAgreement' => ['agreedOn' => '2026-01-05', 'validUntil' => '2026-12-31'],
				],
			],
			'ARInvoice' => [self::draft()],
		];
	}//end setUp()

	/**
	 * The self-billed draft.
	 *
	 * @return array<string,mixed>
	 */
	private static function draft(): array {
		return [
			'id' => 'inv-sb',
			'invoiceNumber' => 'SB-2026-00042',
			'administrationId' => 'adm-jansen',
			'customerId' => 'DEB-0042',
			'invoiceDate' => '2026-09-21',
			'grossAmount' => 6540.0,
			'lifecycleState' => 'draft',
			'selfBilled' => true,
			'selfBillingMention' => 'Factuur uitgereikt door afnemer',
			'selfBilling' => ['buyerInvoiceNumber' => 'SB-2026-00042', 'senderPeppolId' => '9944:nl000099998b57'],
		];
	}//end draft()

	/**
	 * The review over the in-memory register; the user may reach adm-jansen only.
	 *
	 * @return SelfBilledInvoiceReview
	 */
	private function review(): SelfBilledInvoiceReview {
		$store = new class($this->rows) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string,array<int,array<string,mixed>>> $rows Rows by schema, by reference.
			 */
			public function __construct(
				private array &$rows,
			) {
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				unset($register);
				return $this;
			}

			/**
			 * Entity rows, as OpenRegister's findAll() answers them.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,ObjectEntity>
			 */
			public function findAll(array $params = []): array {
				$found = [];
				foreach (($this->rows[$this->schema] ?? []) as $row) {
					foreach (($params['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$entity = new ObjectEntity();
					$entity->setObject($row);
					$found[] = $entity;
				}

				return $found;
			}

			/**
			 * Upsert by id.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return ObjectEntity
			 */
			public function saveObject(array $object): ObjectEntity {
				foreach (($this->rows[$this->schema] ?? []) as $index => $row) {
					if (($row['id'] ?? null) === ($object['id'] ?? null)) {
						$this->rows[$this->schema][$index] = $object;
					}
				}

				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		};

		$context = $this->createStub(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-jansen');

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new SelfBilledInvoiceReview(
			objectService: new DuckObjectServiceAdapter($store),
			administrationContext: $context,
			appConfig: $appConfig,
		);
	}//end review()

	/**
	 * The guard's refusal message for an invoice, or null when it issues.
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return string|null
	 */
	private function refusal(array $invoice): ?string {
		$guard = new SelfBillingAgreementGuard(review: $this->review());
		try {
			$returned = $guard->execute(objectData: $invoice, previousData: [], parameters: [], actionName: 'issue');
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		self::assertSame($invoice, $returned, 'the guard changes nothing');
		return null;
	}//end refusal()

	/**
	 * REQ-SEIX-007 scenario: the bookkeeper issues a self-billed invoice its agreement covers.
	 *
	 * @return void
	 */
	public function testACurrentAgreementLetsTheInvoiceBeIssued(): void {
		self::assertNull($this->refusal(invoice: self::draft()));
	}//end testACurrentAgreementLetsTheInvoiceBeIssued()

	/**
	 * An expired, absent or unmatched agreement, and a rejected invoice, refuse with the reason.
	 *
	 * @return void
	 */
	public function testTheIssueRefusals(): void {
		$this->rows['CustomerMaster'][0]['selfBillingAgreement']['validUntil'] = '2026-06-30';
		self::assertSame(
			'Self-billed invoice SB-2026-00042 cannot be issued: The self-billing agreement does not cover the invoice date.',
			$this->refusal(invoice: self::draft())
		);

		$this->setUp();
		unset($this->rows['CustomerMaster'][0]['selfBillingAgreement']);
		self::assertSame(
			'Self-billed invoice SB-2026-00042 cannot be issued: No self-billing agreement is recorded for this customer.',
			$this->refusal(invoice: self::draft())
		);

		$this->setUp();
		$this->rows['CustomerMaster'][0]['administrationId'] = 'adm-other';
		self::assertSame(
			'Self-billed invoice SB-2026-00042 cannot be issued: No customer with this VAT id, Peppol id or KvK number.',
			$this->refusal(invoice: self::draft())
		);

		$this->setUp();
		$rejected = self::draft();
		$rejected['selfBilling']['rejectionReason'] = 'Hoeveelheid klopt niet';
		self::assertSame(
			'Self-billed invoice SB-2026-00042 was rejected and cannot be issued: Hoeveelheid klopt niet',
			$this->refusal(invoice: $rejected)
		);
	}//end testTheIssueRefusals()

	/**
	 * An ordinary invoice is not the guard's business, agreement or not.
	 *
	 * @return void
	 */
	public function testAnOrdinaryInvoiceIsNotChecked(): void {
		$this->rows['CustomerMaster'] = [];
		$ordinary = self::draft();
		unset($ordinary['selfBilled']);
		self::assertNull($this->refusal(invoice: $ordinary));

		$ordinary['selfBilled'] = 'true';
		self::assertNull($this->refusal(invoice: $ordinary), 'only a real boolean marks a self-billed invoice');
	}//end testAnOrdinaryInvoiceIsNotChecked()

	/**
	 * A rejection keeps the reason and leaves the invoice an unbooked draft.
	 *
	 * @return void
	 */
	public function testARejectionKeepsTheReasonAndLeavesTheInvoiceUnbooked(): void {
		$invoice = $this->review()->reject(administrationId: 'adm-jansen', invoiceNumber: 'SB-2026-00042', reason: '  Hoeveelheid klopt niet  ');

		self::assertSame('Hoeveelheid klopt niet', $invoice['selfBilling']['rejectionReason']);
		$stored = $this->rows['ARInvoice'][0];
		self::assertSame('draft', $stored['lifecycleState']);
		self::assertArrayNotHasKey('glTransactionId', $stored);
		self::assertSame(
			['buyerInvoiceNumber' => 'SB-2026-00042', 'senderPeppolId' => '9944:nl000099998b57', 'rejectionReason' => 'Hoeveelheid klopt niet'],
			$stored['selfBilling']
		);
		self::assertCount(1, $this->rows['ARInvoice']);
	}//end testARejectionKeepsTheReasonAndLeavesTheInvoiceUnbooked()

	/**
	 * What a rejection refuses, with nothing written.
	 *
	 * @return void
	 */
	public function testTheRejectionRefusals(): void {
		$issued = self::draft();
		$issued['id'] = 'inv-issued';
		$issued['invoiceNumber'] = '2026-0420';
		$issued['lifecycleState'] = 'issued';
		$this->rows['ARInvoice'][] = $issued;
		// The same draft in an administration this user cannot reach.
		$elsewhere = self::draft();
		$elsewhere['id'] = 'inv-elsewhere';
		$elsewhere['administrationId'] = 'adm-other';
		$this->rows['ARInvoice'][] = $elsewhere;
		$before = $this->rows['ARInvoice'];

		$cases = [
			['adm-other', 'SB-2026-00042', 'x', 'ARInvoice not found'],
			['adm-jansen', 'SB-2026-99999', 'x', 'ARInvoice not found'],
			['adm-jansen', '2026-0420', 'x', 'Only a draft self-billed invoice can be rejected'],
			['adm-jansen', 'SB-2026-00042', '   ', 'A reason is required to reject a self-billed invoice'],
			['adm-jansen', 'SB-2026-00042', str_repeat('a', 501), 'The reason is longer than 500 characters'],
		];
		foreach ($cases as [$administrationId, $number, $reason, $message]) {
			try {
				$this->review()->reject(administrationId: $administrationId, invoiceNumber: $number, reason: $reason);
				self::fail($message . ': no refusal');
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

		self::assertSame($before, $this->rows['ARInvoice']);
	}//end testTheRejectionRefusals()
}//end class
