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
use ReflectionClass;
use RuntimeException;

/**
 * @uses DgfipSI1\ConfigTree\ConfigTree
 *
 * @covers DgfipSI1\ConfigTree\Exception\BranchNotFoundException
 * @covers DgfipSI1\ConfigTree\Exception\ValueNotFoundException
 * @covers DgfipSI1\ConfigTree\Exception\SchemaValidationException
 */
class ConfigurationTreeTest extends TestCase
{
    protected const MARSEILLE   = [ 'location' => ['latitude' => 43.17, 'longitude' => 5.22 ]];
    protected const PARIS       = [ 'location' => ['latitude' => 48.52, 'longitude' => 2.19 ]];
    protected const EMPLOYEE    = "    addresses: {  }\n    name: john\n    noob: true\n";


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
     * @covers DgfipSI1\ConfigTree\ConfigTree::__construct
     * @covers DgfipSI1\ConfigTree\ConfigTree::fromArray
     * @covers DgfipSI1\ConfigTree\ConfigTree::fromFile
     */
    public function testConstructors(): void
    {
        /** test yaml and json files for schema */
        $class = new ReflectionClass('DgfipSI1\ConfigTree\ConfigTree');
        $schema = $class->getProperty('schema');
        $schema->setAccessible(true);
        $ctJson = new ConfigTree($this->testDataDir."miniSchema.json");
        $ctYaml = new ConfigTree($this->testDataDir."miniSchema.yaml");
        $this->assertEquals($schema->getValue($ctJson), $schema->getValue($ctYaml));
        /* test non existing file  */
        $msg = '';
        try {
            $ct = new ConfigTree("absentSchema.json");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Error reading schema file 'absentSchema.json'", $msg);
        /* test with bad extension  */
        $msg = '';
        try {
            $ct = new ConfigTree($this->testDataDir."testSchema.foo");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertMatchesRegularExpression("/Unsupported extension/", $msg);

        /* test fromArray */
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", []);
        $this->assertNotEmpty($ct);

        /* test fromFile */
        $ct = ConfigTree::fromFile($this->testDataDir."simpleSchema.yaml", $this->testDataDir."testConfig.yaml");
        /* test fromFile with bad file */
        $msg = '';
        try {
            $ct = ConfigTree::fromFile($this->testDataDir."simpleSchema.yaml", $this->testDataDir."badYaml.yaml");
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        $this->assertMatchesRegularExpression('/Exception parsing/', $msg);
    }
   /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::parseSchema
     */
    public function testParseSchema(): void
    {
        $ct = new ConfigTree($this->testDataDir."simpleSchema.yaml");
        $ct->check();
        /** @var string */
        $result = $ct->print(true);
        $this->assertMatchesRegularExpression("/name: john/", $result);
        /** tests with bad schema properties 1/2 */
        $msg = '';
        try {
            $opts = new \stdClass();
            /** @phpstan-ignore-next-line */
            $ct->parseSchema($opts, 'foo');
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals('Validation error: schema properties should be a stdClass object', $msg);
        /** tests with bad schema properties 2/2*/
        $msg = '';
        try {
            $opts = new \stdClass();
            $props = new \stdClass();
            $props->properties = 'foo';
            $ct->parseSchema($opts, $props);
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals('Bad schema : expecting attributes for property properties', $msg);
        /** tests with bad refs properties 1/2 */
        $msg = '';
        try {
            $ct = new ConfigTree($this->testDataDir."badRefs01.yaml");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertMatchesRegularExpression('#Error parsing ref /definitions/person#', $msg);
        /** tests with bad refs properties 2/2 */
        $msg = '';
        try {
            $ct = new ConfigTree($this->testDataDir."badRefs02.yaml");
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Can't locate ref person in schema", $msg);
    }
    /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::check
     */
    public function testCheck(): void
    {
        $msg = '';
        try {
            $ct = ConfigTree::fromArray($this->testDataDir."badSchema3.yaml", []);
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals('Validation error: object is an invalid type for foo\n', $msg);

        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", []);
        $msg = '';
        try {
            $ct->set('foo', 'bar');
        } catch (SchemaValidationException $e) {
            $msg = $e->getMessage();
        }
        $this->assertMatchesRegularExpression('/The property foo is not defined/', $msg);
    }

    /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::get
    */
    public function testGet(): void
    {
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", []);
        $ct->set('cities.Marseille', self::MARSEILLE);

        /** test nominal case */
        $this->assertEquals(43.17, $ct->get('cities.Marseille.location.latitude'));

        /** test getting a value on a non existing branch */
        /* NO OPTIONS => raise exception */
        $ret = $this->safeGet($ct, 'cities.Paris.location.latitude');
        $this->assertEquals("Config branch 'cities.Paris' does not exist.", $ret['err']);

        /* NULL_ON_NO_BRANCH => return null */
        $ret = $this->safeGet($ct, 'cities.Paris.location.latitude', ConfigTree::NULL_ON_NO_BRANCH);
        $this->assertEmpty($ret['err']);
        $this->assertNull($ret['value']);

        /* CREATE_BRANCH => create branch on the fly
        case 1 : default value exists  */
        $ret = $this->safeGet($ct, 'cities.Paris.location.altitude', ConfigTree::CREATE_BRANCH_ON_GET);
        $this->assertEquals('', $ret['err']);
        $this->assertEquals(0, $ret['value']);
        /* case 1 : default value does not exists  */
        $ret = $this->safeGet($ct, 'cities.Lyon.location.latitude', ConfigTree::CREATE_BRANCH_ON_GET);
        $this->assertNotEmpty($ret['err']);

        /** test getting a non existing value */
        $ret = $this->safeGet($ct, 'cities.Marseille.location.x');
        $this->assertEquals("Option 'cities.Marseille.location.x' does not exists or has not been set.", $ret['err']);

        $ret = $this->safeGet($ct, 'cities.Marseille.location.x', ConfigTree::NULL_ON_NO_VALUE);
        $this->assertEquals('', $ret['err']);
        $this->assertNull($ret['value']);
    }


    /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::set
    */
    public function testSet(): void
    {
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", []);
        $ct->set('cities.Marseille', self::MARSEILLE);
        $this->assertEquals(43.17, $ct->get('cities.Marseille.location.latitude'));
        $ct->set('cities.Marseille.location.latitude', 43);
        $this->assertEquals(43, $ct->get('cities.Marseille.location.latitude'));

        /* test on the fly creation of arborescence */
        $ct->set('cities.Paris.location.latitude', 48);
        $this->assertEquals(48, $ct->get('cities.Paris.location.latitude'));

        /* test bad value with without check */
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", []);
        $msg = '';
        try {
            $ct->set('cities', 1, false);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals('', $msg);
        /* test bad value with with check */
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", []);
        $msg = '';
        try {
            $ct->set('cities', 1);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        $this->assertMatchesRegularExpression('/cities : Integer value found/', $msg);
    }
    /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::merge
    */
    public function testMerge(): void
    {
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", [ 'cities' => [ 'Paris' => self::PARIS]]);
        $ct->merge(['cities.Marseille' => self::MARSEILLE]);
        $result  = "cities:\n";
        $result .= "  Paris:\n    location:\n      latitude: 48.52\n      longitude: 2.19\n      altitude: 0\n";
        $result .= "  Marseille:\n    location:\n      latitude: 43.17\n      longitude: 5.22\n      altitude: 0\n";
        $result .= "employees:\n  boss:\n".self::EMPLOYEE."  chief:\n".self::EMPLOYEE;
        $this->assertEquals($result, $ct->print(true));
    }

    /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::print
     */
    public function testPrint(): void
    {
        $ct = ConfigTree::fromArray($this->testDataDir."simpleSchema.yaml", [ 'cities' => [ 'Paris' => []]]);
        $output  = "cities:\n  Paris: {  }\n";
        $output .= "employees:\n  boss:\n".self::EMPLOYEE."  chief:\n".self::EMPLOYEE;

        $this->expectOutputString($output);
        $ct->print();
        $this->assertEquals($output, $ct->print(true));
    }
    /**
     * @covers DgfipSI1\ConfigTree\ConfigTree::toObject
     * @covers DgfipSI1\ConfigTree\ConfigTree::toArray
     *
     * @return void
     */
    public function testToObjectAndToArray(): void
    {
        $class = new ReflectionClass('DgfipSI1\ConfigTree\ConfigTree');
        $toObject = $class->getMethod('toObject');
        $toObject->setAccessible(true);
        $toArray = $class->getMethod('toArray');
        $toArray->setAccessible(true);

        $obj = new \stdClass();
        $obj->tree = new \stdClass();
        $obj->tree->leaf = 'leaf-name';
        $array = [ 'tree' => [ 'leaf' => 'leaf-name']];

        /* test toArray() with object */
        $this->assertEquals($array, $toArray->invokeArgs(null, [$obj]));
        /* test toArray() with non object */
        $this->assertEquals('foo', $toArray->invokeArgs(null, ['foo']));
        /* test toObject() with array */
        $this->assertEquals($obj, $toObject->invokeArgs(null, [$array]));
        /* test toObject() with non object */
        $this->assertEquals('foo', $toObject->invokeArgs(null, ['foo']));
    }

    /**
     * safeGet() : launch a get() with all parameters, but catch exception and return it in ret[err]
     *
     * @param ConfigTree $ct
     * @param string     $item
     * @param integer    $options
     *
     * @return array<string,mixed>
     */
    protected function safeGet($ct, $item, $options = 0)
    {
        $msg = '';
        $value = null;
        try {
            $value = $ct->get($item, $options);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }

        return [ 'value' => $value, 'err' => $msg];
    }
}
