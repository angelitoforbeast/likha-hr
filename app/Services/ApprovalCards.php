<?php

namespace App\Services;

use App\Models\AttendanceChangeRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * How pending requests are cut into the cards of the Approvals page. The page, its paging,
 * the sidebar badge and the server-side check of a posted group all use this one function,
 * so what is counted, shown and accepted as "one change" cannot differ.
 *
 * - A day whose only pending request is a shift change joins the group of its change: same
 *   employee, same shift asked, same requester, and the same batch id or, without one, the
 *   same minute it was asked in. A change that is asked one date at a time leaves no batch
 *   id, so the minute is what ties its days together. A group of two days or more is one card.
 * - Every other day (times, overtime, rest day, SIL, or a shift together with other fields)
 *   is the per-day card it was.
 * - A shift assignment request of the profile is its own card.
 */
class ApprovalCards
{
    /** The most days one shift change card holds. */
    public const MAX_DAYS = 50;

    /**
     * @param  Collection<int, AttendanceChangeRequest>  $rows  every pending row of the days considered
     * @return array<int, array{type: string, key: ?string, employee_id: int, pairs: array<int, array{0: int, 1: string}>, ids: int[], first_id: int}>
     *         oldest first
     */
    public static function build(Collection $rows): array
    {
        $cards = [];
        $byPair = [];
        foreach ($rows as $row) {
            if ($row->field === AttendanceChangeRequest::FIELD_ASSIGNMENT) {
                $cards[] = [
                    'type' => 'assignment', 'key' => null, 'employee_id' => (int) $row->employee_id,
                    'pairs' => [], 'ids' => [(int) $row->id], 'first_id' => (int) $row->id,
                ];
                continue;
            }
            $byPair[$row->employee_id . '_' . $row->dateString()][] = $row;
        }

        $groups = [];
        foreach ($byPair as $pairRows) {
            $first = $pairRows[0];
            $pair = [(int) $first->employee_id, $first->dateString()];
            $ids = array_map(fn ($r) => (int) $r->id, $pairRows);
            $key = count($pairRows) === 1 ? self::groupKey($first) : null;
            if ($key === null) {
                $cards[] = ['type' => 'day', 'key' => null, 'employee_id' => $pair[0], 'pairs' => [$pair], 'ids' => $ids, 'first_id' => min($ids)];
                continue;
            }
            $groups[$key][] = ['pair' => $pair, 'id' => $ids[0]];
        }

        foreach ($groups as $key => $all) {
            usort($all, fn ($a, $b) => strcmp($a['pair'][1], $b['pair'][1]));
            // A very long change is cut into cards of at most MAX_DAYS days, date order: each
            // keeps the key (the server check is the same) and one page never holds more
            // previews, or one post more days, than that.
            foreach (array_chunk($all, self::MAX_DAYS) as $days) {
                $ids = array_column($days, 'id');
                $cards[] = [
                    'type'        => count($days) > 1 ? 'group' : 'day',
                    'key'         => count($days) > 1 ? $key : null,
                    'employee_id' => $days[0]['pair'][0],
                    'pairs'       => array_column($days, 'pair'),
                    'ids'         => $ids,
                    'first_id'    => min($ids),
                ];
            }
        }

        usort($cards, fn ($a, $b) => $a['first_id'] <=> $b['first_id']);

        return $cards;
    }

    /**
     * The change a shift request belongs to, or null for a request that is not a shift change.
     * The key names what the CEO sees on the card, so a posted key can be checked against it.
     */
    public static function groupKey(AttendanceChangeRequest $row): ?string
    {
        if ($row->field !== AttendanceChangeRequest::FIELD_SHIFT || $row->new_value === null) {
            return null;
        }
        $when = $row->batch_id
            ? 'b' . $row->batch_id
            : 'm' . Carbon::parse($row->created_at)->format('YmdHi');

        return 'e' . (int) $row->employee_id . '-s' . $row->new_value . '-r' . (int) $row->requested_by . '-' . $when;
    }

    /**
     * Dates as short ranges: "Oct 05, Oct 08–09, Oct 12–Nov 02".
     *
     * @param  string[]  $dates  Y-m-d, ascending
     */
    public static function dateRanges(array $dates): string
    {
        $runs = [];
        foreach ($dates as $date) {
            $d = Carbon::parse($date);
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][1]->copy()->addDay()->isSameDay($d)) {
                $runs[$last][1] = $d;
            } else {
                $runs[] = [$d, $d];
            }
        }

        return implode(', ', array_map(function ($run) {
            [$from, $to] = $run;
            if ($from->isSameDay($to)) {
                return $from->format('M d');
            }

            return $from->format('M d') . '–' . ($from->month === $to->month ? $to->format('d') : $to->format('M d'));
        }, $runs));
    }
}
