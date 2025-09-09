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

            // All enums should be backed string enums for consistency
            // This ensures they can be used with ->value in the generated code
            $enum->setType('string');

            // Add enum cases
            foreach ($enumData['values'] as $value) {
                $caseName = $this->generateCaseName($value);
                $case = $enum->addCase($caseName);

                // Always set the value for backed enums
                // Convert to string if not already
                $case->setValue((string)$value);
            }

            $results['Enums/' . $className . '.php'] = $file;
        }

        return $results;
    }

    protected function collectEnums(ApiSpecification $specification): array
    {
        $enums = [];
        $enumSignatures = []; // Track enum value signatures to detect duplicates

        foreach ($specification->endpoints as $endpoint) {
            // Collect from all parameter types
            $allParameters = array_merge(
                $endpoint->pathParameters,
                $endpoint->queryParameters,
                $endpoint->bodyParameters
            );

            foreach ($allParameters as $parameter) {
                if ($parameter->hasEnum()) {
                    // Create a signature for these enum values
                    $signature = $this->createEnumSignature($parameter->enumValues);
                    
                    // Check if we already have an enum with these exact values
                    if (isset($enumSignatures[$signature])) {
                        // Reuse the existing enum name
                        $parameter->enumName = $enumSignatures[$signature];
                    } else {
                        // Generate a new enum name
                        $enumKey = $this->generateEnumKey($endpoint, $parameter);
                        
                        // Store the signature mapping
                        $enumSignatures[$signature] = $enumKey;
                        $parameter->enumName = $enumKey;
                        
                        // Add to enums collection
                        $enums[$enumKey] = [
                            'values' => $parameter->enumValues,
                            'description' => $parameter->description,
                            'endpoints' => [$endpoint->name],
                        ];
                    }
                }
            }
        }

        return $enums;
    }

    /**
     * Create a unique signature for a set of enum values
     */
    protected function createEnumSignature(array $values): string
    {
        // Sort values to ensure consistent signatures
        sort($values);
        return json_encode($values);
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
        // Common parameter names that should be shared across endpoints
        $commonParameterNames = [
            'status' => 'Status',
            'type' => 'Type',
            'action' => 'Action',
            'method' => 'Method',
            'state' => 'State',
            'role' => 'Role',
            'permission' => 'Permission',
            'mode' => 'Mode',
            'twoFaMethod' => 'TwoFaMethod',
            'two_fa_method' => 'TwoFaMethod',
            'section' => 'Section',
            'value' => 'Value',
            'redirectMode' => 'RedirectMode',
            'redirect_mode' => 'RedirectMode',
            'redirectCode' => 'RedirectCode',
            'redirect_code' => 'RedirectCode',
            'deleteMode' => 'DeleteMode',
            'delete_mode' => 'DeleteMode',
            'vendor' => 'Vendor',
            'resellerID' => 'ResellerID',
            'reseller_id' => 'ResellerID',
            'stateFilter' => 'StateFilter',
            'state_filter' => 'StateFilter',
            'selectedInterval' => 'SelectedInterval',
            'selected_interval' => 'SelectedInterval',
            'period' => 'Period',
            'tld' => 'Tld',
            'software' => 'Software',
            'group' => 'Group',
            'product' => 'Product',
            'products' => 'Products',
            'round' => 'Round',
            'settingsMode' => 'SettingsMode',
            'settings_mode' => 'SettingsMode',
            'userSettingsMode' => 'UserSettingsMode',
            'user_settings_mode' => 'UserSettingsMode',
            'rightsMode' => 'RightsMode',
            'rights_mode' => 'RightsMode',
            'rightsGroupsMode' => 'RightsGroupsMode',
            'rights_groups_mode' => 'RightsGroupsMode',
            'rightsCategoryMode' => 'RightsCategoryMode',
            'rights_category_mode' => 'RightsCategoryMode',
            'inheritanceMode' => 'InheritanceMode',
            'inheritance_mode' => 'InheritanceMode',
            'subresellerInheritanceMode' => 'SubresellerInheritanceMode',
            'subreseller_inheritance_mode' => 'SubresellerInheritanceMode',
            'subuserInheritanceMode' => 'SubuserInheritanceMode',
            'subuser_inheritance_mode' => 'SubuserInheritanceMode',
            'renewalMode' => 'RenewalMode',
            'renewal_mode' => 'RenewalMode',
            'expireDays' => 'ExpireDays',
            'expire_days' => 'ExpireDays',
            'ipVersion' => 'IpVersion',
            'ip_version' => 'IpVersion',
            'flexDNSipVersion' => 'IpVersion',
        ];

        // Check if this is a common parameter that should be shared
        $paramName = $parameter->name;
        if (isset($commonParameterNames[$paramName])) {
            return $commonParameterNames[$paramName];
        }
        
        // For parameters like 'twoFaMethod' that appear in multiple endpoints
        // Use a normalized name without the endpoint prefix
        $normalizedName = $this->normalizeParameterName($paramName);
        if (in_array($normalizedName, ['TwoFaMethod', 'Section', 'Value', 'RedirectMode', 'RedirectCode', 'SettingsMode', 'RightsMode'])) {
            return $normalizedName;
        }

        // Remove HTTP method prefixes from endpoint name
        $httpMethods = ['get_', 'post_', 'put_', 'patch_', 'delete_', 'head_', 'options_'];
        $endpointName = $endpoint->name;
        
        foreach ($httpMethods as $method) {
            if (str_starts_with(strtolower($endpointName), $method)) {
                $endpointName = substr($endpointName, strlen($method));
                break;
            }
        }

        // Use endpoint group/tag if available, otherwise use cleaned endpoint name
        $context = '';
        if (! empty($endpoint->tags)) {
            $context = Str::studly($endpoint->tags[0]);
        } else {
            $context = Str::studly($endpointName);
        }

        return $context . ucfirst($this->normalizeParameterName($paramName));
    }
    
    /**
     * Normalize parameter names to a consistent format
     */
    protected function normalizeParameterName(string $name): string
    {
        // Convert snake_case to PascalCase
        $name = str_replace('_', ' ', $name);
        $name = ucwords($name);
        $name = str_replace(' ', '', $name);
        
        return $name;
    }
}