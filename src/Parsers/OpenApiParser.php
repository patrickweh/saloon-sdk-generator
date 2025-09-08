<?php

namespace Crescat\SaloonSdkGenerator\Parsers;

use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\Components;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter as OpenApiParameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Paths;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\SecurityRequirement;
use cebe\openapi\spec\Server;
use cebe\openapi\spec\Type;
use Crescat\SaloonSdkGenerator\Contracts\Parser;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiKeyLocation;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\BaseUrl;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Method;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Data\Generator\SecurityScheme;
use Crescat\SaloonSdkGenerator\Data\Generator\SecuritySchemeType;
use Crescat\SaloonSdkGenerator\Data\Generator\ServerParameter;
use Illuminate\Support\Str;

class OpenApiParser implements Parser
{
    public function __construct(protected OpenApi $openApi) {}

    public static function build($content): self
    {
        return new self(
            Str::endsWith($content, '.json')
                ? Reader::readFromJsonFile(fileName: realpath($content), resolveReferences: ReferenceContext::RESOLVE_MODE_INLINE)
                : Reader::readFromYamlFile(fileName: realpath($content), resolveReferences: ReferenceContext::RESOLVE_MODE_INLINE)
        );
    }

    public function parse(): ApiSpecification
    {
        return new ApiSpecification(
            name: $this->openApi->info->title,
            description: $this->openApi->info->description,
            baseUrl: $this->parseBaseUrl($this->openApi->servers),
            securityRequirements: $this->openApi->security !== null ? $this->parseSecurityRequirements($this->openApi->security->getSerializableData()) : [],
            components: $this->parseComponents($this->openApi->components),
            endpoints: $this->parseItems($this->openApi->paths)
        );
    }

    /**
     * @param  array  $parameters  Array of OpenApiParameter or Reference objects
     * @return Parameter[] array
     */
    protected function mapParams(array $parameters, string $in): array
    {
        return collect($parameters)
            ->map(function ($parameter) {
                // Resolve Reference objects to their actual Parameter objects
                if ($parameter instanceof Reference) {
                    // When using RESOLVE_MODE_INLINE, we need to manually resolve the reference
                    $refPath = $parameter->getReference();

                    // Parse the reference path (e.g., "#/components/parameters/PathAlbumId")
                    if (str_starts_with($refPath, '#/components/parameters/')) {
                        $paramName = str_replace('#/components/parameters/', '', $refPath);

                        // Check if the parameter exists in components
                        if (isset($this->openApi->components->parameters[$paramName])) {
                            $resolvedParam = $this->openApi->components->parameters[$paramName];

                            // The resolved parameter might itself be a Reference in some cases
                            if ($resolvedParam instanceof Reference) {
                                return null;
                            }

                            return $resolvedParam;
                        }
                    }

                    return null;
                }

                return $parameter;
            })
            ->filter() // Remove any nulls from failed resolutions
            ->whereInstanceOf(OpenApiParameter::class)
            ->filter(fn (OpenApiParameter $parameter) => $parameter->in == $in)
            ->map(function (OpenApiParameter $parameter) {
                $enumValues = $parameter->schema?->enum ?? null;
                $enumName = null;

                if ($enumValues) {
                    // Generate enum name from parameter name
                    // Keep simple for common names, otherwise will be updated by EnumGenerator
                    $enumName = ucfirst($parameter->name);
                }

                return new Parameter(
                    type: $this->mapSchemaTypeToPhpType($parameter->schema?->type),
                    nullable: $parameter->required == false,
                    name: $parameter->name,
                    description: $parameter->description,
                    enumValues: $enumValues,
                    enumName: $enumName
                );
            })
            ->values() // Reset array keys
            ->all();
    }

    protected function mapSchemaTypeToPhpType($type): string
    {
        return match ($type) {
            Type::INTEGER => 'int',
            Type::NUMBER => 'float|int', // TODO: is "number" always a float in openapi specs?
            Type::STRING => 'string',
            Type::BOOLEAN => 'bool',
            Type::OBJECT, Type::ARRAY => 'array',
            default => 'mixed',
        };
    }

    /**
     * @param  Server[]  $servers
     */
    protected function parseBaseUrl(?array $servers): BaseUrl
    {
        /** @var Server $server */
        $server = array_shift($servers);
        if (is_null($server->variables)) {
            return new BaseUrl('');
        }

        $parameters = [];
        foreach ($server->variables as $name => $variable) {
            $parameters[] = new ServerParameter($name, $variable->default, $variable->description);
        }

        return new BaseUrl($server->url, $parameters);
    }

    protected function parseComponents(?Components $components): \Crescat\SaloonSdkGenerator\Data\Generator\Components
    {
        if (! $components) {
            return new \Crescat\SaloonSdkGenerator\Data\Generator\Components();
        }

        $securitySchemes = [];
        foreach ($components->securitySchemes as $securityScheme) {
            $securitySchemes[] = new SecurityScheme(
                type: SecuritySchemeType::tryFrom($securityScheme->type),
                name: $securityScheme->name,
                in: ApiKeyLocation::tryFrom($securityScheme->in ?? ''),
                scheme: $securityScheme->scheme,
                bearerFormat: $securityScheme->bearerFormat,
                description: $securityScheme->description,
                flows: $securityScheme->flows,
                openIdConnectUrl: $securityScheme->openIdConnectUrl
            );
        }

        return new \Crescat\SaloonSdkGenerator\Data\Generator\Components(
            schemas: $components->schemas,
            securitySchemes: $securitySchemes
        );
    }

