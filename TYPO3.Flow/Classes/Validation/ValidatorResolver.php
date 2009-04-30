<?php
declare(ENCODING = 'utf-8');
namespace F3\FLOW3\Validation;

/*                                                                        *
 * This script belongs to the FLOW3 framework.                            *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * @package FLOW3
 * @subpackage Validation
 * @version $Id$
 */

/**
 * Validator resolver to automatically find a appropriate validator for a given subject
 *
 * @package FLOW3
 * @subpackage Validation
 * @version $Id$
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 */
class ValidatorResolver {

	/**
	 * @var \F3\FLOW3\Object\ManagerInterface
	 */
	protected $objectManager;

	/**
	 * @var \F3\FLOW3\Reflection\Service
	 */
	protected $reflectionService;

	/**
	 * @var array
	 */
	protected $baseValidatorChains = array();

	/**
	 * Constructs the validator resolver
	 *
	 * @param \F3\FLOW3\Object\ManagerInterface A reference to the compomenent manager
	 * @author Andreas Förthner <andreas.foerthner@netlogix.de>
	 */
	public function __construct(\F3\FLOW3\Object\ManagerInterface $objectManager) {
		$this->objectManager = $objectManager;
	}

	/**
	 * Injects the reflection service
	 *
	 * @param \F3\FLOW3\Reflection\Service $reflectionService
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 * @internal
	 */
	public function injectReflectionService(\F3\FLOW3\Reflection\Service $reflectionService) {
		$this->reflectionService = $reflectionService;
	}

	/**
	 * Get a validator for a given data type. Returns a validator implementing
	 * the F3\FLOW3\Validation\Validator\ValidatorInterface or NULL if no validator
	 * could be resolved.
	 *
	 * @param string $validatorName Either one of the built-in data types or fully qualified validator class name
	 * @param array $validatorOptions Options to be passed to the validator
	 * @return F3\FLOW3\Validation\Validator\ValidatorResolver Validator Resolver or NULL if none found.
	 * @author Sebastian Kurfürst <sebastian@typo3.org>
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function createValidator($validatorName, array $validatorOptions = array()) {
		$validatorClassName = $this->resolveValidatorObjectName($validatorName);
		if ($validatorClassName === FALSE) return NULL;
		$validator = $this->objectManager->getObject($validatorClassName);
		$validator->setOptions($validatorOptions);
		return ($validator instanceof \F3\FLOW3\Validation\Validator\ValidatorInterface) ? $validator : NULL;
	}

	/**
	 * Resolves and returns the base validator chain for the given data type.
	 *
	 * If no validator could be resolved (which usually means that no validation is necessary),
	 * NULL is returned.
	 *
	 * @param string $dataType The data type to search a validator for. Usually the fully qualified object name
	 * @return F3\FLOW3\Validation\Validator\ChainValidator The validator chain or NULL
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function getBaseValidatorChain($dataType) {
		if (!isset($this->baseValidatorChains[$dataType])) {
			$this->baseValidatorChains[$dataType] = $this->buildBaseValidatorChain($dataType);
		}
		return $this->baseValidatorChains[$dataType];
	}

	/**
	 * Detects and registers any additional validators for arguments which were specified in the @validate
	 * annotations of a method.
	 *
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 * @internal
	 */
	public function buildMethodArgumentsValidatorChains($className, $methodName) {
		$validatorChains = array();

		$methodTagsValues = $this->reflectionService->getMethodTagsValues($className, $methodName);
		if (isset($methodTagsValues['validate'])) {
			foreach ($methodTagsValues['validate'] as $validateValue) {
				$matches = array();
				preg_match('/^\$(?P<argumentName>[a-zA-Z0-9]+)\s+(?P<validators>.*)$/', $validateValue, $matches);
				$argumentName = $matches['argumentName'];

				preg_match_all('/(?P<validatorName>[a-zA-Z0-9]+)(?:\((?P<validatorOptions>[^)]+)\))?/', $matches['validators'], $matches, PREG_SET_ORDER);
				foreach ($matches as $match) {
					$validatorName = $match['validatorName'];
					$validatorOptions = array();
					$rawValidatorOptions = isset($match['validatorOptions']) ? explode(',', $match['validatorOptions']) : array();
					foreach ($rawValidatorOptions as $rawValidatorOption) {
						if (strpos($rawValidatorOption, '=') !== FALSE) {
							list($optionName, $optionValue) = explode('=', $rawValidatorOption);
							$validatorOptions[trim($optionName)] = trim($optionValue);
						}
					}
					$newValidator = $this->createValidator($validatorName, $validatorOptions);
					if ($newValidator === NULL) throw new \F3\FLOW3\Validation\Exception\NoSuchValidator('Invalid validate annotation in ' . $className . '->' . $methodName . '(): Could not resolve class name for  validator "' . $validatorName . '".', 1239853109);

					if  (isset($validatorChains[$argumentName])) {
						$validatorChains[$argumentName]->addValidator($newValidator);
					} else {
						$validatorChains[$argumentName] = $this->createValidator('Chain');
						$validatorChains[$argumentName]->addValidator($newValidator);
					}
				}
			}
		}
		return $validatorChains;
	}

