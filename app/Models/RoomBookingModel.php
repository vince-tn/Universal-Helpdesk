<?php

namespace App\Models;

use CodeIgniter\Model;

class RoomBookingModel extends Model
{
    protected $table         = 'room_bookings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'room_id', 'user_id', 'title', 'starts_at', 'ends_at', 'attendees', 'status',
        'recurrence_group', 'checked_in_at', 'approved_by',
    ];

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PENDING   = 'pending_approval';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RELEASED  = 'released';

    // statuses that hold the room
    public const HOLDING = [self::STATUS_CONFIRMED, self::STATUS_PENDING];

    public const RELEASE_AFTER_MINUTES = 15;

    // bookings that overlap the window and still hold the room
    public function clashes(int $roomId, string $startsAt, string $endsAt, ?int $exceptId = null): array
    {
        $builder = $this->where('room_id', $roomId)
            ->whereIn('status', self::HOLDING)
            ->where('starts_at <', $endsAt)
            ->where('ends_at >', $startsAt);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->orderBy('starts_at', 'ASC')->findAll();
    }

    public function hasClash(int $roomId, string $startsAt, string $endsAt, ?int $exceptId = null): bool
    {
        return $this->clashes($roomId, $startsAt, $endsAt, $exceptId) !== [];
    }

    // holding bookings in a window with the booker name
    public function inWindow(string $from, string $to, ?int $roomId = null): array
    {
        $builder = $this->select('room_bookings.*, users.name AS user_name')
            ->join('users', 'users.id = room_bookings.user_id', 'left')
            ->whereIn('room_bookings.status', self::HOLDING)
            ->where('room_bookings.starts_at <', $to)
            ->where('room_bookings.ends_at >', $from);

        if ($roomId !== null) {
            $builder->where('room_bookings.room_id', $roomId);
        }

        return $builder->orderBy('room_bookings.starts_at', 'ASC')->findAll();
    }

    public function withRoom(int $id): ?array
    {
        return $this->select('room_bookings.*, rooms.name AS room_name, rooms.location AS room_location, users.name AS user_name, users.email AS user_email')
            ->join('rooms', 'rooms.id = room_bookings.room_id', 'left')
            ->join('users', 'users.id = room_bookings.user_id', 'left')
            ->where('room_bookings.id', $id)
            ->first();
    }

    // bookings in one recurring series with room details
    public function series(string $group): array
    {
        return $this->select('room_bookings.*, rooms.name AS room_name, rooms.location AS room_location')
            ->join('rooms', 'rooms.id = room_bookings.room_id', 'left')
            ->where('room_bookings.recurrence_group', $group)
            ->orderBy('room_bookings.starts_at', 'ASC')
            ->findAll();
    }

    // upcoming and recent bookings for one person
    public function forUser(int $userId, string $since): array
    {
        return $this->select('room_bookings.*, rooms.name AS room_name, rooms.location AS room_location')
            ->join('rooms', 'rooms.id = room_bookings.room_id', 'left')
            ->where('room_bookings.user_id', $userId)
            ->where('room_bookings.ends_at >=', $since)
            ->orderBy('room_bookings.starts_at', 'ASC')
            ->findAll(200);
    }

    public function pendingApprovals(string $since): array
    {
        return $this->select('room_bookings.*, rooms.name AS room_name, users.name AS user_name')
            ->join('rooms', 'rooms.id = room_bookings.room_id', 'left')
            ->join('users', 'users.id = room_bookings.user_id', 'left')
            ->where('room_bookings.status', self::STATUS_PENDING)
            ->where('room_bookings.ends_at >=', $since)
            ->orderBy('room_bookings.starts_at', 'ASC')
            ->findAll(200);
    }

    // releases no-shows as of now in the room time zone
    public function releaseDue(): int
    {
        $now    = \App\Libraries\RoomTime::now();
        $cutoff = $now->modify('-' . self::RELEASE_AFTER_MINUTES . ' minutes');

        return $this->releaseNoShows($cutoff->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));
    }

    // frees rooms nobody checked into before the cutoff
    public function releaseNoShows(string $cutoff, string $now): int
    {
        $this->builder()
            ->whereIn('status', self::HOLDING)
            ->where('checked_in_at', null)
            ->where('starts_at <=', $cutoff)
            ->update(['status' => self::STATUS_RELEASED, 'updated_at' => $now]);

        return $this->db->affectedRows();
    }
}
