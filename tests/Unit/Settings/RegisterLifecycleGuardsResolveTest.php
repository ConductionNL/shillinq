<?php

/**
 * Regression guard for shillinq#425 — every FQCN referenced from an
 * `x-openregister-lifecycle` transition's `requires` clause must resolve.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * shillinq#425 was 17 guard classes (+1 missing method) referenced from
 * `x-openregister-lifecycle.transitions.<action>.requires` that did not
 * exist, so every one of those transitions threw a RuntimeException at
 * runtime (OpenRegister's LifecycleGuardRegistry::resolve() is not caught by
 * LifecycleValidationListener::handle()).
 *
 * The investigation also found a SECOND half of the same bug: OpenRegister's
 * container only resolves a `requires` tag if it is either (a) a literal,
 * instantiable class name, or (b) explicitly registered as a service alias.
 * A tag containing `::` (the fleet-wide "Class::method" convention used
 * throughout this app's register.d) can NEVER satisfy (a) — PHP class names
 * cannot contain `::` — so every such guard MUST be registered explicitly in
 * Application.php. This test enforces BOTH halves for every FQCN-shaped
 * `requires` value: the class (and, for `Class::method` tags, a genuine
 * public method matching the adapter's expected signature) must exist, AND
 * Application.php must register that exact literal tag string.
 *
 * Scope: only `requires` values that look like a namespaced FQCN (contain a
 * `\`) are checked. Several other pre-existing `requires` shapes in this
 * codebase are NOT FQCNs at all (a bare field name, a boolean expression, or
 * the `x-openregister-approval` sentinel) — those are separate, already
 * pre-existing defects (see shillinq#433/#435 filed alongside this change)
 * and intentionally out of scope for this regression guard, which targets
 * exactly the bug class shillinq#425 describes.
 */
final class RegisterLifecycleGuardsResolveTest extends TestCase {

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Absolute path to the register.d fragment directory.
	 *
	 * @var string
	 */
	private string $fragmentDir = __DIR__ . '/../../../lib/Settings/register.d';

	/**
	 * Absolute path to Application.php, scanned as plain text for literal
	 * `registerService()` tag registrations.
	 *
	 * @var string
	 */
	private string $applicationPath = __DIR__ . '/../../../lib/AppInfo/Application.php';

	/**
	 * Collect every `requires` value nested under an
	 * `x-openregister-lifecycle.transitions.*` node, across the monolith and
	 * every register.d fragment.
	 *
	 * @return array<int, array{0: string, 1: string}> Pairs of [sourceFile, requiresValue].
	 */
	private function collectLifecycleRequiresValues(): array {
		$files = [$this->registerPath];
		foreach (glob($this->fragmentDir . '/*.json') as $fragment) {
			$files[] = $fragment;
		}

		$found = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($data, "Fixture must be valid JSON: {$file}");
			$this->walkForLifecycleRequires(node: $data, file: basename($file), found: $found);
		}

