<?php

declare(strict_types=1);

/*
 * This file is part of PhpConfigTree
 */

namespace DgfipSI1\ConfigTree;

use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use DgfipSI1\ConfigTree\Exception\SchemaValidationException;
use DgfipSI1\ConfigTree\Exception\BranchNotFoundException;
use DgfipSI1\ConfigTree\Exception\ValueNotFoundException;

/**
 * class RepoMirror
 * Yum repo mirror class
 */
class ConfigTree
{
    /** @var array<string,mixed> */
    protected $options = [];

    /** @var array<string,mixed> */
    protected $schema = null;
    /**
     * Constructor
     *
     * @param string $schemaFile
     *
     * @return void
     */
    public function __construct($schemaFile)
    {
        try {
            $schemaFileContent = file_get_contents($schemaFile);
        } catch (\exception $e) {
            throw new SchemaValidationException(sprintf("Schema file not found : '%s'", $schemaFile));
        }
        switch (pathinfo($schemaFile, PATHINFO_EXTENSION)) {
            case 'json':
                $this->schema  = (array) json_decode("$schemaFileContent", true);
                break;
            case 'yml':
            case 'yaml':
                $this->schema  = (array) yaml::parseFile($schemaFile, yaml::DUMP_OBJECT_AS_MAP);
                break;
            default:
                $msg = "Unsupported extension for : '%s'\nSupported schema types : yaml or json.";
                throw new SchemaValidationException(sprintf($msg, $schemaFile));
        }
        $this->options = $this->getDefaultOptions();
    }
    /**
     * Build ConfigTree Object from flat array
     *
     * @param string              $schemaFile
     * @param array<string,mixed> $options
     *
     * @return self
     */
    public static function fromArray($schemaFile, $options)
    {
        $instance = new self($schemaFile);
        $instance->merge($options);

        return $instance;
    }

    /**
     * Build ConfigTree Object from yaml file
     *
     * @param string $schemaFile
     * @param string $configFile
     *
     * @return self
     */
    public static function fromFile($schemaFile, $configFile)
    {
        $instance = new self($schemaFile);
        $config  = (array) yaml::parseFile($configFile, yaml::DUMP_OBJECT_AS_MAP);
        $instance->merge($config);

        return $instance;
    }

    /**
     * Undocumented function
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $properties
     *
     * @return array<string,mixed>
     */
    public function getDefaultOptions(&$options = [], &$properties = null)
    {
        if (null === $properties) {
            $properties = $this->schema['properties'];
        }
        if (!is_array($properties)) {
            throw new SchemaValidationException('Bad schema : properties should be an array');
        }
        foreach ($properties as $key => $props) {
            if (!is_array($props)) {
                $msg = 'Bad schema : expecting attributes for property %s';
                throw new SchemaValidationException(sprintf($msg, $key));
            }
            $type = $props['type'];
            if (array_key_exists('default', $props)) {
                $options[$key] = $this->getDefaultValue($type, $props['default']);
            } elseif ('array' === $type) {
                $options[$key] = [];
            }
            if (array_key_exists('properties', $props)) {
                $this->getDefaultOptions($options[$key], $props['properties']);
            }
        }

        return $options;
    }
    /**
     * Check properties against shema
     *
     * @return bool
     */
    public function check()
    {
        $validator = new Validator();
        $validator->check($this->options, $this->schema);
        if (!$validator->isValid()) {
            $errors = [];
            foreach ((array) $validator->getErrors() as $error) {
                $errors[] = ($error['property'] ? $error['property'].' : ' : '').$error['message'];
            }
            throw new SchemaValidationException('Validation error: config does not match schema.', $errors);
        }

        return true;
    }
    /**
     * getters
     *
     * @param string $name
     *
     * @return mixed
     */
    public function get(string $name)
    {
        $branch = &$this->options;
        $nodes = explode('.', $name);
        $index = 1;
        $branchName = '';
        foreach ($nodes as $node) {
            if (!array_key_exists($node, $branch)) {
                if (sizeof($nodes) !== $index) {
                    $msg = "Config branch '%s/%s' does not exist.";
                    throw new BranchNotFoundException(sprintf($msg, $branchName, $node));
                }
                $msg = "Option '%s' does not exists or has not been set.";
                throw new ValueNotFoundException(sprintf($msg, $name));
            }
            if (sizeof($nodes) !== $index) {
                $index++;
                $branchName = "$branchName/$node";
                /** @var array<string,mixed> $branch */
                $branch = &$branch[$node];
            } else {
                return $branch[$node];
            }
        }
    }
    /**
     * Setters
     *
     * @param string $name
     * @param mixed  $value
     * @param bool   $check
     *
     * @return self
     */
    public function set($name, $value, $check = true)
    {
        $branch = &$this->options;
        $nodes = explode('.', $name);
        $index = 1;
        $branchName = '';
        foreach ($nodes as $node) {
            if (sizeof($nodes) !== $index) {
                if (!array_key_exists($node, $branch)) {
                    $msg = "Config branch '%s/%s' does not exist.";
                    throw new BranchNotFoundException(sprintf($msg, $branchName, $node));
                }
                /** @var array<string,mixed> $branch */
                $branch = &$branch[$node];
            } else {
                $branch[$node] = $value;
            }
            $index++;
            $branchName = "$branchName/$node";
        }
        if ($check) {
            $this->check();
        }

        return $this;
    }
    /**
     * merge options given to target array
     *
     * @param array<string,mixed> $options
     *
     * @return void
    */
    public function merge($options)
    {
        foreach ($options as $optName => $optValue) {
            $this->set($optName, $optValue, false);
        }
        $this->check();
    }
    /**
     * Giving type and default string, return typed default value
     *
     * @param string|array<string> $type
     * @param string               $default
     *
     * @return mixed
     */
    protected function getDefaultValue($type, $default)
    {
        if (is_array($type)) {
            $type = $type[0];
        }
        switch ($type) {
            case 'string':
                $value = $default;
                break;
            case 'null':
                $value = null;
                break;
            case 'boolean':
                $value = $default ? true : false;
                break;
            case 'integer':
                $value = intval($default);
                break;
            case 'array':
                $value = [];
                break;
            default:
                throw new SchemaValidationException(sprintf("Unknown type : '%s'", $type));
        }

        return $value;
    }
}
