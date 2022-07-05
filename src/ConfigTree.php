<?php

declare(strict_types=1);

/*
 * This file is part of PhpConfigTree
 */

namespace DgfipSI1\ConfigTree;

use Composer\Json\JsonValidationException;
use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * class RepoMirror
 * Yum repo mirror class
 */
class ConfigTree
{
    /** @var string */
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
            throw new JsonValidationException(sprintf("Schema file not found : '%s'", $schemaFile));
        }
        switch (pathinfo($schemaFile, PATHINFO_EXTENSION)) {
            case 'json':
                $this->schema  = (array) json_decode("$schemaFileContent", true);
                break;
            case 'yml':
            case 'yaml':
                $this->schema  = (array) yaml::parseFile($schemaFile, yaml::DUMP_OBJECT_AS_MAP);
                break;
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
     * Undocumented function
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $properties
     *
     * @return array<string,mixed>
     */
    public function getDefaultOptions(&$options = null, &$properties = null)
    {
        if (null === $options) {
            $options = [];
        }
        if (null === $properties) {
            $properties = $this->schema['properties'];
        }
        if (!is_array($properties)) {
            throw new \Exception('Bad schema : properties should be an array');
        }
        foreach ($properties as $key => $props) {
            if (!is_array($props)) {
                throw new \Exception(sprintf('Bad schema : expecting attributes for property %s', $key));
            }
            if (array_key_exists('default', $props)) {
                $type = $props['type'];
                if (is_array($type)) {
                    $type = $type[0];
                }
                switch ($type) {
                    case 'string':
                        $value = $props['default'];
                        break;
                    case 'null':
                        $value = null;
                        break;
                    case 'boolean':
                        $value = $props['default'] ? true : false;
                        break;
                    case 'integer':
                        $value = 0 + $props['default'];
                        break;
                    case 'array':
                        $value = [];
                        break;
                    default:
                        throw new \Exception(sprintf("Unknown type : '%s'", $type));
                }
                $options[$key] = $value;
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
            throw new JsonValidationException('Validation error: config does not match schema.', $errors);
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
                    throw new \Exception(sprintf("Config branch '%s/%s' does not exist.", $branchName, $node));
                }
                throw new \Exception(sprintf("Option '%s' does not exists or has not been set.", $name));
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
        // print "\n\nSET $name => $value\n";
        // print_r($branch);
        // print "TYPE : ".gettype($branch)."\n\n";
        $branchName = '';
        foreach ($nodes as $node) {
            if (sizeof($nodes) !== $index) {
                if (!array_key_exists($node, $branch)) {
                    throw new \Exception(sprintf("Config branch '%s/%s' does not exist.", $branchName, $node));
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
}
