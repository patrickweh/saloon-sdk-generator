<?php

namespace Crescat\SaloonSdkGenerator\Helpers;

namespace Crescat\SaloonSdkGenerator\Helpers;

use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Dumper;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use SensitiveParameter;

class MethodGeneratorHelper
{
    /**
     * Adds a promoted property to a method based on a given parameter.
     *
     * @param  Method  $method  The method to which the promoted property is added.
     * @param  Parameter  $parameter  The parameter based on which the promoted property is added.
     * @return Method The updated method with the promoted property.
     */
    public static function addParameterAsPromotedProperty(
        Method $method,
        Parameter $parameter,
        mixed $defaultValue = null,
        bool $sensitive = false,
        ?string $namespace = null
    ): Method {
        // TODO: validate that this is a constructor, promported properties are only supported on constructors.

        $name = NameHelper::safeVariableName($parameter->name);

        // Determine the type to use
        $type = $parameter->type;
        $docType = $parameter->type;

        // If this parameter has an enum, use the full namespace
        // (the namespace will handle importing it and resolving to short name)
        if ($parameter->hasEnum() && $namespace) {
            // Extract base namespace (before \Requests or \Resource)
            $parts = explode('\\', $namespace);
            $baseNamespace = [];
            foreach ($parts as $part) {
                if ($part === 'Requests' || $part === 'Resource') {
                    break;
                }
                $baseNamespace[] = $part;
            }
            $enumNamespace = implode('\\', $baseNamespace) . '\\Enums';
            $enumClass = Str::studly($parameter->enumName);
            $type = $enumNamespace . '\\' . $enumClass;
            $docType = $enumClass;
        }

        $property = $method
            ->addComment(
                trim(sprintf(
                    '@param %s $%s %s',
                    $parameter->nullable ? "null|{$docType}" : $docType,
                    $name,
                    $parameter->description
                ))
            )
            ->addPromotedParameter($name);

        $property
            ->setType($type)
            ->setNullable($parameter->nullable)
            ->setProtected();

        if ($defaultValue !== null) {
            $property->setDefaultValue($defaultValue);
        } elseif ($parameter->nullable) {
            $property->setDefaultValue(null);
        }

        if ($sensitive) {
            $property->addAttribute(SensitiveParameter::class);
        }

        return $method;
    }

    /**
     * Generates a method that returns parameters as an array.
     */
    public static function generateArrayReturnMethod(ClassType $classType, string $name, array $parameters, bool $withArrayFilterWrapper = false): Method
    {
        $paramArray = self::buildParameterArray($parameters);

        $body = $withArrayFilterWrapper
            ? sprintf('return array_filter(%s);', (new Dumper())->dump($paramArray))
            : sprintf('return %s;', (new Dumper())->dump($paramArray));

        return $classType
            ->addMethod($name)
            ->setReturnType('array')
            ->addBody($body);
    }

    /**
     * Builds an array of parameters with their corresponding values.
     */
    protected static function buildParameterArray(array $parameters): array
    {
        return collect($parameters)
            ->mapWithKeys(function (Parameter $parameter) {
                $varName = NameHelper::safeVariableName($parameter->name);
                // If this is an enum parameter, we need to get its value
                if ($parameter->hasEnum()) {
                    // Handle nullable enums
                    if ($parameter->nullable) {
                        return [
                            $parameter->name => new Literal(
                                sprintf('$this->%s?->value', $varName)
                            ),
                        ];
                    }

                    return [
                        $parameter->name => new Literal(
                            sprintf('$this->%s->value', $varName)
                        ),
                    ];
                }

                return [
                    $parameter->name => new Literal(
                        sprintf('$this->%s', $varName)
                    ),
                ];
            })
            ->toArray();
    }
}