	/**
	 * Builds a base validator chain for the given data type.
	 *
	 * The base validation rules are those which were declared directly in a class (typically
	 * a model) through some @validate annotations.
	 *
	 * Additionally, if a custom validator was defined for the class in question, it will be added
	 * to the end of the chain. A custom validator is found if it follows the naming convention
	 * "[FullyqualifiedModelClassName]Validator".
	 *
	 * @param string $dataType The data type to build the validation chain for. Usually the fully qualified object name.
	 * @return F3\FLOW3\Validation\Validator\ChainValidator The validator chain or NULL
	 * @author Robert Lemke <robert@typo3.org>
	 */
	protected function buildBaseValidatorChain($dataType) {
		$validatorChain = $this->objectManager->getObject('F3\FLOW3\Validation\Validator\ChainValidator');

		$customValidatorObjectName = $this->resolveValidatorObjectName($dataType . 'Validator');
		if ($customValidatorObjectName !== FALSE) {
			$validatorChain->addValidator($this->objectManager->getObject($customValidatorObjectName));
		}

		if (class_exists($dataType)) {
			$validatorCount = 0;
			$objectValidator = $this->createValidator('GenericObject');

			foreach ($this->reflectionService->getClassPropertyNames($dataType) as $classPropertyName) {
				$classPropertyTagsValues = $this->reflectionService->getPropertyTagsValues($dataType, $classPropertyName);
				if (!isset($classPropertyTagsValues['validate'])) continue;

				foreach ($classPropertyTagsValues['validate'] as $validateValue) {
					$matches = array();
					preg_match_all('/(?P<validatorName>[a-zA-Z0-9]+)(?:\((?P<validatorOptions>[^)]+)\))?/', $validateValue, $matches, PREG_SET_ORDER);
					foreach ($matches as $match) {
						$validatorName = $match['validatorName'];
						$validatorOptions = array();
						$rawValidatorOptions = isset($match['validatorOptions']) ? explode(',', $match['validatorOptions']) : array();
						foreach ($rawValidatorOptions as $rawValidatorOption) {
							if (strpos($rawValidatorOption, '=') !== FALSE) {
								list($optionName, $optionValue) = explode('=', $rawValidatorOption);
								$validatorOptions[trim($optionName)] = trim($optionValue);
							}
						}
						$newValidator = $this->createValidator($validatorName, $validatorOptions);
						if ($newValidator === NULL) throw new \F3\FLOW3\Validation\Exception\NoSuchValidator('Invalid validate annotation in ' . $dataType . '::' . $classPropertyName . ': Could not resolve class name for  validator "' . $validatorName . '".', 1241098027);
						$objectValidator->addPropertyValidator($classPropertyName, $newValidator);
						$validatorCount ++;
					}
				}
			}
			if ($validatorCount > 0) $validatorChain->addValidator($objectValidator);
		}

		return $validatorChain;
	}

	/**
	 * Returns an object of an appropriate validator for the given class. If no validator is available
	 * NULL is returned
	 *
	 * @param string $validatorName Either the fully qualified class name of the validator or the short name of a built-in validator
	 * @return string Name of the validator object or FALSE
	 * @author Robert Lemke <robert@typo3.org>
	 */
	protected function resolveValidatorObjectName($validatorName) {
		if ($this->objectManager->isObjectRegistered($validatorName)) return $validatorName;

		$possibleClassName = 'F3\FLOW3\Validation\Validator\\' . $this->unifyDataType($validatorName) . 'Validator';
		if ($this->objectManager->isObjectRegistered($possibleClassName)) return $possibleClassName;

		return FALSE;
	}

	/**
	 * Preprocess data types. Used to map primitive PHP types to DataTypes in FLOW3.
	 *
	 * @param string $type Data type to unify
	 * @return string unified data type
	 * @author Sebastian Kurfürst <sebastian@typo3.org>
	 * @author Bastian Waidelich <bastian@typo3.org>
	 */
	protected function unifyDataType($type) {
		switch ($type) {
			case 'int' :
				$type = 'Integer';
				break;
			case 'string' :
				$type = 'Text';
				break;
			case 'bool' :
				$type = 'Boolean';
				break;
			case 'double' :
				$type = 'Float';
				break;
			case 'numeric' :
				$type = 'Number';
				break;
			case 'mixed' :
				$type = 'Raw';
				break;
		}
		return ucfirst($type);
	}
}

?>