<?php

/**
 * Golden test for the three server-write-grant lifecycle guards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PaymentRunReconcileGuard;
use OCA\Shillinq\Lifecycle\PurchaseOrderApprovalGuard;
use OCA\Shillinq\Lifecycle\SupplierInvoiceExceptionApprovalGuard;
use OCA\Shillinq\Service\ServerWriteAuthority;
use PHPUnit\Framework\TestCase;

/**
 * Pins every guard's decision and message per object, with no grant, the
 * guard's own grant on the object, another object's grant and another
 * guard's grant.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ServerWriteGrantGuardsGoldenTest extends TestCase {

	/**
	 * Every decision matches the golden fixture.
	 *
	 * @return void
	 */
	public function testDecisionsMatchGolden(): void {
		$approved = ['decision' => 'approved', 'decidedAt' => '2026-01-01'];
		$objects = [
			'byId' => ['id' => 'obj-1', 'approvalChain' => [$approved]],
			'bySelf' => ['@self' => ['id' => 'obj-1'], 'approvalChain' => [$approved, $approved]],
			'idWins' => ['id' => 'obj-2', '@self' => ['id' => 'obj-1'], 'approvalChain' => [$approved]],
			'noId' => ['approvalChain' => [$approved]],
			'emptyChain' => ['id' => 'obj-1', 'approvalChain' => []],
			'noChain' => ['id' => 'obj-1'],
			'pendingEntry' => ['id' => 'obj-1', 'approvalChain' => [$approved, ['decision' => 'pending']]],
			'undatedEntry' => ['id' => 'obj-1', 'approvalChain' => [['decision' => 'approved', 'decidedAt' => ' ']]],
			'scalarChain' => ['id' => 'obj-1', 'approvalChain' => 'approved'],
		];
		$guards = [
			'reconcile' => PaymentRunReconcileGuard::class,
			'exception' => SupplierInvoiceExceptionApprovalGuard::class,
			'purchaseOrder' => PurchaseOrderApprovalGuard::class,
		];
		$result = [];
		foreach ($guards as $guardName => $guardClass) {
			$grants = [
				'none' => null,
				'own' => [$guardClass::PURPOSE, 'obj-1'],
				'otherObject' => [$guardClass::PURPOSE, 'obj-9'],
				'otherPurpose' => ['some-other-purpose', 'obj-1'],
				'emptyId' => [$guardClass::PURPOSE, ''],
			];
			foreach ($grants as $grantName => $grant) {
				foreach ($objects as $objectName => $object) {
					$result[$guardName][$grantName][$objectName] = $this->decide(guardClass: $guardClass, grant: $grant, object: $object);
				}
			}
		}

		$file = __DIR__.'/fixtures/server-write-grant-guards.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('GRANT_GUARDS_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDecisionsMatchGolden()

	/**
	 * One guard decision, optionally under a grant.
	 *
	 * @param string     $guardClass The guard class.
	 * @param array|null $grant      [purpose, objectId] or null.
	 * @param array      $object     The object.
	 *
	 * @return array{allowed: bool, message: ?string}
	 */
	private function decide(string $guardClass, ?array $grant, array $object): array {
		$authority = new ServerWriteAuthority();
		$guard = new $guardClass($authority);
		$check = static fn () => $guard->check($object, 'transition', 'user-1');
		$decision = $check();
		if ($grant !== null) {
			$decision = $authority->during($grant[0], $grant[1], $check);
		}

		return ['allowed' => $decision->isAllowed(), 'message' => $decision->getMessage()];

	}//end decide()
}//end class
