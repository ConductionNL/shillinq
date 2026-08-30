<?php

/**
 * PHPUnit bootstrap for standalone unit tests — wires OCP stubs without full NC environment.
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);
define('PHPUNIT_RUN', 1);
$autoloader = include __DIR__ . '/../vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/stubs/OpenRegister/');

// \OC::$server is referenced from OCP\AppFramework\Http\Response::getHeaders().
// Provide a minimal compatible stub so controller tests can serialise headers
// without bootstrapping a full Nextcloud environment.
if (class_exists('OC', false) === false) {
	/**
	 * Minimal OC class stub for unit-test header serialisation.
	 */
	final class OC {

		/**
		 * Pseudo-server container — only get() is exercised.
		 *
		 * @var object
		 */
		public static object $server;
	}//end class

	OC::$server = new class {
		/**
		 * Resolve a service interface to a minimal stub.
		 *
		 * @param string $service The service id.
		 *
		 * @return object The stub.
		 */
		public function get(string $service): object {
			if ($service === 'OCP\\IRequest') {
				return new class {
					/**
					 * Return a deterministic request id stub.
					 *
					 * @return string
					 */
					public function getId(): string {
						return 'stub-request-id';
					}//end getId()
				};
			}

			if ($service === 'OCP\\IConfig') {
				return new class {
					/**
					 * Return a system-value stub.
					 *
					 * @param string $key The config key.
					 * @param mixed $default The default.
					 *
					 * @return mixed The default.
					 */
					public function getSystemValue(string $key, mixed $default = null): mixed {
						return $default;
					}//end getSystemValue()
				};
			}

			return new class {
			};
		}//end get()
	};
}//end if
