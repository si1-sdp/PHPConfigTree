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
use DgfipSI1\ConfigTree\Exception\RuntimeException;
use DgfipSI1\ConfigTree\Exception\ValueNotFoundException;
use JsonSchema\Constraints\Constraint;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * class RepoMirror
 * Yum repo mirror class
 */
class ConfigTree
{
    protected const STATUS_OK = 1;
    protected const STATUS_NO_VALUE = 2;
    protected const STATUS_NO_BRANCH = 3;

    public const NULL_ON_NO_BRANCH    = 1;
    public const NULL_ON_NO_VALUE     = 2;
    public const CREATE_BRANCH_ON_GET = 4;

    /** @var \stdClass*/
    protected $options;

    /** @var mixed */
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
            throw new SchemaValidationException(sprintf("Error reading schema file '%s'", $schemaFile));
        }
        switch (pathinfo($schemaFile, PATHINFO_EXTENSION)) {
            case 'json':
                $this->schema  = json_decode("$schemaFileContent", true);
                break;
            case 'yml':
            case 'yaml':
                $this->schema  = yaml::parseFile($schemaFile, yaml::PARSE_OBJECT_FOR_MAP | yaml::PARSE_OBJECT);
                break;
            default:
                $msg = "Unsupported extension for : '%s'\nSupported schema types : yaml or json.";
                throw new SchemaValidationException(sprintf($msg, $schemaFile));
        }
        $this->options = new \stdClass();
        $this->check(); // generate default options
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
        try {
            $config  = (array) yaml::parseFile($configFile, yaml::PARSE_OBJECT | yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $e) {
            $error = "Exception parsing ".$e->getParsedFile()." at line ".$e->getParsedLine()." : ".$e->getSnippet();
            throw new RuntimeException("$error");
        }
        $instance->merge($config);

        return $instance;
    }
    /**
     * Check properties against shema
     *
     * @return bool
     */
    public function check()
    {
        $validator      = new Validator();
        $validatorError = "Validation error:\n";
        try {
            $validator->validate(
                $this->options,
                $this->schema,
                Constraint::CHECK_MODE_APPLY_DEFAULTS |
                Constraint::CHECK_MODE_TYPE_CAST |
                Constraint::CHECK_MODE_COERCE_TYPES |
                Constraint::CHECK_MODE_VALIDATE_SCHEMA
            );
        } catch (\Exception | \TypeError $e) {
            $validatorError = sprintf('Validation error: %s\n', $e->getMessage());
        }

        if (!$validator->isValid()) {
            foreach ((array) $validator->getErrors() as $error) {
                $validatorError .= " - ".($error['property'] ? $error['property'].' : ' : '').$error['message']."\n";
            }
            throw new SchemaValidationException($validatorError);
        }

        return true;
    }
    /**
     * @return \stdClass
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * getters
     *
     * @param string  $name
     * @param integer $options
     *
     * @return mixed
     */
    public function get($name, $options = 0)
    {

        $nullOnNoBranch   = ($options & self::NULL_ON_NO_BRANCH)    === self::NULL_ON_NO_BRANCH;
        $nullOnNoValue    = ($options & self::NULL_ON_NO_VALUE)     === self::NULL_ON_NO_VALUE;
        $autoCreateBranch = ($options & self::CREATE_BRANCH_ON_GET) === self::CREATE_BRANCH_ON_GET;

        $branch = &$this->options;
        $nodes = explode('.', $name);
        $index = 1;
        $branchName = '';
        $status = self::STATUS_OK;
        $retValue = null;
        foreach ($nodes as $node) {
            $branchName = "$branchName.$node";
            if (!property_exists($branch, $node)) {
                // node not found, and whe're not at leaf yet => Branch not found
                if (sizeof($nodes) !== $index) {
                    if ($autoCreateBranch) {
                        $this->set(substr($branchName, 1), new \stdClass());
                    } else {
                        $status = self::STATUS_NO_BRANCH;
                    }
                } else {
                    $status = self::STATUS_NO_VALUE;
                }
                if (self::STATUS_OK !== $status) {
                    break;
                }
            }
            if (sizeof($nodes) !== $index) {
                $index++;
                /** @var \stdClass $branch */
                $branch = &$branch->$node;
            } else {
                $retValue = $branch->$node;
                break;
            }
        }
        $branchName = substr($branchName, 1);
        switch ($status) {
            case self::STATUS_NO_BRANCH:
                if (!$nullOnNoBranch) {
                    $msg = "Config branch '%s' does not exist.";
                    throw new BranchNotFoundException(sprintf($msg, $branchName));
                }
                break;
            case self::STATUS_NO_VALUE:
                if (!$nullOnNoValue) {
                    $msg = "Option '%s' does not exists or has not been set.";
                    throw new ValueNotFoundException(sprintf($msg, $name));
                }
                break;
            default:
                // status OK
        }

        return $retValue;
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
        if (is_array($value) && !array_key_exists(0, $value)) {
            $value = self::toObject($value);
        }
        $branch = &$this->options;
        $nodes = explode('.', $name);
        $index = 1;
        $branchName = '';
        foreach ($nodes as $node) {
            if (sizeof($nodes) !== $index) {
                /** @var \stdClass $branch */
                if (!property_exists($branch, $node)) {
                    $branch->$node = new \stdClass();
                }
                $branch = &$branch->$node;
                /** @var array<string,mixed> $branch */
            } else {
                $branch->$node = $value;
            }
            $index++;
            $branchName = "$branchName.$node";
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
            $this->set("$optName", $optValue, false);
        }
        $this->check();
    }
    /**
     * print configuration
     *
     * @return void
     */
    public function print()
    {
        $yaml = Yaml::dump($this->options, 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
        print $yaml;
    }
    /**
     * Traverse tree and change every associative array to a stdClass with properties
     * (we consider an array to be associative if array[0] does not exists)
     *
     * @param array<string,mixed> $array
     *
     * @return \stdClass
     */
    private static function toObject($array)
    {
        $obj = new \stdClass();
        foreach ($array as $key => $value) {
            $obj->$key = (is_array($value) && !array_key_exists(0, $value)) ? self::toObject($value) : $value;
        }

        return $obj;
    }
}
