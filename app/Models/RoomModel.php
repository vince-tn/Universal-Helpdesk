<?php

namespace App\Models;

use CodeIgniter\Model;

class RoomModel extends Model
{
    protected $table         = 'rooms';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'location', 'capacity', 'equipment', 'needs_approval', 'is_active'];

    public function active(): array
    {
        return $this->where('is_active', 1)->orderBy('name', 'ASC')->findAll();
    }

    public function allOrdered(): array
    {
        return $this->orderBy('is_active', 'DESC')->orderBy('name', 'ASC')->findAll();
    }

    public function findByName(string $name): ?array
    {
        return $this->where('name', trim($name))->first();
    }

    // equipment text split into a clean list
    public static function equipmentList(?string $equipment): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $equipment))));
    }
}
