<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Revision;
use App\Models\User;

final class RevisionService
{
    public function request(User $buyer, Order $order, string $note, ?int $stageVersion): Revision
    {
        return OrderTransaction::run($buyer, $order, function (Order $locked) use ($buyer, $note, $stageVersion): Revision {
            abort_unless($locked->hasBuyer($buyer), 403);
            $locked->assertStageVersion($stageVersion);

            $before = $locked->only(['status', 'stage_version']);
            $locked->requestRevision($buyer);
            $revision = $locked->revisions()->forceCreate([
                'revision_no' => (int) $locked->revisions()->max('revision_no') + 1,
                'revision_note' => $note,
                'requested_at' => now(),
            ]);
            $locked->saveOrFail();

            OrderActivity::record(
                $locked, $buyer, 'revision.requested',
                'Revisi diajukan', 'Pemesan meminta perbaikan hasil sesuai brief pesanan.',
                $before, [...$locked->only(['status', 'stage_version']), 'revision_id' => $revision->id],
            );

            return $revision;
        });
    }

    public function start(User $seller, Order $order, Revision $revision): void
    {
        OrderTransaction::run($seller, $order, function (Order $locked) use ($seller, $revision): void {
            abort_unless($locked->hasSeller($seller), 403);
            $current = $locked->revisions()->lockForUpdate()->findOrFail($revision->id);
            $before = $current->status->value;
            $current->start($seller, $locked);
            $current->saveOrFail();

            OrderActivity::record(
                $locked, $seller, 'revision.started',
                'Revisi mulai dikerjakan', 'Penjual mulai mengerjakan perbaikan hasil.',
                ['revision_id' => $current->id, 'revision_status' => $before],
                ['revision_id' => $current->id, 'revision_status' => $current->status->value],
            );
        });
    }
}
