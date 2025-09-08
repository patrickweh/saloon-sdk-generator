<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method as SaloonHttpMethod;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class RequestGenerator extends Generator
{
    protected array $inlineResponseDtos = [];

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $classes = [];

        foreach ($specification->endpoints as $endpoint) {
            $classes[] = $this->generateRequestClass($endpoint);
        }

        return $classes;
    }

    protected function addCreateDtoFromResponseMethod(ClassType $classType, PhpNamespace $namespace, Endpoint $endpoint): void
    {
        $response = $endpoint->response;

        if (! $response) {
            return;
        }

        $method = $classType->addMethod('createDtoFromResponse')
            ->setPublic()
            ->setReturnType('mixed');

        $responseParam = $method->addParameter('response');
        $responseParam->setType('Saloon\Http\Response');

        $namespace->addUse('Saloon\Http\Response');

        // Handle different response types
        if ($response['type'] === 'dto' && isset($response['dto_class'])) {
            // Reference to a DTO class
            $dtoClass = $response['dto_class'];
            $dtoFullClass = $this->config->namespace . '\\' . $this->config->dtoNamespaceSuffix . '\\' . $dtoClass;

            $namespace->addUse($dtoFullClass);

            $method->addBody('$data = $response->json();');
            $method->addBody('');
            $method->addBody("return new \\{$dtoFullClass}(\$data);");
        } elseif ($response['type'] === 'array' && isset($response['dto_class'])) {
            // Array of DTOs
            $dtoClass = $response['dto_class'];
            $dtoFullClass = $this->config->namespace . '\\' . $this->config->dtoNamespaceSuffix . '\\' . $dtoClass;

            $namespace->addUse($dtoFullClass);

            $method->addBody('$items = $response->json();');
            $method->addBody('');
            $method->addBody('return array_map(');
            $method->addBody("    fn(\$item) => new \\{$dtoFullClass}(\$item),");
            $method->addBody('    $items');
            $method->addBody(');');
        } elseif ($response['type'] === 'inline' && isset($response['schema'])) {
            // For inline schemas, use the generic ApiResponse DTO
            $apiResponseClass = $this->config->namespace . '\\' . $this->config->dtoNamespaceSuffix . '\\ApiResponse';

            $namespace->addUse($apiResponseClass);

            $method->addBody('$data = $response->json();');
            $method->addBody('');
            $method->addBody("return \\{$apiResponseClass}::fromResponse(\$data);");
        } else {
            // Default: Use ApiResponse for all responses
            $apiResponseClass = $this->config->namespace . '\\' . $this->config->dtoNamespaceSuffix . '\\ApiResponse';

            $namespace->addUse($apiResponseClass);

            $method->addBody('$data = $response->json();');
            $method->addBody('');
            $method->addBody("return \\{$apiResponseClass}::fromResponse(\$data);");
        }
    }

    protected function generateRequestClass(Endpoint $endpoint): PhpFile
    {
        $resourceName = NameHelper::resourceClassName($endpoint->collection ?: $this->config->fallbackResourceName);
        $className = NameHelper::requestClassName($endpoint->name);

        $classType = new ClassType($className);

        $classFile = new PhpFile();
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}");

        $classType->setExtends(Request::class)
            ->setComment($endpoint->name)
            ->addComment('')
            ->addComment(Utils::wrapLongLines($endpoint->description ?? ''));

        // TODO: We assume JSON body if post/patch, make these assumptions configurable in the future.
        if ($endpoint->method->isPost() || $endpoint->method->isPatch()) {
            $classType
                ->addImplement(HasBody::class)
                ->addTrait(HasJsonBody::class);

            $namespace
                ->addUse(HasBody::class)
                ->addUse(HasJsonBody::class);
        }

        $classType->addProperty('method')
            ->setProtected()
            ->setType(SaloonHttpMethod::class)
            ->setValue(
                new Literal(
                    sprintf('Method::%s', $endpoint->method->value)
                )
            );

        $classType->addMethod('resolveEndpoint')
            ->setPublic()
            ->setReturnType('string')
            ->addBody(
                collect($endpoint->pathSegments)
                    ->map(function ($segment) {
                        return Str::startsWith($segment, ':')
                            ? new Literal(sprintf('{$this->%s}', NameHelper::safeVariableName($segment)))
                            : $segment;
                    })
                    ->pipe(function (Collection $segments) {
                        return new Literal(sprintf('return "/%s";', $segments->implode('/')));
                    })
            );

        $classConstructor = $classType->addMethod('__construct');

        // Get the namespace for enum type resolution
        $currentNamespace = "{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}";

        // Collect all enum types that need to be imported
        $enumsToImport = [];
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

        // Import all collected enum types
        foreach ($enumsToImport as $enumFQN => $enumClass) {
            $namespace->addUse($enumFQN);
        }

        // Priority 1. - Path Parameters
        foreach ($endpoint->pathParameters as $pathParam) {
            MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $pathParam, namespace: $currentNamespace);
        }

        // Priority 2. - Body Parameters
        if (! empty($endpoint->bodyParameters)) {
            $bodyParams = collect($endpoint->bodyParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredBodyParams))
                ->values()
                ->toArray();

            foreach ($bodyParams as $bodyParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $bodyParam, namespace: $currentNamespace);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultBody', $bodyParams, withArrayFilterWrapper: true);
        }

        // Priority 3. - Query Parameters
        if (! empty($endpoint->queryParameters)) {
            $queryParams = collect($endpoint->queryParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
                ->values()
                ->toArray();

            foreach ($queryParams as $queryParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $queryParam, namespace: $currentNamespace);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultQuery', $queryParams, withArrayFilterWrapper: true);
        }

        // Priority 4. - Header Parameters
        if (! empty($endpoint->headerParameters)) {
            $headerParams = collect($endpoint->headerParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredHeaderParams))
                ->values()
                ->toArray();

            foreach ($headerParams as $headerParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $headerParam, namespace: $currentNamespace);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultHeaders', $headerParams, withArrayFilterWrapper: true);
        }

        // Add createDtoFromResponse method if endpoint has a response
        if ($endpoint->response) {
            $this->addCreateDtoFromResponseMethod($classType, $namespace, $endpoint);
        }

        $namespace
            ->addUse(SaloonHttpMethod::class)
            ->addUse(DateTime::class)
            ->addUse(Request::class)
            ->add($classType);

        return $classFile;
    }
}
