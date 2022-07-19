<?php
/*
 * This file is part of DgfipSI1\ConfigTree.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DgfipSI1\ConfigTreeTests;

use DgfipSI1\ConfigTree\ConfigTree;
use DgfipSI1\ConfigTree\Exception\SchemaValidationException;
use DgfipSI1\ConfigTree\Exception\ValueNotFoundException;
use DgfipSI1\ConfigTree\Exception\BranchNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * @covers DgfipSI1\ConfigTree\ConfigTree
 * covers DgfipSI1\ConfigTree\Exception\ExceptionInterface
 * @covers DgfipSI1\ConfigTree\Exception\RunTimeException
 * @covers DgfipSI1\ConfigTree\Exception\BranchNotFoundException
 * @covers DgfipSI1\ConfigTree\Exception\ValueNotFoundException
 * @covers DgfipSI1\ConfigTree\Exception\SchemaValidationException
 */
class ConfigurationTreeTest extends TestCase
{
    /** @var string */
    private $testDataDir;
    /** setup a VfsStream filesystem with /conf/satis_dgfip.yaml
     *
     * {@inheritDoc}
     *
     * @see \PHPUnit\Framework\TestCase::setUp()
     */
    protected function setUp(): void
    {
        $this->testDataDir = __DIR__."/../data/";
    }
   /**
     * Test execCommand method
     *
     */
    public function testConstructor(): void
    {
        /** test yaml and json files for schema */
        $ctJson = new ConfigTree($this->testDataDir."testSchema.json");
        $ctYaml = new ConfigTree($this->testDataDir."testSchema.yaml");
        $this->assertEquals($ctJson->getDefaultOptions(), $ctYaml->getDefaultOptions());
        $ctYaml->check();
        $ctJson->check();
        /** test with bad schemas */
        $msg = '';
        try {
            $ct = new ConfigTree($this->testDataDir."badSchema1.yaml");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Bad schema : properties should be an array", $msg);
        try {
            $ct = new ConfigTree($this->testDataDir."badSchema2.yaml");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Bad schema : expecting attributes for property foo", $msg);
        $msg = '';
        try {
            $ct = new ConfigTree("absentSchema.json");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Schema file not found : 'absentSchema.json'", $msg);
        /** test unknown extension */
        $msg = '';
        try {
            $ctYaml = new ConfigTree($this->testDataDir."testSchema.foo");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertMatchesRegularExpression("/Unsupported extension/", $msg);
        /** test with options schemas */
        $ct = ConfigTree::fromArray($this->testDataDir."testSchema.yaml", ['subtree2.list1' => ['a', 'b', 'c']]);
        /** @var array<mixed> $list */
        $list = $ct->get('subtree2.list1');
        $this->assertEquals('b', $list[1]);
        $ct = ConfigTree::fromFile($this->testDataDir."testSchema.yaml", $this->testDataDir."testConfig.yaml");
        /** @var array<mixed> $list */
        $list = $ct->get('subtree2.list1');
        $this->assertEquals('y', $list[1]);
    }
   /**
     * Test execCommand method
     *
     */
    public function testGetDefaultOptions(): void
    {
        $ct = new ConfigTree($this->testDataDir."testSchema.json");
        $expectedOptions = [
            'boolean-prop1' => false,
            'boolean-prop2' => true,
            'integer-prop1' => 10,
            'string-prop1'  => null,
            'string-prop2'  => 'blah blah',
            'subtree1'      => [
                'integer-prop1' => 0,
                'string-prop1'  => 'info',
            ],
            'subtree2'      => [
                'list1'         => [],
                'boolan-prop'   => false,
                'subsubtree'    => [],
            ],
        ];
        $this->assertEquals($expectedOptions, $ct->getDefaultOptions());

        $msg = '';
        try {
            $ct = new ConfigTree($this->testDataDir."unhandledSchema.json");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Unknown type : 'foo'", $msg);
    }
    /**
     * Test merge and check methods
     */
    public function testGetSetMerge(): void
    {
        $ct = new ConfigTree($this->testDataDir."testSchema.json");
        // nominal case : merge exising options
        $this->assertEquals(false, $ct->get('boolean-prop1'));
        $this->assertEquals(0, $ct->get('subtree1.integer-prop1'));
        $ct->merge(['boolean-prop1' => true, 'subtree1.integer-prop1' => 100]);
        $this->assertEquals(true, $ct->get('boolean-prop1'));
        $this->assertEquals(100, $ct->get('subtree1.integer-prop1'));
        // nominal case : get existing option with no default
        $msg = '';
        try {
            $ct->get('subtree2.subsubtree.string');
        } catch (ValueNotFoundException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Option 'subtree2.subsubtree.string' does not exists or has not been set.", $msg);
        $this->assertNull($ct->get('subtree2.subsubtree.string', true));

        // nominal case : set option which had no default
        $ct->set('subtree2.subsubtree.string', 'foo');
        $this->assertEquals('foo', $ct->get('subtree2.subsubtree.string'));
        // error case : get option in non existing branch
        $msg = '';
        try {
            $ct->get('foo.bar.baz');
        } catch (BranchNotFoundException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Config branch '/foo' does not exist.", $msg);
        $this->assertNull($ct->get('foo.bar.baz', false, true));

        try {
            $ct->get('subtree1.bar.baz');
        } catch (BranchNotFoundException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Config branch '/subtree1/bar' does not exist.", $msg);
        // error case : set option in non existing branch
        try {
            $ct->set('foo.bar.baz', 1);
        } catch (BranchNotFoundException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Config branch '/foo' does not exist.", $msg);
        // error case : set non existing option : this time should fire a check exception
        $errs = [];
        try {
            $ct->set('subtree', 1);
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
            $errs = $e->getErrors();
        }
        $this->assertEquals("Validation error: config does not match schema.", $msg);
        $this->assertMatchesRegularExpression("/The property subtree is not defined and /", $errs[0]);
    }
}
