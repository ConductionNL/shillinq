<?php

/**
 * Unit tests for IntegriqPeppolOrderAdapter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PurchaseOrder;

use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\PurchaseOrder\IntegriqPeppolOrderAdapter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The supplier is looked up at integriq; the UBL Order is stored and integriq
 * is asked to transmit it; without integriq the adapter refuses.
 *
 * @covers \OCA\Shillinq\Service\PurchaseOrder\IntegriqPeppolOrderAdapter
 * @uses \OCA\Shillinq\Support\FleetAppId
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
 */
final class IntegriqPeppolOrderAdapterTest extends TestCase {

	/**
	 * What integriq's participant lookup answers; null for no integriq.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $lookup = ['exists' => true, 'supportedDocTypes' => []];

	/**
	 * Whether integriq's EventService is there.
	 *
	 * @var bool
	 */
	private bool $events = true;

	/**
	 * Every CloudEvent emitted, as its named arguments.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $emitted = [];

	/**
	 * Every file written, as [path, content].
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $files = [];

	/**
	 * The adapter over fakes.
	 *
	 * @param string|null $localPeppolId The supplier's Peppol id in shillinq's records.
	 * @param string|null $userId        The signed-in user.
	 *
	 * @return IntegriqPeppolOrderAdapter
	 */
	private function adapter(?string $localPeppolId = '0106:12345678', ?string $userId = 'inkoper'): IntegriqPeppolOrderAdapter {
		$directory = $this->createMock(PeppolTransmissionPortInterface::class);
		$directory->method('lookupParticipant')->willReturn($localPeppolId);

		$test = $this;
		$lookup = $this->lookup;
		$transmission = new class($lookup) {
			/**
			 * @param array<string,mixed>|null $answer The lookup answer.
			 */
			public function __construct(private ?array $answer) {
			}

			/**
			 * @param string $peppolId The id.
			 * @return array<string,mixed>
			 */
			public function lookupParticipant(string $peppolId): array {
				return ($this->answer ?? []) + ['asked' => $peppolId];
			}
		};
		$events = new class($test) {
			/**
			 * @param IntegriqPeppolOrderAdapterTest $test The test recording the events.
			 */
			public function __construct(private IntegriqPeppolOrderAdapterTest $test) {
			}

			/**
			 * @param mixed ...$args The CloudEvent.
			 * @return array<int,mixed>
			 */
			public function emitCloudEvent(mixed ...$args): array {
				$this->test->emitted[] = $args;
				return [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($transmission, $events): object {
				if ($class === 'OCA\\Integriq\\Service\\PeppolTransmissionService' && $this->lookup !== null) {
					return $transmission;
				}

				if ($class === 'OCA\\Integriq\\Service\\EventService' && $this->events === true) {
					return $events;
				}

				throw new RuntimeException('not registered: ' . $class);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		return new IntegriqPeppolOrderAdapter(
			partyDirectory: $directory,
			container: $container,
			rootFolder: $this->rootFolder(),
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end adapter()

	/**
	 * A root folder whose user folder records every file written.
	 *
	 * @return IRootFolder
	 */
	private function rootFolder(): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $uid): Folder => $this->folder(path: '/' . $uid . '/files'));

		return $root;
	}//end rootFolder()

	/**
	 * A folder that creates children on demand.
	 *
	 * @param string $path The folder's path.
	 *
	 * @return Folder
	 */
	private function folder(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(fn (string $name): Folder => $this->folder(path: $path . '/' . $name));
		$folder->method('newFile')->willReturnCallback(
			function (string $name, string $content) use ($path): File {
				$this->files[] = [$path . '/' . $name, $content];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(4711);
				return $file;
			}
		);

		return $folder;
	}//end folder()

	/**
	 * A participant that takes Orders is returned as found in shillinq's records.
	 *
	 * @return void
	 */
	public function testAParticipantTakingOrdersIsFound(): void {
		$this->lookup = ['exists' => true, 'supportedDocTypes' => ['urn:oasis:names:specification:ubl:schema:xsd:Order-2::Order##urn:fdc:peppol.eu:poacc:trns:order:3']];

		self::assertSame('0106:12345678', $this->adapter()->lookupParticipant(administrationId: 'adm-1', partyId: 'sup-1'));
	}//end testAParticipantTakingOrdersIsFound()

	/**
	 * No local Peppol id, an unknown participant and one that takes no Orders are not participants.
	 *
	 * @return void
	 */
	public function testANonParticipantIsNull(): void {
		self::assertNull($this->adapter(localPeppolId: null)->lookupParticipant(administrationId: 'adm-1', partyId: 'sup-1'));

		$this->lookup = ['exists' => false, 'supportedDocTypes' => []];
		self::assertNull($this->adapter()->lookupParticipant(administrationId: 'adm-1', partyId: 'sup-1'));

		$this->lookup = ['exists' => true, 'supportedDocTypes' => ['urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice']];
		self::assertNull($this->adapter()->lookupParticipant(administrationId: 'adm-1', partyId: 'sup-1'));
	}//end testANonParticipantIsNull()

	/**
	 * Without integriq the lookup says Peppol is not configured.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheLookupRefuses(): void {
		$this->lookup = null;
		$this->expectExceptionObject(new RuntimeException('Peppol sending is not configured'));

		$this->adapter()->lookupParticipant(administrationId: 'adm-1', partyId: 'sup-1');
	}//end testWithoutIntegriqTheLookupRefuses()

	/**
	 * The Order is stored in the buyer's files and integriq is asked to send it, naming the order.
	 *
	 * @return void
	 */
	public function testSubmitOrderStoresTheOrderAndAsksIntegriq(): void {
		$uri = $this->adapter()->submitOrder(
			participantId: '0106:12345678',
			ublOrderXml: '<Order/>',
			purchaseOrder: ['id' => 'po-31', 'poNumber' => 'PO-2026-031', 'administrationId' => 'Gemeente Voorbeeld']
		);

		self::assertSame('openregister://shillinq/PurchaseOrder/po-31', $uri);
		self::assertSame([['/inkoper/files/Shillinq/PurchaseOrders/Gemeente-Voorbeeld/PO-2026-031.xml', '<Order/>']], $this->files);
		self::assertSame(
			[
				'type' => 'nl.conduction.peppol.outbound.requested',
				'source' => 'shillinq',
				'subject' => 'openregister://shillinq/PurchaseOrder/po-31',
				'data' => [
					'sourceApp' => 'shillinq',
					'objectType' => 'purchase-order',
					'objectUri' => 'openregister://shillinq/PurchaseOrder/po-31',
					'recipientPeppolId' => '0106:12345678',
					'documentType' => 'Order',
					'payloadFileUri' => 'nextcloud-file:4711',
				],
				'userId' => 'inkoper',
			],
			$this->emitted[0]
		);
	}//end testSubmitOrderStoresTheOrderAndAsksIntegriq()

	/**
	 * Without integriq, without an order id or without a user nothing is stored or emitted.
	 *
	 * @return void
	 */
	public function testSubmitOrderRefusesWhatItCannotSend(): void {
		$cases = [
			['Peppol sending is not configured', ['id' => 'po-31'], 'inkoper', false],
			['A purchase order without an id cannot be sent by Peppol', [], 'inkoper', true],
			['A purchase order is sent by Peppol only by a signed-in user', ['id' => 'po-31'], null, true],
		];
		foreach ($cases as [$message, $order, $userId, $events]) {
			$this->events = $events;
			try {
				$this->adapter(userId: $userId)->submitOrder(participantId: '0106:1', ublOrderXml: '<Order/>', purchaseOrder: $order);
				self::fail('sent: ' . $message);
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

		self::assertSame([], $this->files);
		self::assertSame([], $this->emitted);
	}//end testSubmitOrderRefusesWhatItCannotSend()

	/**
	 * The generic port submit asks integriq for the named document.
	 *
	 * @return void
	 */
	public function testSubmitEmitsForTheStoredDocument(): void {
		self::assertSame('nextcloud-file:9', $this->adapter()->submit(participantId: '0106:1', documentType: 'Order', payloadFileUri: 'nextcloud-file:9'));
		self::assertSame('nextcloud-file:9', $this->emitted[0]['data']['payloadFileUri']);
		self::assertSame('Order', $this->emitted[0]['data']['documentType']);
	}//end testSubmitEmitsForTheStoredDocument()
}//end class