		return $found;
	}//end collectLifecycleRequiresValues()

	/**
	 * Recursively walk a decoded JSON tree; whenever an
	 * `x-openregister-lifecycle` node with a `transitions` map is found,
	 * collect every transition's non-empty string `requires` value.
	 *
	 * @param mixed $node Current JSON subtree.
	 * @param string $file Source file basename (for failure messages).
	 * @param array<int, array{0:string,1:string}> $found Accumulator (by reference).
	 *
	 * @return void
	 */
	private function walkForLifecycleRequires(mixed $node, string $file, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		if (isset($node['x-openregister-lifecycle']['transitions']) === true
			&& is_array($node['x-openregister-lifecycle']['transitions']) === true
		) {
			foreach ($node['x-openregister-lifecycle']['transitions'] as $spec) {
				if (is_array($spec) === false) {
					continue;
				}

				$requires = ($spec['requires'] ?? null);
				if (is_string($requires) === true && $requires !== '') {
					$found[] = [$file, $requires];
				}
			}
		}

		foreach ($node as $value) {
			$this->walkForLifecycleRequires(node: $value, file: $file, found: $found);
		}

	}//end walkForLifecycleRequires()

	/**
	 * The exact 16 tags shillinq#425 covers (15 new classes/methods +
	 * PeriodCloseGuard::trialBalanceVerifies) — the ones THIS change makes
	 * genuinely resolvable end-to-end (class + method + DI registration).
	 *
	 * @return array<int, string>
	 */
	private function fixedTags(): array {
		return [
			'OCA\Shillinq\Guard\Iv3XmlValidationGuard::requireValidXml',
			'OCA\Shillinq\Guard\Iv3SubmissionGuard::requireApproval',
			'OCA\Shillinq\Guard\KorLockoutGuard::requireLockoutExpired',
			'OCA\Shillinq\Guard\ProjectActivationGuard::requireStartDate',
			'OCA\Shillinq\Guard\ProjectTransitionGuard::requireReason',
			'OCA\Shillinq\Guard\ProjectCloseGuard::requireWipJustificationOrZero',
			'OCA\Shillinq\Lifecycle\FiscalYearGuard::requireAllPeriodsClosedForYear',
			'OCA\Shillinq\Lifecycle\GLReversalGuard::isReversed',
			'OCA\Shillinq\Lifecycle\WriteOffReasonGuard::requireReason',
			'OCA\Shillinq\Guard\VatSubmissionGuard::requireApproval',
			'OCA\Shillinq\Guard\BcfSubmissionGuard::requireApproval',
			'OCA\Shillinq\Lifecycle\APGuard::isInvoiceNumberUnique',
			'OCA\Shillinq\Lifecycle\APGuard::requireWriteOffReason',
			'OCA\Shillinq\Lifecycle\WBSOExportValidationGuard',
			'OCA\Shillinq\Guard\SubsidieRepaymentGuard::requireZeroRepaymentBalance',
			'OCA\Shillinq\Guard\RateScheduleOverlapGuard::requireNonOverlappingWindow',
			'OCA\Shillinq\Lifecycle\PeriodCloseGuard::trialBalanceVerifies',
		];

	}//end fixedTags()

	/**
	 * Every `requires` value shaped like a namespaced FQCN (optionally with
	 * a `::method` suffix) must at minimum resolve to a real class (+ real
	 * method, when a `::method` suffix is present) — this alone is the
	 * shillinq#425 regression guard for "17 classes referenced from
	 * register.d do not exist".
	 *
	 * Additionally, for the exact 16 tags this specific change fixes
	 * end-to-end (see fixedTags()), Application.php must also
	 * `registerService()` that EXACT literal tag string — the only way
	 * OpenRegister's container can ever produce an instance for a tag
	 * containing `::`. This second, stricter check is intentionally scoped
	 * to just those 16: dozens of OTHER pre-existing guards in this app
	 * (e.g. OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced) share the same
	 * class-exists-but-never-registered gap and are tracked separately as
	 * shillinq#433 — fixing all of them is out of scope here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-4
	 */
	public function testEveryFqcnShapedLifecycleRequiresResolves(): void {
		$applicationSource = (string)file_get_contents($this->applicationPath);
		$pairs = $this->collectLifecycleRequiresValues();
		$this->assertNotEmpty($pairs, 'Expected to find at least one x-openregister-lifecycle transition.');

		$fixedTags = $this->fixedTags();
		$checked = 0;
		$fixedSeen = [];
		foreach ($pairs as [$file, $requires]) {
			if (str_contains($requires, '\\') === false) {
				// Not FQCN-shaped (bare field name, boolean expression, or a
				// sentinel like x-openregister-approval) — out of scope for
				// this regression guard; see class docblock.
				continue;
			}

			$checked++;

			if (str_contains($requires, '::') === true) {
				[$class, $method] = explode('::', $requires, 2);
			} else {
				$class = $requires;
				$method = null;
			}

			$this->assertTrue(
				class_exists($class),
				"requires \"{$requires}\" in {$file}: class {$class} does not exist."
			);

			if ($method !== null) {
				$this->assertTrue(
					method_exists($class, $method),
					"requires \"{$requires}\" in {$file}: method {$method} does not exist on {$class}."
				);
			}

			if (in_array($requires, $fixedTags, true) === true) {
				$fixedSeen[$requires] = true;
				$this->assertStringContainsString(
					$requires,
					$applicationSource,
					"requires \"{$requires}\" in {$file}: Application.php does not registerService() this exact "
					. "literal tag, so OpenRegister's container can never resolve it (a tag containing \"::\" cannot "
					. 'autowire — see RegisterRequiresGuardAdapter.php docblock).'
				);
			}
		}

		// Sanity: this test must actually have exercised something, or a
		// change to the FQCN-detection heuristic could silently stop
		// checking anything at all.
		$this->assertGreaterThanOrEqual(16, $checked, 'Expected at least the 16 shillinq#425 tags to be checked.');

		foreach ($fixedTags as $tag) {
			$this->assertArrayHasKey(
				$tag,
				$fixedSeen,
				"Expected to find requires=\"{$tag}\" declared somewhere in register.d/shillinq_register.json "
				. '— did a register.d edit accidentally drop it?'
			);
		}

	}//end testEveryFqcnShapedLifecycleRequiresResolves()
}//end class