    protected function parseEndpoint(Operation $operation, $pathParams, string $path, string $methodName): ?Endpoint
    {
        // Parse body parameters from requestBody
        $bodyParameters = [];
        if ($operation->requestBody && $operation->requestBody->content) {
            // Check for JSON or form-encoded content
            $content = $operation->requestBody->content['application/json']
                ?? $operation->requestBody->content['application/x-www-form-urlencoded']
                ?? $operation->requestBody->content['multipart/form-data']
                ?? null;

            if ($content && isset($content->schema)) {
                $bodyParameters = $this->parseSchemaToParameters($content->schema);
            }
        }

        // Parse response schema for DTO generation
        $responseInfo = null;
        if ($operation->responses && isset($operation->responses['200'])) {
            $response = $operation->responses['200'];
            if ($response->content && isset($response->content['application/json'])) {
                $jsonContent = $response->content['application/json'];
                if ($jsonContent->schema) {
                    $responseInfo = $this->parseResponseSchema($jsonContent->schema);
                }
            }
        }

        return new Endpoint(
            name: trim($operation->operationId ?: $operation->summary ?: ''),
            method: Method::parse($methodName),
            pathSegments: Str::of($path)->replace('{', ':')->remove('}')->trim('/')->explode('/')->toArray(),
            collection: $operation->tags[0] ?? null, // In the real-world, people USUALLY only use one tag...
            response: $responseInfo,
            description: $operation->description,
            queryParameters: $this->mapParams($operation->parameters ?? [], 'query'),
            // TODO: Check if this differs between spec versions
            pathParameters: $pathParams + $this->mapParams($operation->parameters ?? [], 'path'),
            bodyParameters: $bodyParameters,
            headerParameters: $this->mapParams($operation->parameters ?? [], 'header'),
        );
    }

    /**
     * @return array|Endpoint[]
     */
    protected function parseItems(?Paths $items): array
    {
        if (! $items) {
            return [];
        }

        $requests = [];

        foreach ($items as $path => $item) {
            if ($item instanceof PathItem) {
                foreach ($item->getOperations() as $method => $operation) {
                    // TODO: variables for the path
                    $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
                }
            }
        }

        return $requests;
    }

    protected function parseResponseSchema($schema): ?array
    {
        // Handle schema references
        if ($schema instanceof Reference) {
            $refPath = $schema->getReference();
            if (str_starts_with($refPath, '#/components/schemas/')) {
                $schemaName = str_replace('#/components/schemas/', '', $refPath);

                // Return the schema name to be used as DTO class name
                return [
                    'type' => 'dto',
                    'dto_class' => $schemaName,
                ];
            }
        }

        // For inline schemas, check if it has properties that could be a DTO
        if ($schema && isset($schema->properties)) {
            // Generate a DTO name based on the endpoint (this will be handled by RequestGenerator)
            return [
                'type' => 'inline',
                'schema' => $schema,
            ];
        }

        // For array responses, check the items
        if ($schema && $schema->type === 'array' && isset($schema->items)) {
            if ($schema->items instanceof Reference) {
                $refPath = $schema->items->getReference();
                if (str_starts_with($refPath, '#/components/schemas/')) {
                    $schemaName = str_replace('#/components/schemas/', '', $refPath);

                    return [
                        'type' => 'array',
                        'dto_class' => $schemaName,
                    ];
                }
            }
        }

        return null;
    }

    protected function parseSchemaToParameters($schema): array
    {
        $parameters = [];

        // Handle schema references
        if ($schema instanceof Reference) {
            $refPath = $schema->getReference();
            if (str_starts_with($refPath, '#/components/schemas/')) {
                $schemaName = str_replace('#/components/schemas/', '', $refPath);
                if (isset($this->components?->schemas[$schemaName])) {
                    $schema = $this->components->schemas[$schemaName];
                }
            }
        }

        // Parse properties from schema
        if ($schema && isset($schema->properties)) {
            foreach ($schema->properties as $propertyName => $property) {
                // Skip if property is a reference (complex object)
                if ($property instanceof Reference) {
                    continue;
                }

                $isRequired = in_array($propertyName, $schema->required ?? []);
                $enumValues = $property->enum ?? null;
                $enumName = null;

                if ($enumValues) {
                    // Generate enum name from property name
                    // Keep simple for common names, otherwise will be updated by EnumGenerator
                    $enumName = ucfirst($propertyName);
                }

                $parameters[] = new Parameter(
                    type: $this->mapSchemaTypeToPhpType($property->type ?? 'mixed'),
                    nullable: ! $isRequired,
                    name: $propertyName,
                    description: $property->description ?? null,
                    enumValues: $enumValues,
                    enumName: $enumName
                );
            }
        }

        return $parameters;
    }

    /**
     * @return \Crescat\SaloonSdkGenerator\Data\Generator\SecurityRequirement[]
     */
    protected function parseSecurityRequirements(array $security): array
    {
        $securityRequirements = [];

        foreach ($security as $key => $securityOption) {
            // Handle case where it's already an array (from SecurityRequirements->getSerializableData())
            if (is_array($securityOption)) {
                foreach ($securityOption as $name => $scopes) {
                    $securityRequirements[] = new \Crescat\SaloonSdkGenerator\Data\Generator\SecurityRequirement(
                        $name,
                        $scopes
                    );
                }

                continue;
            }

            // Handle case where it's a SecurityRequirement object
            $data = $securityOption->getSerializableData();
            if (gettype($data) !== 'object') {
                continue;
            }

            $securityProperties = get_object_vars($data);

            foreach ($securityProperties as $name => $scopes) {
                $securityRequirements[] = new \Crescat\SaloonSdkGenerator\Data\Generator\SecurityRequirement(
                    $name,
                    $scopes
                );
            }
        }

        return $securityRequirements;
    }
}
