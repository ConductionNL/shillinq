<?php

/**
 * Composition-root argument tests.
 *
 * `lib/AppInfo/Application.php` registers a number of services with a
 * hand-written factory closure — `return new Foo(bar: $c->get(...))` —
 * instead of letting Nextcloud autowire them. That is a deliberate choice
 * (several of these need an argument the container cannot infer, or must stay
 * a string reference so a disabled OpenRegister does not fatal bootstrap), but
 * it has one dangerous property:
 *
 *   **A manual `new Foo(...)` that no longer matches `Foo::__construct()` is
 *   invisible to every static check we run.** `php -l` passes, phpcs, phpmd,
 *   psalm and phpstan pass, and the class's own unit test passes too, because
 *   the unit test constructs it directly and never goes through the factory.
 *   The first thing that notices is a **request**.
 *
 * That is exactly what happened when ADR-084 replaced
 * `DoorsnijdingsVerbodValidator`'s `$container` parameter with the published
 * `ObjectServiceInterface` contract. The factory in `Application.php` kept
 * passing `container: $c` — a parameter that no longer exists — so resolving
 * the service threw `Error: Unknown named parameter $container`, and
 * `InnovatieboxController` requires that service, so **every Innovatiebox
 * route returned 500**. `tests/Unit/Service/DoorsnijdingsVerbodValidatorTest`
 * was green throughout: it builds the validator itself, correctly.
 *
 * These tests close that hole. They read `Application.php`'s own source,
 * extract every `new <Class>(...)` in it, and compare the call against the
 * target class's real constructor via reflection.
 *
 * @category  Test
 * @package   OCA\Shillinq\Tests\Unit\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every hand-written factory in the composition root must still match the
 * constructor it calls.
 */
class CompositionRootArgumentsTest extends TestCase {

	/**
	 * Absolute path to the composition root.
	 *
	 * @var string
	 */
	private string $applicationPhp;

	/**
	 * Parsed `new <Class>(...)` call sites found in the composition root.
	 *
	 * Each entry: ['class' => FQN, 'line' => int, 'named' => string[],
	 * 'positional' => int].
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $callSites;


	/**
	 * Parse the composition root once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->applicationPhp = dirname(__DIR__, 3) . '/lib/AppInfo/Application.php';
		self::assertFileExists($this->applicationPhp, 'the composition root must exist');

		$source = file_get_contents($this->applicationPhp);
		$this->callSites = $this->parseNewCalls(
			$source,
			$this->buildUseMap($source),
			$this->currentNamespace($source)
		);

	}//end setUp()


	/**
	 * The parser must actually have found the factories.
	 *
	 * Without this, a parsing regression would make every other test in this
	 * file pass over an empty list — a check that did not run looks exactly
	 * like one that passed.
	 *
	 * @return void
	 */
	public function testTheParserFindsTheFactoriesItIsSupposedToCheck(): void {
		self::assertGreaterThanOrEqual(
			20,
			count($this->callSites),
			'Expected the composition root to contain at least 20 `new X(...)` '
			. 'call sites; found ' . count($this->callSites) . '. Either the '
			. 'factories were removed (update this floor deliberately) or this '
			. "file's parser broke and every other assertion here is vacuous."
		);

		$classes = array_column($this->callSites, 'class');
		self::assertContains(
			DoorsnijdingsVerbodValidator::class,
			$classes,
			'The DoorsnijdingsVerbodValidator factory must be among the parsed '
			. 'call sites; it is the one this test file exists for.'
		);

	}//end testTheParserFindsTheFactoriesItIsSupposedToCheck()


