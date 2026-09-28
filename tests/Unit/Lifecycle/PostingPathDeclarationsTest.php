<?php

/**
 * Walks the merged register and checks the posting path resolves end to end.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\AppInfo\LedgerPostingRegistration;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * OpenRegister resolves a declared lifecycle action by looking the name up in
 * its own container and then in the server container, and the server
 * container only reaches an app container for an `OCA\<App>\...` name. A
 * kebab-case name such as `materialise-gl-transaction` can therefore never
 * reach a handler shillinq registers, and every transition declaring one
 * aborts. The same holds for a `Class::method` `requires` tag nobody
 * registered. A unit test on the handler or guard class cannot see either,
 * because it never goes through the resolution; this test walks the merged
 * register the way SettingsService imports it instead (#516, #1103).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PostingPathDeclarationsTest extends TestCase {

	/**
	 * The posting action names OpenRegister cannot resolve for shillinq.
	 *
	 * @var list<string>
	 */
	private const UNRESOLVABLE_POSTING_NAMES = [
		'materialise-gl-transaction',
		'evaluate-allocation-rules',
	];

	/**
	 * The transitions that post to the ledger, whose guard must resolve.
	 *
	 * @var list<string>
	 */
	private const POSTING_TRANSITIONS = [
		'GLTransaction.post',
		'JournalEntry.post',
		'JournalEntry.postDirect',
		'APInvoice.post',
		'ARInvoice.issue',
		'APTransaction.issue',
	];

	/**
	 * Captured registerService() factories, keyed by service name.
	 *
	 * @var array<string, callable>
	 */
	private array $services = [];

	/**
	 * The register as SettingsService imports it: base file, then every
	 * register.d fragment in sorted order, through the real deepMergeConfig().
	 *
	 * @return array<string, mixed>
	 */
	private function effectiveRegister(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$merged = json_decode((string)file_get_contents($root . '/shillinq_register.json'), true);
		self::assertIsArray($merged);

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($root . '/register.d/*.json');
		self::assertNotEmpty($fragments);
		sort($fragments);
		foreach ($fragments as $fragment) {
			$data = json_decode((string)file_get_contents($fragment), true);
			self::assertIsArray($data, $fragment);
			$merged = $merge->invoke(null, $merged, $data);
		}

		return $merged;
	}//end effectiveRegister()

	/**
	 * Every transition of every schema, keyed `Schema.transition`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function transitions(): array {
		$out = [];
		foreach (($this->effectiveRegister()['components']['schemas'] ?? []) as $schema => $definition) {
			$lifecycle = ($definition['x-openregister-lifecycle'] ?? ($definition['configuration']['x-openregister-lifecycle'] ?? null));
			if (is_array($lifecycle) === false) {
				continue;
			}

			foreach (($lifecycle['transitions'] ?? []) as $name => $transition) {
				if (is_array($transition) === true) {
					$out[$schema . '.' . $name] = $transition;
				}
			}
		}

		return $out;
	}//end transitions()

	/**
	 * The declared action entries of one transition.
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function actions(array $transition): array {
		$out = [];
		foreach (($transition['actions'] ?? []) as $action) {
			if (is_array($action) === true) {
				$out[] = $action;
			}
		}

		return $out;
	}//end actions()

	/**
	 * No transition declares a posting action by a name OpenRegister cannot
	 * resolve to shillinq's handler.
	 *
	 * @return void
	 */
	public function testNoTransitionDeclaresAPostingActionByAnUnresolvableName(): void {
		$offenders = [];
		foreach ($this->transitions() as $key => $transition) {
			foreach ($this->actions($transition) as $action) {
				if (in_array(($action['action'] ?? ''), self::UNRESOLVABLE_POSTING_NAMES, true) === true) {
					$offenders[] = $key . ' -> ' . $action['action'];
				}
			}
		}

		self::assertSame([], $offenders, 'These transitions abort with "declared but no handler is registered".');
	}//end testNoTransitionDeclaresAPostingActionByAnUnresolvableName()

	/**
	 * Every action named by a shillinq FQCN is a class implementing the
	 * action interface, and every posting declaration names a source schema.
	 *
	 * @return void
	 */
	public function testEveryShillinqActionDeclarationNamesARealHandler(): void {
		$seen = 0;
		foreach ($this->transitions() as $key => $transition) {
			foreach ($this->actions($transition) as $action) {
				$name = (string)($action['action'] ?? '');
				if (str_starts_with($name, 'OCA\\Shillinq\\') === false) {
					continue;
				}

				$seen++;
				self::assertTrue(class_exists($name), $key . ' names ' . $name . ', which does not exist.');
				self::assertTrue(
					is_subclass_of($name, LifecycleActionInterface::class),
					$key . ' names ' . $name . ', which does not implement LifecycleActionInterface.'
				);

				if ($name === MaterialiseGlTransactionAction::class) {
					self::assertNotSame(
						'',
						(string)($action['actionParameters']['sourceSchema'] ?? ''),
						$key . ' declares the posting action without a sourceSchema.'
					);
				}
			}
		}

		self::assertGreaterThan(1, $seen);
	}//end testEveryShillinqActionDeclarationNamesARealHandler()

	/**
	 * Issuing a sales invoice books it (REQ-LPP-007).
	 *
	 * @return void
	 */
	public function testArInvoiceIssueDeclaresThePostingAction(): void {
		$issue = ($this->transitions()['ARInvoice.issue'] ?? []);
		$found = false;
		foreach ($this->actions($issue) as $action) {
			if (($action['action'] ?? '') === MaterialiseGlTransactionAction::class
				&& ($action['actionParameters']['sourceSchema'] ?? '') === 'ARInvoice'
			) {
				$found = true;
			}
		}

		self::assertTrue($found, 'ARInvoice.issue must declare the posting action with sourceSchema ARInvoice.');
	}//end testArInvoiceIssueDeclaresThePostingAction()

	/**
	 * StockMove.post is already booked by CogsPosterService and payroll runs
	 * in humaniq, so neither may declare a second posting (REQ-LPP-006).
	 *
	 * @return void
	 */
	public function testStockMoveAndPayrollDoNotDeclareASecondPosting(): void {
		$transitions = $this->transitions();
		foreach (['StockMove.post', 'Payroll.issue'] as $key) {
			foreach ($this->actions(($transitions[$key] ?? [])) as $action) {
				$name = (string)($action['action'] ?? '');
				self::assertNotContains($name, ['materialise-gl-transaction', MaterialiseGlTransactionAction::class], $key);
			}
		}
	}//end testStockMoveAndPayrollDoNotDeclareASecondPosting()

	/**
	 * A registration context that records registerService() calls.
	 *
	 * @return IRegistrationContext
	 */
	private function recordingContext(): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory, bool $shared = true): void {
				$this->services[$name] = $factory;
			}
		);

		return $context;
	}//end recordingContext()

	/**
	 * The `requires` tag of every posting transition is registered, and the
	 * adapter it builds calls a guard method that takes the object array the
	 * adapter hands it (a string-id method would TypeError and deny).
	 *
	 * @return void
	 */
	public function testEveryPostingGuardTagIsRegisteredWithAnArrayShapedMethod(): void {
		(new LedgerPostingRegistration())->register($this->recordingContext());

		$transitions = $this->transitions();
		foreach (self::POSTING_TRANSITIONS as $key) {
			$tag = (string)($transitions[$key]['requires'] ?? '');
			self::assertNotSame('', $tag, $key . ' declares no requires guard.');
			self::assertArrayHasKey($tag, $this->services, $key . ' requires ' . $tag . ', which nothing registers.');

			[$class, $method] = explode('::', $tag, 2);

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				fn (string $id) => match ($id) {
					$class => (new ReflectionClass($class))->newInstanceWithoutConstructor(),
					LoggerInterface::class => $this->createMock(LoggerInterface::class),
					default => null,
				}
			);

			$adapter = ($this->services[$tag])($container);
			self::assertInstanceOf(RegisterRequiresGuardAdapter::class, $adapter, $key);

			$property = (new ReflectionClass($adapter))->getProperty('method');
			$property->setAccessible(true);
			self::assertSame($method, $property->getValue($adapter), $key . ': the adapter must call the method the tag names.');

			$parameters = (new ReflectionMethod($class, $method))->getParameters();
			self::assertNotSame([], $parameters, $tag);
			self::assertTrue($this->acceptsArray($parameters[0]->getType()), $tag . ' must accept the object array the adapter passes.');
		}//end foreach
	}//end testEveryPostingGuardTagIsRegisteredWithAnArrayShapedMethod()

	/**
	 * Whether a parameter type admits an array.
	 *
	 * @param \ReflectionType|null $type The parameter type.
	 *
	 * @return bool
	 */
	private function acceptsArray(?\ReflectionType $type): bool {
		if ($type === null) {
			return true;
		}

		if ($type instanceof ReflectionNamedType) {
			return in_array($type->getName(), ['array', 'mixed', 'iterable'], true);
		}

		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $member) {
				if ($this->acceptsArray($member) === true) {
					return true;
				}
			}
		}

		return false;
	}//end acceptsArray()
}//end class
