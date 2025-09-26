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
use Saloon\Traits\Body\HasFormBody;
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

            // Use fully qualified class name instead of import to avoid naming conflicts
            // $namespace->addUse($dtoFullClass);

            $method->addBody("return \\{$dtoFullClass}::from(\$response->json() ?? []);");
        } elseif ($response['type'] === 'array' && isset($response['dto_class'])) {
            // Array of DTOs
            $dtoClass = $response['dto_class'];
            $dtoFullClass = $this->config->namespace . '\\' . $this->config->dtoNamespaceSuffix . '\\' . $dtoClass;

            // Use fully qualified class name instead of import to avoid naming conflicts
            // $namespace->addUse($dtoFullClass);

            $method->addBody('return array_map(');
            $method->addBody("    fn(\$item) => \\{$dtoFullClass}::from(\$item),");
            $method->addBody('    $response->json() ?? []');
            $method->addBody(');');
        } elseif ($response['type'] === 'inline' && isset($response['schema'])) {
            // For inline schemas, return the raw JSON data
            $method->addBody('return $response->json();');
        } else {
            // Default: return the raw JSON response
            $method->addBody('return $response->json();');
        }
    }

    protected function generateRequestClass(Endpoint $endpoint): PhpFile
    {
        $resourceName = NameHelper::resourceClassName($endpoint->collection ?: $this->config->fallbackResourceName);
        $className = NameHelper::requestClassName($endpoint->name);
        
        // Ensure the class name ends with 'Request' to avoid conflicts with DTOs
        if (!Str::endsWith($className, 'Request')) {
            $className .= 'Request';
        }

        $classType = new ClassType($className);

        $classFile = new PhpFile();
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}");

        $classType->setExtends(Request::class)
            ->setComment($endpoint->name)
            ->addComment('')
            ->addComment(Utils::wrapLongLines($endpoint->description ?? ''));

        // Determine body type based on content type from OpenAPI spec
        if ($endpoint->method->isPost() || $endpoint->method->isPatch() || $endpoint->method->isPut()) {
            $classType->addImplement(HasBody::class);
            $namespace->addUse(HasBody::class);

            // Use the content type from OpenAPI spec, default to JSON
            if ($endpoint->contentType === 'application/x-www-form-urlencoded') {
                $classType->addTrait(HasFormBody::class);
                $namespace->addUse(HasFormBody::class);
            } elseif ($endpoint->contentType === 'multipart/form-data') {
                // For multipart, we also use HasFormBody in Saloon
                $classType->addTrait(HasFormBody::class);
                $namespace->addUse(HasFormBody::class);
            } else {
                // Default to JSON for 'application/json' or when not specified
                $classType->addTrait(HasJsonBody::class);
                $namespace->addUse(HasJsonBody::class);
            }
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

        // Collect all enum and DTO types that need to be imported
        $enumsToImport = [];
        $dtosToImport = [];
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

            // Check if parameter type looks like a DTO class name
            if (Str::startsWith($parameter->type, Str::studly($parameter->type))) {
                // This is likely a DTO class (starts with uppercase)
                $dtoClass = $parameter->type;
                $dtoFQN = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClass}";
                $dtosToImport[$dtoFQN] = $dtoClass;
            }
        }

        // Import all collected enum types
        foreach ($enumsToImport as $enumFQN => $enumClass) {
            $namespace->addUse($enumFQN);
        }

        // Import all collected DTO types
        foreach ($dtosToImport as $dtoFQN => $dtoClass) {
            $namespace->addUse($dtoFQN);
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