	/**
	 * Every manual factory supplies every argument its constructor requires.
	 *
	 * @return void
	 */
	public function testEveryManualFactorySuppliesAllRequiredConstructorArguments(): void {
		$checked  = 0;
		$problems = [];

		foreach ($this->callSites as $site) {
			if (class_exists($site['class']) === false) {
				// Classes from other apps (OpenRegister, OCP) are not
				// autoloadable in a unit-test run. Reported by
				// testUnresolvableFactoryTargetsAreDeclared() rather than
				// silently skipped here.
				continue;
			}

			$reflection  = new ReflectionClass($site['class']);
			$constructor = $reflection->getConstructor();
			if ($constructor === null) {
				continue;
			}

			$checked++;
			$parameters = $constructor->getParameters();
			$names      = array_map(
				static fn ($parameter) => $parameter->getName(),
				$parameters
			);

			foreach (array_count_values($site['named']) as $name => $times) {
				if ($times > 1) {
					$problems[] = sprintf(
						'%s:%d new %s — named argument $%s is passed %d times '
						. '(PHP fatals with "Named parameter $%s overwrites '
						. 'previous argument")',
						basename($this->applicationPhp),
						$site['line'],
						$reflection->getShortName(),
						$name,
						$times,
						$name
					);
				}

				if (in_array($name, $names, true) === false) {
					$problems[] = sprintf(
						'%s:%d new %s — named argument $%s does not exist on '
						. '%s::__construct() (it takes: %s). PHP fatals with '
						. '"Unknown named parameter $%s".',
						basename($this->applicationPhp),
						$site['line'],
						$reflection->getShortName(),
						$name,
						$reflection->getShortName(),
						implode(', ', array_map(static fn ($n) => '$' . $n, $names)),
						$name
					);
				}
			}

			foreach ($parameters as $index => $parameter) {
				if ($index < $site['positional']) {
					// Supplied positionally.
					continue;
				}

				if ($parameter->isOptional() === true || $parameter->isVariadic() === true) {
					continue;
				}

				if (in_array($parameter->getName(), $site['named'], true) === true) {
					continue;
				}

				$problems[] = sprintf(
					'%s:%d new %s — required constructor parameter $%s is not '
					. 'supplied (%d of %d arguments given). Every request that '
					. 'resolves this service will die with ArgumentCountError.',
					basename($this->applicationPhp),
					$site['line'],
					$reflection->getShortName(),
					$parameter->getName(),
					(count($site['named']) + $site['positional']),
					count($parameters)
				);
			}
		}//end foreach

		self::assertGreaterThanOrEqual(
			5,
			$checked,
			'Expected to reflect at least 5 factory targets; reflected '
			. $checked . '. A run that checks nothing must not report success.'
		);

		self::assertSame(
			[],
			$problems,
			"The composition root disagrees with a constructor it calls:\n  - "
			. implode("\n  - ", $problems)
		);

	}//end testEveryManualFactorySuppliesAllRequiredConstructorArguments()


	/**
	 * The Innovatiebox validator's factory, named explicitly.
	 *
	 * This is the regression that made every `InnovatieboxController` route a
	 * 500. It is asserted on its own so that the failure message names the
	 * service rather than a list.
	 *
	 * @return void
	 */
	public function testInnovatieboxValidatorFactoryMatchesItsConstructor(): void {
		$sites = array_values(
			array_filter(
				$this->callSites,
				static fn ($site) => $site['class'] === DoorsnijdingsVerbodValidator::class
			)
		);

		self::assertCount(
			1,
			$sites,
			'Application.php must register exactly one hand-written '
			. 'DoorsnijdingsVerbodValidator factory.'
		);

		$constructor = (new ReflectionClass(DoorsnijdingsVerbodValidator::class))->getConstructor();
		self::assertNotNull($constructor);

		$names = array_map(
			static fn ($parameter) => $parameter->getName(),
			$constructor->getParameters()
		);

		$unknown = array_values(array_diff($sites[0]['named'], $names));
		self::assertSame(
			[],
			$unknown,
			'The DoorsnijdingsVerbodValidator factory passes named argument(s) '
			. '$' . implode(', $', $unknown) . ' that its constructor does not '
			. 'have. Resolving the service throws "Unknown named parameter", '
			. 'and InnovatieboxController requires it — so every Innovatiebox '
			. 'route returns 500.'
		);

		$required = [];
		foreach ($constructor->getParameters() as $index => $parameter) {
			if ($index < $sites[0]['positional']) {
				continue;
			}

			if ($parameter->isOptional() === true || $parameter->isVariadic() === true) {
				continue;
			}

			$required[] = $parameter->getName();
		}

		$missing = array_values(array_diff($required, $sites[0]['named']));
		self::assertSame(
			[],
			$missing,
			'The DoorsnijdingsVerbodValidator factory does not pass: $'
			. implode(', $', $missing) . '. Resolving the service throws '
			. 'ArgumentCountError.'
		);

	}//end testInnovatieboxValidatorFactoryMatchesItsConstructor()


