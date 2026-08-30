<?php

/**
 * Regression guard: every REQUIRED in-app interface dependency must be bound.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's `SimpleContainer` autowires a constructor parameter by its type.
 * When that type is an INTERFACE (or abstract class) the container cannot
 * `new` it, so `Container::resolve()` throws
 *
 *   QueryException: Could not resolve <FQCN>! Class can not be instantiated
 *
 * unless `Application::register()` binds it with `registerService()`.
 *
 * `SimpleContainer::buildClassConstructorParameters()` only swallows that
 * exception when the parameter `isDefaultValueAvailable()` (or is nullable and
 * resolvable by NAME). So the shape
 *
 *   ?FooPortInterface $port = null      // SAFE — falls back to the default
 *   FooPortInterface $port              // FATAL — no binding, no default
 *
 * is the whole difference between a dormant optional port and an endpoint that
 * answers HTTP 500 on every single request. The failure is not local to the
 * class that declares it: it propagates up the entire construction graph, so
 * one unbound port takes down every controller that transitively depends on it,
 * BEFORE any controller code runs — which means no in-controller error handling,
 * validation, or IDOR masking ever executes.
 *
 * Measured on `development` (run 31110513361):
 * `PeppolTransmissionPortInterface` had no binding and was type-hinted
 * NON-nullably with no default by `EInvoiceValidationService`. That killed
 * `EInvoiceValidationService -> EInvoiceService -> ARInvoiceEInvoiceController`,
 * so `POST /api/ar-invoices/{invoiceNumber}/send-einvoice` returned 500 with an
 * unhandled exception where the spec requires 400 (missing administrationId)
 * and an IDOR-safe 404 (unknown invoice). Two Newman assertions caught it; the
 * 1500+ unit tests did not, because none of them builds the container.
 *
 * This guard is a SOURCE scan, deliberately not a container test: it needs no
 * Nextcloud instance, and it cannot be fooled by the autoloader resolving
 * `OCA\Shillinq\*` to a deployed copy of the app rather than this tree.
 */
final class ContainerResolvableConstructorsTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Set up the repository root path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = dirname(__DIR__, 3);

	}//end setUp()

	/**
	 * Every required, non-instantiable in-app constructor dependency is bound.
	 *
	 * @return void
	 */
	public function testEveryRequiredInAppInterfaceDependencyIsRegistered(): void {
		$files = $this->phpFiles($this->root . '/lib');
		$this->assertGreaterThan(
			500,
			count($files),
			'Sanity floor: the lib/ scan found implausibly few PHP files, so a '
			. 'green result here would mean the scan did not run, not that the '
			. 'bindings are correct.'
		);

		$nonInstantiable = $this->nonInstantiableInAppTypes($files);
		$this->assertGreaterThan(
			20,
			count($nonInstantiable),
			'Sanity floor: no in-app interfaces/abstract classes were detected, '
			. 'so this test could never fail and proves nothing.'
		);

		$bound = $this->registeredServices();
		$violations = [];

		foreach ($files as $file) {
			foreach ($this->requiredDependencies($file) as $dependency) {
				[$fqcn, $className] = $dependency;
				if (isset($nonInstantiable[$fqcn]) === false) {
					continue;
				}

				if (isset($bound[$fqcn]) === true) {
					continue;
				}

				$violations[] = $className . ' requires ' . $fqcn
					. ' (declared in ' . str_replace($this->root . '/', '', $file) . ')';
			}
		}

		$this->assertSame(
			[],
			$violations,
			"The following constructors type-hint an in-app interface or abstract\n"
			. "class NON-nullably with no default value, but Application::register()\n"
			. "never binds that type. Nextcloud's container cannot build them, so\n"
			. "every controller that transitively depends on one answers HTTP 500\n"
			. "before its own code runs. Fix by adding a registerService() binding\n"
			. "to lib/AppInfo/Application.php, or by making the parameter optional\n"
			. "(`?Type \$p = null`) when the dependency really is optional:\n\n  "
			. implode("\n  ", $violations) . "\n"
		);

	}//end testEveryRequiredInAppInterfaceDependencyIsRegistered()

	/**
	 * Recursively collect every .php file under a directory.
	 *
	 * @param string $dir Directory to walk.
	 *
	 * @return array<int,string> Absolute file paths.
	 */
	private function phpFiles(string $dir): array {
		$found = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$found[] = $entry->getPathname();
			}
		}

		sort($found);
		return $found;
	}//end phpFiles()

	/**
	 * Map every `OCA\Shillinq\*` interface / abstract class to its FQCN.
	 *
	 * @param array<int,string> $files Files to scan.
	 *
	 * @return array<string,true> FQCN => true for non-instantiable types.
	 */
	private function nonInstantiableInAppTypes(array $files): array {
		$types = [];

		foreach ($files as $file) {
			$source = (string)file_get_contents($file);
			if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
				continue;
			}

			$matched = preg_match(
				'/^\s*(?:final\s+)?(interface|abstract\s+class)\s+(\w+)/m',
				$source,
				$decl
			);
			if ($matched !== 1) {
				continue;
			}

			$types[trim($ns[1]) . '\\' . $decl[2]] = true;
		}

		return $types;
	}//end nonInstantiableInAppTypes()

	/**
	 * Extract required (non-nullable, defaultless) in-app constructor deps.
	 *
	 * @param string $file The PHP file to inspect.
	 *
	 * @return array<int,array{0:string,1:string}> Tuples of [dependency FQCN, declaring class FQCN].
	 */
	private function requiredDependencies(string $file): array {
		$source = (string)file_get_contents($file);
		if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
			return [];
		}

		$namespace = trim($ns[1]);
		if (preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $decl) !== 1) {
			return [];
		}

		$className = $namespace . '\\' . $decl[1];
		$aliases = $this->useAliases($source);

		$matched = preg_match(
			'/function\s+__construct\s*\((.*?)\)\s*(?::\s*\w+\s*)?\{/s',
			$source,
			$ctor
		);
		if ($matched !== 1) {
			return [];
		}

		$dependencies = [];
		foreach ($this->splitParameters($ctor[1]) as $parameter) {
			// Optional (has a default) or explicitly nullable — the container
			// falls back safely, so an unbound type is harmless here.
			if (str_contains($parameter, '=') === true || str_contains($parameter, '?') === true) {
				continue;
			}

			$matchedType = preg_match(
				'/(?:public|protected|private)?\s*(?:readonly\s+)?([A-Za-z_\\\\][\w\\\\]*)\s+\$\w+\s*$/',
				trim($parameter),
				$type
			);
			if ($matchedType !== 1) {
				continue;
			}

			$short = $type[1];
			$fqcn = ($aliases[$short] ?? ($namespace . '\\' . $short));
			if (str_starts_with($short, '\\') === true) {
				$fqcn = ltrim($short, '\\');
			}

			$dependencies[] = [$fqcn, $className];
		}//end foreach

		return $dependencies;
	}//end requiredDependencies()

	/**
	 * Build the short-name => FQCN map from a file's `use` statements.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<string,string> Alias map.
	 */
	private function useAliases(string $source): array {
		$aliases = [];
		preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			$fqcn = $match[1];
			$parts = explode('\\', $fqcn);
			$short = ($match[2] ?? end($parts));
			if ($short === '' || $short === null) {
				$short = end($parts);
			}

			$aliases[$short] = $fqcn;
		}

		return $aliases;
	}//end useAliases()

	/**
	 * Split a constructor parameter list on top-level commas.
	 *
	 * Attribute arguments and default array literals can contain commas, so a
	 * naive explode() would shred the list.
	 *
	 * @param string $parameters Raw parameter list.
	 *
	 * @return array<int,string> Individual parameter declarations.
	 */
	private function splitParameters(string $parameters): array {
		$out = [];
		$buffer = '';
		$depth = 0;
		$length = strlen($parameters);

		for ($i = 0; $i < $length; $i++) {
			$char = $parameters[$i];
			if ($char === '(' || $char === '[') {
				$depth++;
			}

			if ($char === ')' || $char === ']') {
				$depth--;
			}

			if ($char === ',' && $depth === 0) {
				$out[] = $buffer;
				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		if (trim($buffer) !== '') {
			$out[] = $buffer;
		}

		return $out;
	}//end splitParameters()

	/**
	 * Collect every type bound via `registerService(` anywhere under
	 * `lib/AppInfo/`.
	 *
	 * Application::register() is not the only place a binding can live:
	 * `OrderFulfilmentGateRegistration`, `SigningDelegationRegistration`
	 * and `BudgetScenarioRegistration` are focused registrar classes,
	 * `(new ...Registration())->register($context)`-called from
	 * Application::register() specifically to keep it under PHPMD's
	 * `ExcessiveClassLength` threshold. A binding moved into one of those
	 * classes is bound at runtime exactly as if it were written inline, so
	 * this scan must follow it there or it reports a false violation for
	 * every interface a registrar (rather than Application.php itself) binds.
	 *
	 * @return array<string,true> FQCN => true.
	 */
	private function registeredServices(): array {
		$bound = [];

		foreach (glob($this->root . '/lib/AppInfo/*.php') as $file) {
			$source = (string)file_get_contents($file);
			$aliases = $this->useAliases($source);

			preg_match_all(
				'/registerService\(\s*(?:[\w]+:\s*)?([A-Za-z_\\\\][\w\\\\]*)::class/',
				$source,
				$matches
			);

			foreach ($matches[1] as $short) {
				$bound[$aliases[$short] ?? ('OCA\\Shillinq\\AppInfo\\' . $short)] = true;
			}

			// registerServiceAlias() is the other binding form the framework offers.
			preg_match_all(
				'/registerServiceAlias\(\s*(?:[\w]+:\s*)?([A-Za-z_\\\\][\w\\\\]*)::class/',
				$source,
				$aliasMatches
			);

			foreach ($aliasMatches[1] as $short) {
				$bound[$aliases[$short] ?? ('OCA\\Shillinq\\AppInfo\\' . $short)] = true;
			}
		}

		return $bound;
	}//end registeredServices()
}//end class
