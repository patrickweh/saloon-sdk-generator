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
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
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
     * Determine the collection based on the path and tag
     */
    protected function determineCollection(string $path, ?string $tag): ?string
    {
        // Extract the first segment of the path
        $segments = explode('/', trim($path, '/'));
        $firstSegment = $segments[0] ?? null;

        if (! $firstSegment) {
            return $tag;
        }

        // Map path prefixes to better collection names
        $pathMapping = [
            'dns' => 'Dns',
            'domain' => 'Domains',
            'handle' => 'Handles',
            'mail' => 'Emails',
            'user' => 'Users',
            'auth' => 'Auth',
            'webspace' => 'Webspaces',
            'tls' => 'Ssl',
            'reseller' => 'Resellers',
            'billing' => 'Billing',
            'invoice' => 'Invoices',
            'payment' => 'Payments',
            'rights' => 'Rights',
            'setting' => 'Settings',
            'batch' => 'Batch',
            'ticket' => 'Tickets',
            'maintenance' => 'Maintenance',
            'flexdns' => 'FlexDns',
            'gdpr' => 'Gdpr',
            'pgp' => 'Pgp',
            'vns' => 'Vns',
            'ens' => 'Ens',
            'tld' => 'Tlds',
            'stats' => 'Statistics',
            'prices' => 'Prices',
            'product' => 'Products',
            'affiliate' => 'Affiliates',
            'resellerPrices' => 'ResellerPrices',
            'file' => 'Files',
            'log' => 'Logs',
            'messageQueue' => 'MessageQueue',
            'domainContent' => 'DomainContent',
            'domainParking' => 'DomainParking',
        ];

        // Check if we have a specific mapping for this path prefix
        if (isset($pathMapping[$firstSegment])) {
            return $pathMapping[$firstSegment];
        }

        // Otherwise use the tag or capitalize the first segment
        return $tag ?: ucfirst($firstSegment);
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
        $contentType = null;

        if ($operation->requestBody && $operation->requestBody->content) {
            // Determine content type
            if (isset($operation->requestBody->content['application/json'])) {
                $contentType = 'application/json';
                $content = $operation->requestBody->content['application/json'];
            } elseif (isset($operation->requestBody->content['application/x-www-form-urlencoded'])) {
                $contentType = 'application/x-www-form-urlencoded';
                $content = $operation->requestBody->content['application/x-www-form-urlencoded'];
            } elseif (isset($operation->requestBody->content['multipart/form-data'])) {
                $contentType = 'multipart/form-data';
                $content = $operation->requestBody->content['multipart/form-data'];
            } else {
                $content = null;
            }

            if ($content && isset($content->schema)) {
                $bodyParameters = $this->parseSchemaToParameters($content->schema);
            }
        }

        // Parse response schema for DTO generation
        $responseInfo = null;
        if ($operation->responses && isset($operation->responses['200'])) {
            $response = $operation->responses['200'];
            
            // Handle Reference objects in responses
            if ($response instanceof Reference) {
                // Skip reference responses for now
                $responseInfo = null;
            } elseif ($response->content && isset($response->content['application/json'])) {
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
            collection: $this->determineCollection($path, $operation->tags[0] ?? null),
            response: $responseInfo,
            description: $operation->description,
            contentType: $contentType,
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
                
                // Clean the schema name to ensure valid PHP class name
                $schemaName = preg_replace('/[^a-zA-Z0-9_]/', '', $schemaName);
                if (empty($schemaName) || is_numeric($schemaName[0])) {
                    $schemaName = 'Dto' . $schemaName;
                }

                // Return the schema name to be used as DTO class name
                return [
                    'type' => 'dto',
                    'dto_class' => $schemaName,
                ];
            }
        }

        // For inline schemas, detect DTOs from property names
        if ($schema && isset($schema->properties)) {
            // Map common response properties to their DTO classes
            $dtoMappings = [
                'domain' => 'Domain',
                'domains' => ['type' => 'array', 'dto_class' => 'Domain'],
                'user' => 'User',
                'users' => ['type' => 'array', 'dto_class' => 'User'],
                'reseller' => 'Reseller',
                'resellers' => ['type' => 'array', 'dto_class' => 'Reseller'],
                'webspace' => 'Webspace',
                'webspaces' => ['type' => 'array', 'dto_class' => 'Webspace'],
                'invoice' => 'Invoice',
                'invoices' => ['type' => 'array', 'dto_class' => 'Invoice'],
                'order' => 'Order',
                'orders' => ['type' => 'array', 'dto_class' => 'Order'],
                'tls' => 'Tls',
                'tlsCertificate' => 'TlsCertificate',
                'tlsCertificates' => ['type' => 'array', 'dto_class' => 'Tls'],
                'mail' => 'EmailAddress',
                'mails' => ['type' => 'array', 'dto_class' => 'EmailAddress'],
                'emailAddress' => 'EmailAddress',
                'emailAddresses' => ['type' => 'array', 'dto_class' => 'EmailAddress'],
                'handle' => 'Handle',
                'handles' => ['type' => 'array', 'dto_class' => 'Handle'],
                'ticket' => 'Ticket',
                'tickets' => ['type' => 'array', 'dto_class' => 'Ticket'],
                'batch' => 'BatchProcessing',
                'batches' => ['type' => 'array', 'dto_class' => 'BatchProcessing'],
                'pushRequest' => 'PushRequest',
                'pushRequests' => ['type' => 'array', 'dto_class' => 'PushRequest'],
            ];

            // Check each property to see if it maps to a DTO
            foreach ($schema->properties as $propName => $propSchema) {
                if (isset($dtoMappings[$propName])) {
                    $mapping = $dtoMappings[$propName];
                    if (is_array($mapping)) {
                        return $mapping;
                    }

                    return [
                        'type' => 'dto',
                        'dto_class' => $mapping,
                    ];
                }
            }

            // For inline schemas that don't match known patterns, return as inline
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
                    
                    // Clean the schema name to ensure valid PHP class name
                    $schemaName = preg_replace('/[^a-zA-Z0-9_]/', '', $schemaName);
                    if (empty($schemaName) || is_numeric($schemaName[0])) {
                        $schemaName = 'Dto' . $schemaName;
                    }

                    return [
                        'type' => 'array',
                        'dto_class' => $schemaName,
                    ];
                }
            }

            // For direct array responses (like domain/list that returns array of domains)
            // Check if this is likely a list endpoint that should return DTOs
            // This is a bit of a hack but necessary for APIs that return arrays directly
            if ($schema->items && isset($schema->items->properties)) {
                // Check if the items have properties that look like a DTO
                foreach ($dtoMappings as $propName => $dtoClass) {
                    if (isset($schema->items->properties->$propName)) {
                        // Found a property that matches a DTO pattern
                        // Determine the DTO based on the property
                        if (is_array($dtoClass) && isset($dtoClass['dto_class'])) {
                            return [
                                'type' => 'array',
                                'dto_class' => $dtoClass['dto_class'],
                            ];
                        }
                    }
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
                if (isset($this->openApi->components?->schemas[$schemaName])) {
                    $schema = $this->openApi->components->schemas[$schemaName];
                }
            }
        }

        // Parse properties from schema
        if ($schema && isset($schema->properties)) {
            foreach ($schema->properties as $propertyName => $property) {
                $isRequired = in_array($propertyName, $schema->required ?? []);
                $type = 'mixed';
                $enumValues = null;
                $enumName = null;
                $description = null;

                // Handle property references (complex objects)
                if ($property instanceof Reference) {
                    $refPath = $property->getReference();
                    if (str_starts_with($refPath, '#/components/schemas/')) {
                        $schemaName = str_replace('#/components/schemas/', '', $refPath);
                        // Recursively expand the referenced schema into individual properties
                        if (isset($this->openApi->components?->schemas[$schemaName])) {
                            $referencedSchema = $this->openApi->components->schemas[$schemaName];
                            if ($referencedSchema && isset($referencedSchema->properties)) {
                                // Expand each property of the referenced schema with a prefix
                                foreach ($referencedSchema->properties as $subPropName => $subProperty) {
                                    $prefixedName = $propertyName . '_' . $subPropName;
                                    $subIsRequired = in_array($subPropName, $referencedSchema->required ?? []);
                                    $subType = 'mixed';
                                    $subDescription = null;

                                    if (! ($subProperty instanceof Reference)) {
                                        $subType = $this->mapSchemaTypeToPhpType($subProperty->type ?? 'mixed');
                                        $subDescription = $subProperty->description ?? null;
                                    }

                                    $parameters[] = new Parameter(
                                        type: $subType,
                                        nullable: true, // All sub-properties should be nullable since the parent is optional
                                        name: $prefixedName,
                                        description: $subDescription,
                                        enumValues: null,
                                        enumName: null,
                                        parentProperty: $propertyName // Track the parent property
                                    );
                                }

                                // Skip adding the parent property since we've expanded it
                                continue;
                            }
                        }
                        // Fallback to using the DTO class name if we can't expand
                        $type = NameHelper::dtoClassName($schemaName);
                    } else {
                        $type = 'mixed';
                    }
                } else {
                    // Simple property
                    $type = $this->mapSchemaTypeToPhpType($property->type ?? 'mixed');
                    $description = $property->description ?? null;
                    $enumValues = $property->enum ?? null;

                    if ($enumValues) {
                        // Generate enum name from property name
                        $enumName = ucfirst($propertyName);
                    }

                    // Handle arrays with items
                    if ($property->type === 'array' && isset($property->items)) {
                        if ($property->items instanceof Reference) {
                            $refPath = $property->items->getReference();
                            if (str_starts_with($refPath, '#/components/schemas/')) {
                                $itemSchemaName = str_replace('#/components/schemas/', '', $refPath);
                                $type = 'array'; // Could be enhanced to track array of specific DTOs
                            }
                        } else {
                            $type = 'array';
                        }
                    }
                }

                $parameters[] = new Parameter(
                    type: $type,
                    nullable: ! $isRequired,
                    name: $propertyName,
                    description: $description,
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