	/**
	 * Record which factory targets could not be reflected.
	 *
	 * An unloadable class is "I could not tell", not a pass. This test keeps
	 * that set visible, so a future factory for a foreign class does not
	 * quietly disappear from the check above.
	 *
	 * @return void
	 */
	public function testUnresolvableFactoryTargetsAreDeclared(): void {
		$unresolvable = [];
		foreach ($this->callSites as $site) {
			if (class_exists($site['class']) === false) {
				$unresolvable[] = $site['class'];
			}
		}

		$unresolvable = array_values(array_unique($unresolvable));
		sort($unresolvable);

		self::assertSame(
			$this->unreflectableTargets(),
			$unresolvable,
			'Composition-root factory targets that cannot be reflected in a '
			. 'unit-test run have changed. Unreflectable targets are unchecked, '
			. "not clean — add a stub, or accept the new entry deliberately.\n"
			. '  now: ' . implode(', ', $unresolvable) . "\n"
			. '  expected: ' . implode(', ', $this->unreflectableTargets())
		);

	}//end testUnresolvableFactoryTargetsAreDeclared()


	/**
	 * Factory targets that a unit-test autoloader cannot resolve.
	 *
	 * These belong to other apps or to PHP itself. Kept sorted, so the
	 * assertion above compares like with like.
	 *
	 * @return string[]
	 */
	private function unreflectableTargets(): array {
		return [];

	}//end unreflectableTargets()


