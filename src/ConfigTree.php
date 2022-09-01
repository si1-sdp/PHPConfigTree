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

    /** @var \stdClass */
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
        if (!is_readable($schemaFile)) {
            throw new SchemaValidationException(sprintf("Error reading schema file '%s'", $schemaFile));
        }
        switch (pathinfo($schemaFile, PATHINFO_EXTENSION)) {
            case 'json':
                $schemaFileContent = file_get_contents($schemaFile);
                /** @var \stdClass $schema */
                $schema = json_decode("$schemaFileContent");
                $this->schema  = $schema;
                break;
            case 'yml':
            case 'yaml':
                /** @var \stdClass $schema */
                $schema  = yaml::parseFile($schemaFile, yaml::PARSE_OBJECT_FOR_MAP);
                $this->schema  = $schema;
                break;
            default:
                $msg = "Unsupported extension for : '%s'\nSupported schema types : yaml or json.";
                throw new SchemaValidationException(sprintf($msg, $schemaFile));
        }
        $this->options = $this->parseSchema();
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
     * parse schema to build a default tree
     *
     * @param \stdClass $options
     * @param \stdClass $schemaProps
     *
     * @return \stdClass
     */
    public function parseSchema(&$options = null, $schemaProps = null)
    {
        if (null === $options) {
            $options = new \stdClass();
        }
        if (null === $schemaProps) {
            $schemaProps = $this->schema->properties;
        }
        if (!(is_object($schemaProps) && 'stdClass' === get_class($schemaProps))) {
            throw new SchemaValidationException("Validation error: schema properties should be a stdClass object");
        }
        foreach ((array) $schemaProps as $key => $props) {
            if (!is_object($props) || 'stdClass' !== get_class($props)) {
                $msg = 'Bad schema : expecting attributes for property %s';
                throw new SchemaValidationException(sprintf($msg, $key));
            }
            if (property_exists($props, 'type') && 'object' === $props->type) {
                $options->$key = new \stdClass();
            }
            if (property_exists($props, '$ref')) {
                $name = '$ref';
                $ref = $props->$name;
                if (strpos($ref, '#') === false) {
                    $err = "Error parsing ref %s.  (nb: only local refs are supported right now)";
                    throw new SchemaValidationException(sprintf($err, $ref));
                }
                $elements = explode('/', substr($ref, strpos($ref, '#')+2));
                $root = $this->schema;
                foreach ($elements as $path) {
                    if (!property_exists($root, $path)) {
                        throw new SchemaValidationException("Can't locate ref $path in schema");
                    }
                    $root = $root->$path;
                }
                if (property_exists($root, 'properties')) {
                    $options->$key = new \stdClass();
                    $this->parseSchema($options->$key, $root->properties);
                }
            }
            if (property_exists($props, 'properties')) {
                $this->parseSchema($options->$key, $props->properties);
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
        $validator      = new Validator();
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
            throw new SchemaValidationException($validatorError);
        }

        if (!$validator->isValid()) {
            $validatorError = "Validation error:\n";
            foreach ((array) $validator->getErrors() as $error) {
                $validatorError .= " - ".($error['property'] ? $error['property'].' : ' : '').$error['message']."\n";
            }
            throw new SchemaValidationException($validatorError);
        }

        return true;
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

        return self::toArray($retValue);
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
        if (null !== $options) {
            foreach ($options as $optName => $optValue) {
                $this->set("$optName", $optValue, false);
            }
        }
        $this->check();
    }

    /**
     * print configuration
     *
     * @param bool $return If this parameter is set to true, print will return its output,
     *                     instead of printing it (which it does by default).
     *
     * @return string|void
     */
    public function print($return = false)
    {
        $yaml = Yaml::dump($this->options, 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
        if (!$return) {
            print $yaml;
        } else {
            return $yaml;
        }
    }
    /**
     * Traverse tree and change every associative array to a stdClass with properties
     * (we consider an array to be associative if array[0] does not exists)
     *
     * @param mixed $item
     *
     * @return mixed
     */
    private static function toObject($item)
    {
        if (is_array($item) && !array_key_exists(0, $item)) {
            $obj = new \stdClass();
            foreach ($item as $key => $value) {
                $obj->$key = self::toObject($value);
            }

            return $obj;
        }

        return $item;
    }
    /**
     * Traverse object tree and change every stdClass object to an associative array
     *
     * @param mixed $item
     *
     * @return mixed
     */
    private static function toArray($item)
    {
        if (is_object($item) && 'stdClass' === get_class($item)) {
            $array = [];
            foreach ((array) $item as $key => $value) {
                $array[$key] = self::toArray($value);
            }

            return $array;
        }

        return $item;
    }
}
