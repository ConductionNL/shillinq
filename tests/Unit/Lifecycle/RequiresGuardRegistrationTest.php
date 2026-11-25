<?php

/**
 * Unit tests for RequiresGuardRegistration.
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
 * @spec openspec/specs/missing-lifecycle-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\RequiresGuardRegistration;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every `Class::method` requires tag of the register resolves to a guard.
 */
final class RequiresGuardRegistrationTest extends TestCase {

	private const ROOT = __DIR__ . '/../../..';

	/**
	 * TAGS is exactly the register's fitting `Class::method` tags that no
	 * other registration binds: a new tag fails here until it is listed.
	 *
	 * @return void
	 */
	public function testTagsAreTheRegistersUnregisteredTags(): void {
		$expected = array_values(array_diff($this->fittingRegisterTags(), $this->tagsRegisteredElsewhere()));
		sort($expected);
		$listed = RequiresGuardRegistration::TAGS;
		sort($listed);

		self::assertSame(expected: $expected, actual: $listed);

	}//end testTagsAreTheRegistersUnregisteredTags()

	/**
	 * Each tag is registered as an adapter over its own guard class.
	 *
	 * @return void
	 */
	public function testEachTagIsRegisteredAsAnAdapterOverItsGuard(): void {
		$factories = [];
		$context   = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new RequiresGuardRegistration())->register(context: $context);

		self::assertSame(expected: RequiresGuardRegistration::TAGS, actual: array_keys($factories));

		$asked     = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$asked): object {
				if ($id === LoggerInterface::class) {
					return new NullLogger();
				}

				$asked[] = $id;
				return new \stdClass();
			}
		);

		foreach ($factories as $tag => $factory) {
			self::assertInstanceOf(expected: RegisterRequiresGuardAdapter::class, actual: $factory($container), message: $tag);
			self::assertSame(expected: explode('::', $tag)[0], actual: end($asked), message: $tag);
		}

	}//end testEachTagIsRegisteredAsAnAdapterOverItsGuard()

	/**
	 * The `Class::method` tags of the register whose method takes the object,
	 * or its id and the object.
	 *
	 * @return list<string>
	 */
	private function fittingRegisterTags(): array {
		$files = glob(self::ROOT . '/lib/Settings/register.d/*.json');
		$files[] = self::ROOT . '/lib/Settings/shillinq_register.json';
		$tags = [];
		foreach ($files as $file) {
			$this->collectRequires(node: json_decode((string)file_get_contents($file), true), tags: $tags);
		}

		$fitting = [];
		foreach (array_keys($tags) as $tag) {
			if ($this->fits(tag: $tag) === true) {
				$fitting[] = $tag;
			}
		}

		return $fitting;

	}//end fittingRegisterTags()

	/**
	 * Collect every string `requires` value under a node.
	 *
	 * @param mixed                $node The JSON node.
	 * @param array<string, true> $tags The tags found so far.
	 *
	 * @return void
	 */
	private function collectRequires(mixed $node, array &$tags): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if ($key === 'requires') {
				foreach ((array)$value as $tag) {
					if (is_string($tag) === true) {
						$tags[$tag] = true;
					}
				}
			}

			$this->collectRequires(node: $value, tags: $tags);
		}

	}//end collectRequires()

	/**
	 * Whether a tag names a shillinq method that takes the object, or its id
	 * and the object (every further parameter optional).
	 *
	 * @param string $tag The tag.
	 *
	 * @return bool
	 */
	private function fits(string $tag): bool {
		if (str_starts_with($tag, 'OCA\\Shillinq\\') === false || str_contains($tag, '::') === false) {
			return false;
		}

		[$class, $method] = explode('::', $tag, 2);
		if (method_exists($class, $method) === false) {
			return false;
		}

		$parameters = (new ReflectionMethod($class, $method))->getParameters();
		$first      = ($parameters[0] ?? null)?->getType();
		if ($first instanceof ReflectionNamedType === false || in_array($first->getName(), ['array', 'string'], true) === false) {
			return false;
		}

		foreach (array_slice($parameters, 1) as $parameter) {
			if ($parameter->isOptional() === false) {
				return false;
			}
		}

		return true;

	}//end fits()

	/**
	 * The `Class::method` tags another file in lib/ registers.
	 *
	 * @return list<string>
	 */
	private function tagsRegisteredElsewhere(): array {
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/lib'));
		$tags     = [];
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php' || $file->getFilename() === 'RequiresGuardRegistration.php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (str_contains($source, 'registerService') === false && str_contains($source, 'GUARDS') === false) {
				continue;
			}

			preg_match_all("/'(OCA\\\\\\\\?Shillinq\\\\\\\\?[A-Za-z\\\\\\\\]+::\\w+)'/", $source, $matches);
			foreach ($matches[1] as $tag) {
				$tags[] = str_replace('\\\\', '\\', $tag);
			}
		}

		return array_values(array_unique($tags));

	}//end tagsRegisteredElsewhere()
}//end class
