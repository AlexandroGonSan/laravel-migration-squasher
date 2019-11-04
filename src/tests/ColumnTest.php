<?php
/**
 * Created by IntelliJ IDEA.
 * User: jbarber
 * Date: 11/5/13
 * Time: 1:15 PM
 */

namespace Stackout\Squasher\tests;


class ColumnTest extends \PHPUnit_Framework_TestCase
{

    public function testInstantiateColumn()
    {
        require __DIR__ .'/../database/Column.php';

        $col = new \Stackout\Squasher\Database\Column('string', 'test', true, 10, false, false);
        $this->assertEquals('string', $col->type);
        $this->assertEquals('test', $col->name);
        $this->assertTrue($col->unsigned);
        $this->assertEquals(10, $col->parameters);
        $this->assertFalse($col->nullable);
        $this->assertFalse($col->unique);
    }
} 