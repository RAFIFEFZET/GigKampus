<?php

namespace Tests\Feature;

use App\Events\NotificationChanged;
use App\Models\Notification;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    public function test_broadcast_job_is_queued_after_commit_and_discarded_on_rollback(): void
    {
        $database = DB::connection();
        $database->statement('CREATE TEMP TABLE notifications (LIKE public.notifications INCLUDING ALL)');
        Queue::fake();

        try {
            $database->beginTransaction();
            [$student, $admin] = $database->transaction(fn () => [
                Notification::forceCreate($this->entry(['user_id' => 1])),
                Notification::forceCreate($this->entry(['admin_id' => 1])),
            ]);
            Queue::assertNothingPushed();
            $database->commit();

            Queue::assertPushed(BroadcastEvent::class, 2);
            foreach ([$student, $admin] as $entry) {
                Queue::assertPushedOn('default', BroadcastEvent::class, function (BroadcastEvent $job) use ($entry): bool {
                    $event = $job->event;

                    return $event instanceof NotificationChanged
                        && $event->broadcastAs() === 'notification.changed'
                        && $event->broadcastWith() === ['notification_id' => $entry->id]
                        && $event->broadcastOn()->name === ($entry->user_id ? 'private-user.1' : 'private-admin.1');
                });
            }

            $database->beginTransaction();
            $rolledBack = Notification::forceCreate($this->entry(['user_id' => 1]));
            Queue::assertPushed(BroadcastEvent::class, 2);
            $database->rollBack();

            Queue::assertPushed(BroadcastEvent::class, 2);
            self::assertNull(Notification::find($rolledBack->id));
        } finally {
            if ($database->transactionLevel() > 0) {
                $database->rollBack(0);
            }

            $database->statement('DROP TABLE pg_temp.notifications');
        }
    }

    private function entry(array $recipient): array
    {
        return [
            ...$recipient,
            'type' => 'test.broadcast',
            'title' => 'Private title',
            'body' => 'Private information.',
            'data' => ['relative_url' => '/private'],
        ];
    }
}
