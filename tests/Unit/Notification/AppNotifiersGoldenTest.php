<?php

/**
 * Golden test for the deadline, e-invoice and POS stock notifiers.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Notification;

use OCA\Shillinq\Listener\PeppolDeliveryStatusListener;
use OCA\Shillinq\Listener\PosStockDecrementListener;
use OCA\Shillinq\Notification\DeadlineReminderNotifier;
use OCA\Shillinq\Notification\EInvoiceNotifier;
use OCA\Shillinq\Notification\PosStockUnmatchedLineNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use PHPUnit\Framework\TestCase;

/**
 * Pins id, name, l10n lookups and the prepared notification (or refusal).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AppNotifiersGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testNotifiersMatchGolden(): void {
		$deadline = 'deadline_reminder';
		$rejected = PeppolDeliveryStatusListener::NOTIFICATION_SUBJECT_REJECTED;
		$unmatched = PosStockDecrementListener::NOTIFICATION_SUBJECT_UNMATCHED;
		$notifiers = [
			'deadline' => [
				'build' => fn () => new DeadlineReminderNotifier($this->factory()),
				'cases' => [
					'own' => ['shillinq', $deadline, ['summary' => 'BTW Q1', 'dueDate' => '2026-04-30']],
					'ownEmpty' => ['shillinq', $deadline, []],
					'otherApp' => ['files', $deadline, []],
					'otherSubject' => ['shillinq', $rejected, []],
				],
			],
			'einvoice' => [
				'build' => fn () => new EInvoiceNotifier($this->factory(), $this->urls()),
				'cases' => [
					'withReason' => ['shillinq', $rejected, ['invoiceNumber' => 'VF-1', 'detail' => ' bad VAT ', 'invoiceId' => 'id 1/2']],
					'noReasonNoId' => ['shillinq', $rejected, ['invoiceNumber' => 'VF-2', 'detail' => '  ']],
					'otherApp' => ['files', $rejected, []],
					'otherSubject' => ['shillinq', $deadline, []],
				],
			],
			'posStock' => [
				'build' => fn () => new PosStockUnmatchedLineNotifier($this->factory()),
				'cases' => [
					'withProduct' => ['shillinq', $unmatched, ['productRef' => 'SKU-9', 'posTxnId' => 'tx-1']],
					'noProduct' => ['shillinq', $unmatched, ['posTxnId' => 'tx-2']],
					'otherApp' => ['files', $unmatched, []],
					'otherSubject' => ['shillinq', $deadline, []],
				],
			],
		];
		$result = [];
		foreach ($notifiers as $name => $spec) {
			$this->calls = [];
			$notifier = $spec['build']();
			$result[$name]['identity'] = [$notifier->getID(), $notifier->getName(), $this->calls];
			foreach ($spec['cases'] as $case => $args) {
				$this->calls = [];
				$result[$name][$case] = $this->prepare(notifier: $notifier, args: $args);
			}
		}

		$file = __DIR__.'/fixtures/app-notifiers.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('NOTIFIERS_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testNotifiersMatchGolden()

	/**
	 * Prepare one notification and record what happened.
	 *
	 * @param INotifier $notifier The notifier.
	 * @param array     $args     [app, subject, parameters].
	 *
	 * @return array
	 */
	private function prepare(INotifier $notifier, array $args): array {
		[$app, $subject, $parameters] = $args;
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject', 'setParsedMessage', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (string $value) use ($setter, $notification) {
					$this->calls[] = [$setter, $value];
					return $notification;
				}
			);
		}

		try {
			$returned = $notifier->prepare($notification, 'nl');
			return ['same' => ($returned === $notification), 'calls' => $this->calls];
		} catch (\Throwable $e) {
			return ['exception' => get_class($e), 'calls' => $this->calls];
		}

	}//end prepare()

	/**
	 * An l10n factory whose translations echo the format with its arguments.
	 *
	 * @return IFactory
	 */
	private function factory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $lang=null) {
				$this->calls[] = ['l10n', $app, $lang];
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					static fn (string $text, array $params=[]): string => '['.$lang.']'.vsprintf($text, $params)
				);
				return $l10n;
			}
		);
		return $factory;

	}//end factory()

	/**
	 * A URL generator recording the route.
	 *
	 * @return IURLGenerator
	 */
	private function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route) {
				$this->calls[] = ['route', $route];
				return 'https://cloud.example/apps/shillinq/';
			}
		);
		return $urls;

	}//end urls()
}//end class
