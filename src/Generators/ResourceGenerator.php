<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Illuminate\Support\Str;
use Nette\InvalidStateException;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;
use Saloon\Http\BaseResource;
use Saloon\Http\Response;

class ResourceGenerator extends Generator
{
    protected array $duplicateRequests = [];

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        return $this->generateResourceClasses($specification);
    }

    /**
     * @param  array|Endpoint[]  $endpoints
     */
    public function generateResourceClass(string $resourceName, array $endpoints): ?PhpFile
    {
        $classType = new ClassType($resourceName);

        $classType->setExtends(BaseResource::class);

        $classFile = new PhpFile();
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->resourceNamespaceSuffix}")
            ->addUse(BaseResource::class);

        $duplicateCounter = 1;
        $enumsToImport = [];

        foreach ($endpoints as $endpoint) {
            $requestClassName = NameHelper::resourceClassName($endpoint->name);
            $methodName = NameHelper::safeVariableName($requestClassName);
            $requestClassNameAlias = $requestClassName == $resourceName ? "{$requestClassName}Request" : null;
            $requestClassFQN = "{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}\\{$requestClassName}";

            $namespace
                ->addUse(Response::class)
                ->addUse(
                    name: $requestClassFQN,
                    alias: $requestClassNameAlias,
                );

            try {
                $method = $classType->addMethod($methodName);
            } catch (InvalidStateException $exception) {
                // TODO: handle more gracefully in the future
                $deduplicatedMethodName = NameHelper::safeVariableName(
                    sprintf('%s%s', $methodName, 'Duplicate' . $duplicateCounter)
                );
                $duplicateCounter++;

                $this->recordDuplicatedRequestName($requestClassName, $deduplicatedMethodName);

                $method = $classType
                    ->addMethod($deduplicatedMethodName)
                    ->addComment('@todo Fix duplicated method name');
            }

            $method->setReturnType(Response::class);

            $args = [];

            // Collect enum types from all parameters
            $allParameters = array_merge(
                $endpoint->pathParameters,
                $endpoint->bodyParameters,
                $endpoint->queryParameters,
                $endpoint->headerParameters
            );

            foreach ($allParameters as $parameter) {
                if ($parameter->hasEnum()) {
                    $enumClass = Str::studly($parameter->enumName);
                    $enumFQN = "{$this->config->namespace}\\Enums\\{$enumClass}";
                    $enumsToImport[$enumFQN] = $enumClass;
                }
            }

            foreach ($endpoint->pathParameters as $parameter) {
                $this->addPropertyToMethod($method, $parameter);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($parameter->name)));
            }

            foreach ($endpoint->bodyParameters as $parameter) {
                if (in_array($parameter->name, $this->config->ignoredBodyParams)) {
                    continue;
                }

                $this->addPropertyToMethod($method, $parameter);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($parameter->name)));
            }

            foreach ($endpoint->queryParameters as $parameter) {
                if (in_array($parameter->name, $this->config->ignoredQueryParams)) {
                    continue;
                }
                $this->addPropertyToMethod($method, $parameter);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($parameter->name)));
            }

            foreach ($endpoint->headerParameters as $parameter) {
                $this->addPropertyToMethod($method, $parameter);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($parameter->name)));
            }

            $method->setBody(
                new Literal(sprintf('return $this->connector->send(new %s(%s));', $requestClassNameAlias ?? $requestClassName, implode(', ', $args)))
            );
        }

        // Import all collected enum types
        foreach ($enumsToImport as $enumFQN => $enumClass) {
            $namespace->addUse($enumFQN);
        }

        $namespace->add($classType);

        return $classFile;
    }

    protected function addPropertyToMethod(Method $method, Parameter $parameter): Method
    {
        $name = NameHelper::safeVariableName($parameter->name);

        // Determine the type to use
        $type = $parameter->type;
        $docType = $parameter->type;

        // If this parameter has an enum, we need to use the full namespace for now
        // Then the import will make it work correctly
        if ($parameter->hasEnum()) {
            $enumClass = Str::studly($parameter->enumName);
            // Use the full namespace for the type, which will be resolved to the short name
            // because we're importing it
            $type = $this->config->namespace . '\\Enums\\' . $enumClass;
            $docType = $enumClass;
        }

        $param = $method
            ->addComment(
                trim(
                    sprintf(
                        '@param %s $%s %s',
                        $parameter->nullable ? "null|{$docType}" : $docType,
                        $name,
                        $parameter->description
                    )
                )
            )
            ->addParameter($name)
            ->setType($type)
            ->setNullable($parameter->nullable);

        if ($parameter->nullable) {
            $param->setDefaultValue(null);
        }

        return $method;
    }

    /**
     * @return array|PhpFile[]
     */
    protected function generateResourceClasses(ApiSpecification $specification): array
    {
        $classes = [];

        $groupedByCollection = collect($specification->endpoints)->groupBy(function (Endpoint $endpoint) {
            return NameHelper::resourceClassName(
                $endpoint->collection ?: $this->config->fallbackResourceName
            );
        });

        foreach ($groupedByCollection as $collection => $items) {
            $classes[] = $this->generateResourceClass($collection, $items->toArray());
        }

        return $classes;
    }

    protected function recordDuplicatedRequestName(string $requestClassName, string $deduplicatedMethodName): void
    {
        $this->duplicateRequests[$requestClassName][] = $deduplicatedMethodName;
    }
}
