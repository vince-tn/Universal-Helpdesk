<?php
    use App\Libraries\RoomTime;
    use App\Models\RoomBookingModel;
?>
<div class="room-legend">
    <span><i class="sw sw-free"></i> Free, tap to book</span>
    <span><i class="sw sw-taken"></i> Booked</span>
    <span><i class="sw sw-mine"></i> Yours</span>
    <span><i class="sw sw-pending"></i> Waiting for approval</span>
</div>

<div class="room-grid-wrap" tabindex="0" aria-label="Room availability grid, scroll sideways to see more">
    <table class="room-grid">
        <thead>
            <tr>
                <th class="rg-time" scope="col">Time</th>
                <?php foreach ($headers as $header): ?>
                    <th scope="col"><?= $header ?></th>
                <?php endforeach ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($slots as $i => $hhmm): ?>
                <tr class="<?= str_ends_with($hhmm, ':00') ? 'is-hour' : '' ?>">
                    <th class="rg-time" scope="row"><?= str_ends_with($hhmm, ':00') ? esc(RoomTime::slotLabel($hhmm)) : '' ?></th>
                    <?php foreach ($columns as $col): ?>
                        <?php $cell = $col['cells'][$i] ?? ['type' => 'free', 'past' => true]; ?>
                        <?php if ($cell['type'] === 'skip') continue; ?>
                        <?php if ($cell['type'] === 'booking'): ?>
                            <?php
                                $b       = $cell['booking'];
                                $mine    = (int) $b['user_id'] === (int) $me['id'];
                                $pending = $b['status'] === RoomBookingModel::STATUS_PENDING;
                                $cls     = $pending ? 'is-pending' : ($mine ? 'is-mine' : 'is-taken');
                            ?>
                            <td class="rg-booking <?= $cls ?>" rowspan="<?= (int) $cell['span'] ?>">
                                <div class="rg-chip">
                                    <?php if ($mine): ?>
                                        <a href="/rooms/mine" class="rg-title"><?= esc($b['title']) ?></a>
                                    <?php else: ?>
                                        <span class="rg-title"><?= esc($b['title']) ?></span>
                                    <?php endif ?>
                                    <span class="rg-meta"><?= esc(RoomTime::range($b['starts_at'], $b['ends_at'])) ?></span>
                                    <span class="rg-meta"><?= $mine ? 'You' : esc($b['user_name'] ?? 'Someone') ?><?= $pending ? ' · waiting for approval' : '' ?><?= ! empty($b['checked_in_at']) ? ' · checked in' : '' ?></span>
                                </div>
                            </td>
                        <?php elseif (! empty($cell['past'])): ?>
                            <td class="rg-free is-past"></td>
                        <?php else: ?>
                            <td class="rg-free">
                                <a href="/rooms/book?room=<?= (int) $col['room']['id'] ?>&amp;date=<?= esc($col['day']->format('Y-m-d')) ?>&amp;start=<?= esc($cell['time']) ?>"
                                   aria-label="Book <?= esc($col['room']['name']) ?> on <?= esc(RoomTime::date($col['day'])) ?> at <?= esc(RoomTime::slotLabel($cell['time'])) ?>">
                                    <span><?= esc(RoomTime::slotLabel($cell['time'])) ?></span>
                                </a>
                            </td>
                        <?php endif ?>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
