<?php
/*
 * This file is part of PhpConfigTree
 */
namespace DgfipSI1\ConfigTree\Exception;

/**
 * Exception class for schema validation exceptions
 */
class SchemaValidationException extends RuntimeException
{
    /**
     * @var array<string>
     */
    protected $errors;

    /**
     * @param string        $message
     * @param array<string> $errors
     */
    public function __construct($message, $errors = [])
    {
        $this->errors = $errors;
        parent::__construct((string) $message, 0);
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