	/**
	 * The namespace the composition root is declared in.
	 *
	 * An unqualified class name in a namespaced file resolves to the current
	 * namespace, not to the global one. Without this, a factory for a class
	 * that is simply a neighbour of `Application` looks unresolvable — and
	 * unresolvable reads as unchecked.
	 *
	 * @param string $source The PHP source of the composition root.
	 *
	 * @return string
	 */
	private function currentNamespace(string $source): string {
		if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $match) === 1) {
			return $match[1];
		}

		return '';

	}//end currentNamespace()


	/**
	 * Build alias => fully-qualified-class-name from the file's `use` list.
	 *
	 * @param string $source The PHP source of the composition root.
	 *
	 * @return array<string, string>
	 */
	private function buildUseMap(string $source): array {
		$map = [];
		preg_match_all(
			'/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m',
			$source,
			$matches,
			PREG_SET_ORDER
		);

		foreach ($matches as $match) {
			$fqn   = $match[1];
			$parts = explode('\\', $fqn);
			$alias = ($match[2] ?? '');
			if ($alias === '') {
				$alias = end($parts);
			}

			$map[$alias] = $fqn;
		}

		return $map;

	}//end buildUseMap()


	/**
	 * Extract every `new <Class>(...)` call site from PHP source.
	 *
	 * Uses PHP's own tokeniser, so comments and string literals cannot be
	 * mistaken for code.
	 *
	 * @param string                $source    The PHP source of the composition root.
	 * @param array<string, string> $useMap    Alias => FQN, from buildUseMap().
	 * @param string                $namespace The file's own namespace.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function parseNewCalls(string $source, array $useMap, string $namespace): array {
		$tokens = token_get_all($source);
		$sites  = [];
		$count  = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			if (is_array($tokens[$i]) === false || $tokens[$i][0] !== T_NEW) {
				continue;
			}

			$line = $tokens[$i][2];

			// The class name: skip whitespace, then take a name token.
			$j = ($i + 1);
			while ($j < $count && is_array($tokens[$j]) === true
				&& in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true
			) {
				$j++;
			}

			if ($j >= $count || is_array($tokens[$j]) === false) {
				continue;
			}

			$nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
			if (in_array($tokens[$j][0], $nameTokens, true) === false) {
				// `new class`, `new $var`, `new self`, ... — not a factory
				// for a named class.
				continue;
			}

			$name       = $tokens[$j][1];
			$isAbsolute = (str_starts_with($name, '\\') === true);
			$name       = ltrim($name, '\\');

			// The argument list must open immediately.
			$k = ($j + 1);
			while ($k < $count && is_array($tokens[$k]) === true
				&& $tokens[$k][0] === T_WHITESPACE
			) {
				$k++;
			}

			if ($k >= $count || $tokens[$k] !== '(') {
				continue;
			}

			[$named, $positional, $end] = $this->readArgumentList($tokens, $k);

			// Resolve `use ... as Alias` FIRST, then take the short name from
			// the fully-qualified name. The other way round makes an aliased
			// class look unresolvable, which reads as "not checked".
			$head = explode('\\', $name)[0];
			$fqn  = $name;
			if (isset($useMap[$head]) === true) {
				$fqn = $useMap[$head] . substr($name, strlen($head));
			} else if ($isAbsolute === false && $namespace !== '') {
				// Not imported and not fully qualified: PHP resolves it
				// relative to the file's own namespace.
				$fqn = $namespace . '\\' . $name;
			}

			$sites[] = [
				'class'      => ltrim($fqn, '\\'),
				'line'       => $line,
				'named'      => $named,
				'positional' => $positional,
			];

			$i = $end;
		}//end for

		return $sites;

	}//end parseNewCalls()


	/**
	 * Read one argument list starting at the `(` token.
	 *
	 * @param array<int, mixed> $tokens The full token stream.
	 * @param int               $open   Index of the opening parenthesis.
	 *
	 * @return array{0: string[], 1: int, 2: int} Named argument names, the
	 *                                            number of positional
	 *                                            arguments, and the index of
	 *                                            the closing parenthesis.
	 */
	private function readArgumentList(array $tokens, int $open): array {
		$depth      = 0;
		$named      = [];
		$positional = 0;
		$sawToken   = false;
		$count      = count($tokens);
		$i          = $open;

		for (; $i < $count; $i++) {
			$token = $tokens[$i];

			if (is_array($token) === true) {
				if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT
					|| $token[0] === T_DOC_COMMENT
				) {
					continue;
				}

				if ($depth === 1) {
					// A named argument is `name:` at the top level of this
					// list. `::` is its own token (T_DOUBLE_COLON), so
					// `Foo::class` cannot be mistaken for one.
					if ($token[0] === T_STRING) {
						$next = ($i + 1);
						while ($next < $count && is_array($tokens[$next]) === true
							&& $tokens[$next][0] === T_WHITESPACE
						) {
							$next++;
						}

						if ($next < $count && $tokens[$next] === ':') {
							$named[]  = $token[1];
							$sawToken = true;
							$i        = $next;
							continue;
						}
					}

					$sawToken = true;
				}

				continue;
			}//end if

			if ($token === '(' || $token === '[' || $token === '{') {
				$depth++;
				if ($depth > 1) {
					$sawToken = true;
				}

				continue;
			}

			if ($token === ')' || $token === ']' || $token === '}') {
				$depth--;
				if ($depth === 0) {
					// PHP requires positional arguments before named ones, so
					// a final segment only counts as positional while no named
					// argument has been seen.
					if ($sawToken === true && count($named) === 0) {
						$positional++;
					}

					break;
				}

				continue;
			}

			if ($token === ',' && $depth === 1) {
				if ($sawToken === true && count($named) === 0) {
					$positional++;
				}

				$sawToken = false;
				continue;
			}

			if ($depth === 1) {
				$sawToken = true;
			}
		}//end for

		return [$named, $positional, $i];

	}//end readArgumentList()


}//end class
