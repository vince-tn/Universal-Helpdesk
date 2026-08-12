<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Database\Seeds\ExampleSeeder;
use Tests\Support\Models\ExampleModel;

/**
 * @internal
 */
final class ExampleDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = ExampleSeeder::class;

    public function testModelFindAll(): void
    {
        $model = new ExampleModel();

        $objects = $model->findAll();

        $this->assertCount(3, $objects);
    }

    public function testSoftDeleteLeavesRow(): void
    {
        $model = new ExampleModel();
        $this->setPrivateProperty($model, 'useSoftDeletes', true);
        $this->setPrivateProperty($model, 'tempUseSoftDeletes', true);

        /** @var stdClass $object */
        $object = $model->first();
        $model->delete($object->id);

        $this->assertNull($model->find($object->id));

        $result = $model->builder()->where('id', $object->id)->get()->getResult();

        $this->assertCount(1, $result);
    }
}
