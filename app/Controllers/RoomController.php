<?php

namespace App\Controllers;

use App\Libraries\Mailer;
use App\Libraries\Org;
use App\Libraries\RoomInvite;
use App\Libraries\RoomTime;
use App\Models\RoomBookingModel;
use App\Models\RoomModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use DateTimeImmutable;

class RoomController extends BaseController
{
    public const MAX_WEEKS         = 12;
    public const APPROVAL_MINUTES  = 240;
    public const CHECKIN_EARLY_MIN = 15;

    protected RoomModel $rooms;
    protected RoomBookingModel $bookings;

    public function __construct()
    {
        $this->rooms    = new RoomModel();
        $this->bookings = new RoomBookingModel();
    }

    // releases no-shows, never blocks the page
    public static function sweep(): int
    {
        try {
            return (new RoomBookingModel())->releaseDue();
        } catch (\Throwable $e) {
            log_message('error', 'Room release sweep failed: {message}', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    private function isApprover(?array $user = null): bool
    {
        $user ??= current_user();

        return Org::isSuperadmin($user) || Org::isSupervisor($user);
    }

    private function superOnly(): void
    {
        if (! Org::isSuperadmin(current_user())) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    public function index()
    {
        self::sweep();

        $day   = RoomTime::day($this->request->getGet('date'));
        $rooms = $this->rooms->active();
        $from  = $day->format('Y-m-d') . ' ' . RoomTime::DAY_START . ':00';
        $to    = $day->format('Y-m-d') . ' ' . RoomTime::DAY_END . ':00';

        $byRoom = [];
        foreach ($this->bookings->inWindow($from, $to) as $b) {
            $byRoom[(int) $b['room_id']][] = $b;
        }

        $columns = [];
        foreach ($rooms as $room) {
            $columns[] = [
                'room'  => $room,
                'day'   => $day,
                'cells' => $this->cells($byRoom[(int) $room['id']] ?? [], $day),
            ];
        }

        return view('rooms/day', $this->common([
            'view'    => 'day',
            'day'     => $day,
            'rooms'   => $rooms,
            'columns' => $columns,
        ]));
    }

    public function week()
    {
        self::sweep();

        $day    = RoomTime::day($this->request->getGet('date'));
        $monday = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        $rooms  = $this->rooms->active();
        $roomId = (int) $this->request->getGet('room');
        $room   = null;

        foreach ($rooms as $r) {
            if ((int) $r['id'] === $roomId) {
                $room = $r;
            }
        }

        $room ??= $rooms[0] ?? null;
        $columns = [];

        if ($room !== null) {
            $all = $this->bookings->inWindow(
                $monday->format(RoomTime::DB_FORMAT),
                $monday->modify('+7 days')->format(RoomTime::DB_FORMAT),
                (int) $room['id']
            );

            for ($i = 0; $i < 7; $i++) {
                $date      = $monday->modify('+' . $i . ' days');
                $columns[] = ['room' => $room, 'day' => $date, 'cells' => $this->cells($all, $date)];
            }
        }

        return view('rooms/week', $this->common([
            'view'    => 'week',
            'day'     => $day,
            'monday'  => $monday,
            'rooms'   => $rooms,
            'room'    => $room,
            'columns' => $columns,
        ]));
    }

    private function common(array $data): array
    {
        $me = current_user();

        return $data + [
            'me'         => $me,
            'isApprover' => $this->isApprover($me),
            'isSuper'    => Org::isSuperadmin($me),
            'slots'      => RoomTime::slots(),
            'now'        => RoomTime::now(),
            'pending'    => $this->isApprover($me) ? count($this->bookings->pendingApprovals(RoomTime::nowDb())) : 0,
        ];
    }

    // maps bookings onto the 30 minute slots of one day
    private function cells(array $bookings, DateTimeImmutable $day): array
    {
        $slots    = RoomTime::slots();
        $dayStart = new DateTimeImmutable($day->format('Y-m-d') . ' ' . RoomTime::DAY_START . ':00', RoomTime::zone());
        $dayEnd   = new DateTimeImmutable($day->format('Y-m-d') . ' ' . RoomTime::DAY_END . ':00', RoomTime::zone());
        $now      = RoomTime::now();
        $slotSec  = RoomTime::SLOT_MIN * 60;
        $cells    = [];

        foreach ($bookings as $b) {
            $start = RoomTime::parse((string) $b['starts_at']);
            $end   = RoomTime::parse((string) $b['ends_at']);

            if ($start === null || $end === null || $end <= $dayStart || $start >= $dayEnd) {
                continue;
            }

            $from = max($start, $dayStart);
            $to   = min($end, $dayEnd);
            $a    = intdiv($from->getTimestamp() - $dayStart->getTimestamp(), $slotSec);
            $z    = (int) ceil(($to->getTimestamp() - $dayStart->getTimestamp()) / $slotSec);

            if ($z <= $a || isset($cells[$a])) {
                continue;
            }

            $cells[$a] = ['type' => 'booking', 'booking' => $b, 'span' => $z - $a];

            for ($i = $a + 1; $i < $z; $i++) {
                $cells[$i] ??= ['type' => 'skip'];
            }
        }

        foreach ($slots as $i => $hhmm) {
            if (isset($cells[$i])) {
                continue;
            }

            $at        = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $hhmm . ':00', RoomTime::zone());
            $cells[$i] = [
                'type' => 'free',
                'past' => $at->getTimestamp() + $slotSec <= $now->getTimestamp(),
                'time' => $hhmm,
            ];
        }

        ksort($cells);

        return $cells;
    }

    public function create()
    {
        self::sweep();

        $rooms = $this->rooms->active();

        if ($rooms === []) {
            return redirect()->to('/rooms')->with('error', 'There are no rooms to book yet.');
        }

        $start = (string) $this->request->getGet('start');
        $slots = RoomTime::slots();

        if (! in_array($start, $slots, true)) {
            $start = '';
        }

        $end = '';
        if ($start !== '') {
            $index = array_search($start, $slots, true);
            $end   = RoomTime::endTimes()[$index + 1] ?? RoomTime::endTimes()[$index];
        }

        return view('rooms/book', [
            'rooms'    => $rooms,
            'slots'    => $slots,
            'endTimes' => RoomTime::endTimes(),
            'maxWeeks' => self::MAX_WEEKS,
            'pick'     => [
                'room_id' => (int) $this->request->getGet('room'),
                'date'    => RoomTime::day($this->request->getGet('date'))->format('Y-m-d'),
                'start'   => $start,
                'end'     => $end,
            ],
        ]);
    }

    public function store()
    {
        $me      = current_user();
        $roomId  = (int) $this->request->getPost('room_id');
        $title   = trim((string) $this->request->getPost('title'));
        $date    = (string) $this->request->getPost('date');
        $start   = (string) $this->request->getPost('start');
        $end     = (string) $this->request->getPost('end');
        $people  = (int) $this->request->getPost('attendees');
        $weeks   = max(1, (int) $this->request->getPost('repeat_weeks'));
        $room    = $roomId > 0 ? $this->rooms->find($roomId) : null;

        $fail = fn (string $message) => redirect()->back()->withInput()->with('error', $message);

        if ($room === null || (int) $room['is_active'] !== 1) {
            return $fail('Please pick a room.');
        }

        if ($title === '' || mb_strlen($title) > 150) {
            return $fail('Give your booking a short title, up to 150 characters.');
        }

        if (! is_ymd_date($date)) {
            return $fail('Please pick a date.');
        }

        if (! in_array($start, RoomTime::slots(), true) || ! in_array($end, RoomTime::endTimes(), true) || $end <= $start) {
            return $fail('Pick a start and end time between 7:00 AM and 9:00 PM, with the end after the start.');
        }

        if ($people < 1) {
            return $fail('Tell us how many people are coming.');
        }

        if ($people > (int) $room['capacity']) {
            return $fail($room['name'] . ' fits ' . (int) $room['capacity'] . ' people. Pick a bigger room or fewer attendees.');
        }

        if ($weeks > self::MAX_WEEKS) {
            return $fail('Weekly bookings can repeat for up to ' . self::MAX_WEEKS . ' weeks.');
        }

        $first = RoomTime::parse($date . ' ' . $start . ':00');
        $last  = RoomTime::parse($date . ' ' . $end . ':00');
        $now   = RoomTime::now();
        $floor = $now->setTime((int) $now->format('H'), (int) $now->format('i') >= 30 ? 30 : 0);

        if ($first === null || $last === null || $first < $floor) {
            return $fail('That time has already passed. Please pick a later time.');
        }

        if ($first > $now->modify('+1 year')) {
            return $fail('Bookings can be made up to one year ahead.');
        }

        $minutes = intdiv($last->getTimestamp() - $first->getTimestamp(), 60);
        $status  = ($minutes > self::APPROVAL_MINUTES || (int) $room['needs_approval'] === 1)
            ? RoomBookingModel::STATUS_PENDING
            : RoomBookingModel::STATUS_CONFIRMED;
        $group   = $weeks > 1 ? bin2hex(random_bytes(8)) : null;
        $made    = [];
        $skipped = [];

        for ($i = 0; $i < $weeks; $i++) {
            $s = $first->modify('+' . $i . ' weeks')->format(RoomTime::DB_FORMAT);
            $e = $last->modify('+' . $i . ' weeks')->format(RoomTime::DB_FORMAT);

            if ($this->bookings->hasClash($roomId, $s, $e)) {
                $skipped[] = $s;
                continue;
            }

            $id = (int) $this->bookings->insert([
                'room_id'          => $roomId,
                'user_id'          => (int) $me['id'],
                'title'            => $title,
                'starts_at'        => $s,
                'ends_at'          => $e,
                'attendees'        => $people,
                'status'           => $status,
                'recurrence_group' => $group,
            ]);

            // the earlier row wins if two people booked at the same moment
            $race = array_filter(
                $this->bookings->clashes($roomId, $s, $e, $id),
                static fn ($other) => (int) $other['id'] < $id
            );

            if ($race !== []) {
                $this->bookings->delete($id);
                $skipped[] = $s;
                continue;
            }

            $made[] = $id;
        }

        if ($made === []) {
            $clash = $this->bookings->clashes($roomId, $first->format(RoomTime::DB_FORMAT), $last->format(RoomTime::DB_FORMAT));
            $taken = $clash !== [] ? ' It is taken from ' . RoomTime::range($clash[0]['starts_at'], $clash[0]['ends_at']) . '.' : '';

            return $fail($weeks > 1
                ? 'Sorry, ' . $room['name'] . ' is already booked on every one of those dates. Try another time or room.'
                : 'Sorry, ' . $room['name'] . ' is already booked at that time.' . $taken . ' Try another time or room.');
        }

        $rows = [];
        foreach ($made as $id) {
            $rows[] = $this->bookings->withRoom($id);
        }

        $this->mailBooking($rows, $skipped, $status === RoomBookingModel::STATUS_PENDING ? 'pending' : 'confirmed');

        $when = RoomTime::date($rows[0]['starts_at']) . ', ' . RoomTime::range($rows[0]['starts_at'], $rows[0]['ends_at']);
        $message = $status === RoomBookingModel::STATUS_PENDING
            ? 'Request sent. ' . $room['name'] . ' on ' . $when . ' is waiting for a supervisor to approve it.'
            : 'You are booked. ' . $room['name'] . ' on ' . $when . '.';

        if (count($made) > 1) {
            $message .= ' Repeats weekly, ' . count($made) . ' dates in total.';
        }

        if ($skipped !== []) {
            $message .= ' Skipped because the room was taken: ' . implode(', ', array_map([RoomTime::class, 'date'], $skipped)) . '.';
        }

        return redirect()->to('/rooms?date=' . $first->format('Y-m-d'))->with('success', $message);
    }

    public function mine()
    {
        self::sweep();

        $me    = current_user();
        $since = RoomTime::now()->setTime(0, 0)->format(RoomTime::DB_FORMAT);

        return view('rooms/mine', [
            'bookings'   => $this->bookings->forUser((int) $me['id'], $since),
            'approvals'  => $this->isApprover($me) ? $this->bookings->pendingApprovals(RoomTime::nowDb()) : [],
            'isApprover' => $this->isApprover($me),
            'isSuper'    => Org::isSuperadmin($me),
            'now'        => RoomTime::now(),
        ]);
    }

    private function findOr404(int $id): array
    {
        $booking = $this->bookings->withRoom($id);

        if ($booking === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $booking;
    }

    private function back(string $fallback = '/rooms/mine')
    {
        $to = (string) $this->request->getPost('return_to');

        return redirect()->to(str_starts_with($to, '/rooms') ? $to : $fallback);
    }

    public function cancel(int $id)
    {
        $me      = current_user();
        $booking = $this->findOr404($id);

        if ((int) $booking['user_id'] !== (int) $me['id'] && ! Org::isSuperadmin($me)) {
            return $this->back()->with('error', 'You can only cancel your own bookings.');
        }

        if (! in_array($booking['status'], RoomBookingModel::HOLDING, true)) {
            return $this->back()->with('error', 'That booking is no longer active.');
        }

        $count = 1;

        if ($this->request->getPost('series') && ! empty($booking['recurrence_group'])) {
            $count = $this->bookings
                ->where('recurrence_group', $booking['recurrence_group'])
                ->where('user_id', (int) $booking['user_id'])
                ->whereIn('status', RoomBookingModel::HOLDING)
                ->where('starts_at >=', $booking['starts_at'])
                ->countAllResults();

            $this->bookings->builder()
                ->where('recurrence_group', $booking['recurrence_group'])
                ->where('user_id', (int) $booking['user_id'])
                ->whereIn('status', RoomBookingModel::HOLDING)
                ->where('starts_at >=', $booking['starts_at'])
                ->update(['status' => RoomBookingModel::STATUS_CANCELLED, 'updated_at' => RoomTime::nowDb()]);
        } else {
            $this->bookings->update($id, ['status' => RoomBookingModel::STATUS_CANCELLED]);
        }

        return $this->back()->with('success', $count > 1
            ? 'Cancelled ' . $count . ' bookings in the series. The room is free again.'
            : 'Booking cancelled. The room is free again.');
    }

    public function checkin(int $id)
    {
        $me      = current_user();
        $booking = $this->findOr404($id);

        if ((int) $booking['user_id'] !== (int) $me['id']) {
            return $this->back()->with('error', 'Only the person who booked the room can check in.');
        }

        if ($booking['status'] !== RoomBookingModel::STATUS_CONFIRMED) {
            return $this->back()->with('error', $booking['status'] === RoomBookingModel::STATUS_PENDING
                ? 'This booking still needs approval, so check-in is not open yet.'
                : 'This booking is no longer active.');
        }

        if (! empty($booking['checked_in_at'])) {
            return $this->back()->with('success', 'You are already checked in.');
        }

        $now   = RoomTime::now();
        $start = RoomTime::parse((string) $booking['starts_at']);
        $opens = $start->modify('-' . self::CHECKIN_EARLY_MIN . ' minutes');
        $shuts = $start->modify('+' . RoomBookingModel::RELEASE_AFTER_MINUTES . ' minutes');

        if ($now < $opens) {
            return $this->back()->with('error', 'Check-in opens at ' . RoomTime::time($opens) . ', 15 minutes before the start.');
        }

        if ($now > $shuts) {
            $this->bookings->update($id, ['status' => RoomBookingModel::STATUS_RELEASED]);

            return $this->back()->with('error', 'Check-in closed 15 minutes after the start, so the room was released. Book it again if it is still free.');
        }

        $this->bookings->update($id, ['checked_in_at' => $now->format(RoomTime::DB_FORMAT)]);

        return $this->back()->with('success', 'Checked in. Enjoy your meeting in ' . $booking['room_name'] . '.');
    }

    public function approve(int $id)
    {
        $me = current_user();

        if (! $this->isApprover($me)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $booking = $this->findOr404($id);

        if ($booking['status'] !== RoomBookingModel::STATUS_PENDING) {
            return $this->back()->with('error', 'That request was already handled.');
        }

        if ($this->bookings->hasClash((int) $booking['room_id'], $booking['starts_at'], $booking['ends_at'], $id)) {
            return $this->back()->with('error', 'The room is now taken at that time, so this request cannot be approved.');
        }

        $this->bookings->update($id, [
            'status'      => RoomBookingModel::STATUS_CONFIRMED,
            'approved_by' => (int) $me['id'],
        ]);

        $this->mailBooking([$this->bookings->withRoom($id)], [], 'approved');

        return $this->back()->with('success', 'Approved. ' . $booking['user_name'] . ' has been emailed.');
    }

    public function reject(int $id)
    {
        $me = current_user();

        if (! $this->isApprover($me)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $booking = $this->findOr404($id);

        if ($booking['status'] !== RoomBookingModel::STATUS_PENDING) {
            return $this->back()->with('error', 'That request was already handled.');
        }

        $this->bookings->update($id, [
            'status'      => RoomBookingModel::STATUS_CANCELLED,
            'approved_by' => (int) $me['id'],
        ]);

        $this->mailBooking([$this->bookings->withRoom($id)], [], 'rejected');

        return $this->back()->with('success', 'Request declined. ' . $booking['user_name'] . ' has been emailed.');
    }

    public function ics(int $id)
    {
        $me      = current_user();
        $booking = $this->findOr404($id);

        if ((int) $booking['user_id'] !== (int) $me['id'] && ! $this->isApprover($me)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="room-booking-' . $id . '.ics"')
            ->setBody(RoomInvite::ics([$booking]));
    }

    // emails the booker, failures are logged and ignored
    private function mailBooking(array $rows, array $skipped, string $kind): void
    {
        try {
            $rows = array_values(array_filter($rows));

            if ($rows === []) {
                return;
            }

            $first = $rows[0];
            $to    = (string) ($first['user_email'] ?? '');

            if ($to === '') {
                return;
            }

            $where = $first['room_name'] . ((string) ($first['room_location'] ?? '') !== '' ? ', ' . $first['room_location'] : '');
            $lines = [];
            foreach ($rows as $r) {
                $lines[] = '  ' . RoomTime::weekday($r['starts_at']) . ', ' . RoomTime::range($r['starts_at'], $r['ends_at']);
            }

            [$subject, $intro] = match ($kind) {
                'pending'  => ['Room request received: ' . $first['title'], 'Your room request is in. A supervisor needs to approve it, and we will email you as soon as they do.'],
                'approved' => ['Room booking approved: ' . $first['title'], 'Good news, your room request was approved. The room is yours.'],
                'rejected' => ['Room request declined: ' . $first['title'], 'Sorry, your room request was declined. Please pick another time or room on the Room bookings page.'],
                default    => ['Room booked: ' . $first['title'], 'Your room is booked.'],
            };

            $body = 'Hi ' . ($first['user_name'] ?? 'there') . ",\n\n" . $intro . "\n\n"
                . 'What: ' . $first['title'] . "\n"
                . 'Where: ' . $where . "\n"
                . "When:\n" . implode("\n", $lines) . "\n";

            if ($skipped !== []) {
                $body .= "\nSkipped because the room was already taken:\n  "
                    . implode("\n  ", array_map([RoomTime::class, 'weekday'], $skipped)) . "\n";
            }

            $attachments = [];

            if ($kind !== 'rejected') {
                $body .= "\nPlease check in on the Room bookings page within 15 minutes of the start, or the room is released for others.\n"
                    . "\nAdd it to your calendar with the attached invite, or download it here:\n"
                    . site_url('rooms/bookings/' . (int) $first['id'] . '/ics') . "\n";
                $attachments[] = [RoomInvite::ics($rows), 'room-booking.ics', 'text/calendar'];
            }

            $body .= "\nChikiting System\n";

            (new Mailer())->send($to, $subject, $body, $attachments);
        } catch (\Throwable $e) {
            log_message('error', 'Room booking email failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function manage()
    {
        $this->superOnly();

        return view('rooms/manage', ['rooms' => $this->rooms->allOrdered()]);
    }

    // reads and checks the room form fields
    private function roomInput(?int $exceptId = null): array|string
    {
        $name      = trim((string) $this->request->getPost('name'));
        $location  = trim((string) $this->request->getPost('location'));
        $capacity  = (int) $this->request->getPost('capacity');
        $equipment = implode(', ', \App\Models\RoomModel::equipmentList((string) $this->request->getPost('equipment')));

        if ($name === '' || mb_strlen($name) > 80) {
            return 'Enter a room name of 1 to 80 characters.';
        }

        $clash = $this->rooms->findByName($name);

        if ($clash !== null && (int) $clash['id'] !== $exceptId) {
            return 'A room called "' . $name . '" already exists.';
        }

        if ($capacity < 1 || $capacity > 500) {
            return 'Capacity should be between 1 and 500 people.';
        }

        if (mb_strlen($location) > 150 || mb_strlen($equipment) > 255) {
            return 'Location or equipment is too long.';
        }

        return [
            'name'           => $name,
            'location'       => $location === '' ? null : $location,
            'capacity'       => $capacity,
            'equipment'      => $equipment === '' ? null : $equipment,
            'needs_approval' => $this->request->getPost('needs_approval') ? 1 : 0,
        ];
    }

    public function manageStore()
    {
        $this->superOnly();

        $data = $this->roomInput();

        if (is_string($data)) {
            return redirect()->back()->withInput()->with('error', $data);
        }

        $this->rooms->insert($data + ['is_active' => 1]);

        return redirect()->to('/rooms/manage')->with('success', 'Room "' . $data['name'] . '" added.');
    }

    public function manageUpdate(int $id)
    {
        $this->superOnly();

        if ($this->rooms->find($id) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->roomInput($id);

        if (is_string($data)) {
            return redirect()->back()->with('error', $data);
        }

        $this->rooms->update($id, $data);

        return redirect()->to('/rooms/manage')->with('success', 'Room "' . $data['name'] . '" saved.');
    }

    public function manageToggle(int $id)
    {
        $this->superOnly();

        $room = $this->rooms->find($id);

        if ($room === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $active = (int) $room['is_active'] === 1 ? 0 : 1;
        $this->rooms->update($id, ['is_active' => $active]);

        return redirect()->to('/rooms/manage')->with('success', $active === 1
            ? 'Room "' . $room['name'] . '" is bookable again.'
            : 'Room "' . $room['name'] . '" is hidden from booking. Existing bookings stay as they are.');
    }
}
