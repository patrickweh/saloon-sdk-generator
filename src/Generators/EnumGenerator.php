<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;

class EnumGenerator extends Generator
{
    public function generate(ApiSpecification $specification): array
    {
        $enums = $this->collectEnums($specification);
        $results = [];

        foreach ($enums as $enumName => $enumData) {
            $namespace = $this->config->namespace . '\\Enums';
            $className = Str::studly($enumName);

            $file = new PhpFile();
            $file->setStrictTypes();

            $namespace = $file->addNamespace($namespace);
            $enum = $namespace->addEnum($className);

            // Add enum cases
            foreach ($enumData['values'] as $value) {
                $caseName = $this->generateCaseName($value);
                $case = $enum->addCase($caseName);

                // For string enums, set the value
                if (is_string($value)) {
                    $case->setValue($value);
                }
            }

            $results['Enums/' . $className . '.php'] = $file;
        }

        return $results;
    }

    protected function collectEnums(ApiSpecification $specification): array
    {
        $enums = [];

        foreach ($specification->endpoints as $endpoint) {
            // Collect from path parameters
            $this->collectEnumsFromParameters($endpoint->pathParameters, $enums, $endpoint);

            // Collect from query parameters
            $this->collectEnumsFromParameters($endpoint->queryParameters, $enums, $endpoint);

            // Collect from body parameters
            $this->collectEnumsFromParameters($endpoint->bodyParameters, $enums, $endpoint);
        }

        return $enums;
    }

    protected function collectEnumsFromParameters(array $parameters, array &$enums, Endpoint $endpoint): void
    {
        foreach ($parameters as $parameter) {
            if ($parameter->hasEnum()) {
                // Generate a unique enum name based on endpoint and parameter
                $enumKey = $this->generateEnumKey($endpoint, $parameter);

                // Update the parameter's enum name FIRST before collecting
                $parameter->enumName = $enumKey;

                if (! isset($enums[$enumKey])) {
                    $enums[$enumKey] = [
                        'values' => $parameter->enumValues,
                        'description' => $parameter->description,
                        'endpoints' => [],
                    ];
                }

                // Track which endpoints use this enum
                $enums[$enumKey]['endpoints'][] = $endpoint->name;
            }
        }
    }

    protected function generateCaseName(string $value): string
    {
        // Handle empty values
        if (empty($value)) {
            return 'EMPTY';
        }

        // Convert value to valid PHP enum case name
        $name = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $value));

        // Handle completely non-alphanumeric values that result in empty string
        if (empty($name)) {
            return 'VALUE_' . md5($value);
        }

        // Ensure it doesn't start with a number
        if (is_numeric($name[0])) {
            $name = '_' . $name;
        }

        return $name;
    }

    protected function generateEnumKey(Endpoint $endpoint, Parameter $parameter): string
    {
        // For common parameter names, use just the parameter name
        // Otherwise include endpoint context
        $commonNames = ['status', 'type', 'action', 'method', 'state', 'role', 'permission'];

        if (in_array(strtolower($parameter->name), $commonNames)) {
            return ucfirst($parameter->name);
        }

        // Use endpoint group/tag if available, otherwise use endpoint name
        $context = '';
        if (! empty($endpoint->tags)) {
            $context = Str::studly($endpoint->tags[0]);
        } else {
            $context = Str::studly($endpoint->name);
        }

        return $context . ucfirst($parameter->name);
    }
}
