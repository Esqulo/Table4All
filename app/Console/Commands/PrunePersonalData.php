<?php

namespace App\Console\Commands;

use App\Enums\AdmissionRequestStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes personal data whose purpose is over (LGPD Art. 15 and 16), with the periods in
 * config('legal.retention'). Scheduled daily (routes/console.php); see docs/privacy.md.
 *
 * Bills, payments and what each customer ordered are kept: they are financial records the
 * law requires, and loyalty rewards count visits from them.
 */
#[Signature('privacy:prune')]
#[Description('Delete personal data past its retention period (table chat, calls, links, invitations, contact requests)')]
final class PrunePersonalData extends Command
{
    private const CHUNK = 1000;

    public function handle(): int
    {
        $days = config('legal.retention');

        // Chat of tables closed long enough ago: it only made sense while people sat there.
        $closedBefore = now()->subDays((int) $days['table_chat_days']);
        $messages = $this->deleteForClosedTables('table_messages', $closedBefore);
        $reactions = $this->deleteForClosedTables('table_reactions', $closedBefore);

        $calls = $this->deleteInChunks(
            DB::table('table_calls')
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '<', now()->subDays((int) $days['table_calls_days'])),
        );

        $links = $this->deleteInChunks(
            DB::table('customer_link_tokens')
                ->where('expires_at', '<', now()->subDays((int) $days['link_tokens_days'])),
        );

        $invitedBefore = now()->subDays((int) $days['invitations_days']);
        // Waiter invitations expire 7 days after being sent (WaiterInvitation::scopePending).
        $waiterInvitations = $this->deleteInChunks(
            DB::table('waiter_invitations')->where('created_at', '<', $invitedBefore->copy()->subDays(7)),
        );
        $adminInvitations = $this->deleteInChunks(
            DB::table('admin_invitations')->where(fn ($q) => $q
                ->where('expires_at', '<', $invitedBefore)
                ->orWhere('accepted_at', '<', $invitedBefore)),
        );

        $admissions = $this->deleteInChunks(
            DB::table('admission_requests')
                ->where('status', '!=', AdmissionRequestStatus::PENDING->value)
                ->where('updated_at', '<', now()->subDays((int) $days['admission_requests_days'])),
        );

        $this->call('queue:prune-failed', ['--hours' => (int) $days['failed_jobs_days'] * 24]);

        $this->info(sprintf(
            'Deleted %d chat message(s), %d reaction(s), %d call(s), %d sign-in link(s), %d invitation(s), %d contact request(s).',
            $messages, $reactions, $calls, $links, $waiterInvitations + $adminInvitations, $admissions,
        ));

        return self::SUCCESS;
    }

    /** Rows of `$table` that belong to tables closed before the given moment. */
    private function deleteForClosedTables(string $table, \DateTimeInterface $closedBefore): int
    {
        return $this->deleteInChunks(
            DB::table($table)->whereIn('restaurant_table_id', DB::table('restaurant_tables')
                ->select('id')
                ->whereNotNull('closed_at')
                ->where('closed_at', '<', $closedBefore)),
        );
    }

    /** Deletes by id in batches, so a large backlog never holds one long lock on a hot table. */
    private function deleteInChunks(Builder $query): int
    {
        $deleted = 0;
        $table = is_string($query->from) ? $query->from : throw new \LogicException('Prune queries read one plain table.');

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
