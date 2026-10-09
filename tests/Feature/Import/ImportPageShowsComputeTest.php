<?php

namespace Tests\Feature\Import;

use App\Models\AttendanceImportRun;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * S-04 The Import page shows what the compute did.
 */
class ImportPageShowsComputeTest extends ImportTestCase
{
    private const PARSE_COUNTS = [
        'total_users_parsed'       => 0,
        'total_logs_parsed'        => 149,
        'total_logs_inserted'      => 140,
        'total_duplicates_skipped' => 9,
        'unmatched_zkteco_ids'     => 0,
        'duration_seconds'         => 0.42,
    ];

    private const PARSE_TEXT = 'Users: 0, Logs: 140/149, Dupes: 9, Unmatched: 0 (0.42s)';

    private function storedRun(string $status, mixed $stats): AttendanceImportRun
    {
        return AttendanceImportRun::create([
            'uploaded_by' => $this->uploader->id,
            'status'      => $status,
            'stats_json'  => $stats,
        ]);
    }

    private function doneRun(?array $compute): AttendanceImportRun
    {
        return $this->storedRun('done', self::PARSE_COUNTS + ($compute === null ? [] : ['compute' => $compute]));
    }

    private function compute(int $computed, int $lockedSkipped, int $errors): array
    {
        return ['days_computed' => $computed, 'locked_skipped' => $lockedSkipped, 'errors' => $errors];
    }

    /** The text of the Stats cell of one run's row, with whitespace collapsed. */
    private function statsCell(AttendanceImportRun $run): string
    {
        $response = $this->actingAs($this->uploader)->get('/import');
        $response->assertOk();

        $pattern = '/<tr data-run-id="' . $run->id . '"[^>]*>.*?<td class="run-stats[^"]*">(.*?)<\/td>/s';
        $this->assertSame(1, preg_match($pattern, $response->getContent(), $match), 'the run has no Stats cell on the page');

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[1]))));
    }

    #[TestDox('S-04.1 the Stats cell keeps its text and adds the computed and locked skipped counts')]
    public function test_s_04_1_cell_shows_computed_and_locked_skipped(): void
    {
        $cell = $this->statsCell($this->doneRun($this->compute(37, 2, 0)));

        $this->assertStringContainsString(self::PARSE_TEXT, $cell);
        $this->assertStringContainsString('Computed: 37 days, 2 locked skipped', $cell);
        $this->assertStringNotContainsString('errors', $cell);
    }

    #[TestDox('S-04.2 a run without a compute summary shows the cell exactly as before')]
    public function test_s_04_2_run_without_summary_is_unchanged(): void
    {
        $cell = $this->statsCell($this->doneRun(null));

        $this->assertSame(self::PARSE_TEXT, $cell);
    }

    #[TestDox('S-04.3 a summary of zeros shows only "Computed: 0 days"')]
    public function test_s_04_3_zero_summary(): void
    {
        $cell = $this->statsCell($this->doneRun($this->compute(0, 0, 0)));

        $this->assertSame(self::PARSE_TEXT . ' Computed: 0 days', $cell);
    }

    #[TestDox('S-04.4 errors are shown and a zero locked skipped count is left out')]
    public function test_s_04_4_errors_without_locked_skipped(): void
    {
        $cell = $this->statsCell($this->doneRun($this->compute(5, 0, 3)));

        $this->assertSame(self::PARSE_TEXT . ' Computed: 5 days, 3 errors', $cell);
    }

    #[TestDox('S-04.5 a queued run with only the preview filter renders without "Computed", in both stored forms')]
    public function test_s_04_5_queued_run_with_only_the_preview_filter(): void
    {
        $filter = ['preview_filter' => ['date_from' => '2026-10-05', 'date_to' => '2026-10-07',
            'included_count' => 2, 'kept_lines' => 9, 'dropped_lines' => 3]];
        $arrayForm = $this->storedRun('queued', $filter);
        $stringForm = $this->storedRun('queued', null);
        $stringForm->update(['stats_json' => json_encode($filter)]);
        $this->assertIsString($stringForm->fresh()->stats_json);

        $this->assertStringNotContainsString('Computed', $this->statsCell($arrayForm));
        $this->assertStringNotContainsString('Computed', $this->statsCell($stringForm));
    }

    #[TestDox('S-04.6 the status poll receives the same "Computed" text when a run finishes')]
    public function test_s_04_6_status_poll_carries_the_compute_text(): void
    {
        $run = $this->makeRun(array_merge($this->fullDay('1', self::D1), $this->fullDay('2', self::D1)));
        $run->update(['status' => 'processing']);
        $this->assertStringNotContainsString('Computed', $this->statsCell($run));

        $this->process($run);

        $status = $this->actingAs($this->uploader)->getJson("/import/{$run->id}/status");
        $status->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('compute_text', 'Computed: 2 days');
        // The page and the poll must show one wording, so the cell is checked against the same text.
        $this->assertStringEndsWith('Computed: 2 days', $this->statsCell($run));

        // The poll script itself only runs in a browser; here it is checked that it reads the field.
        $page = $this->actingAs($this->uploader)->get('/import')->getContent();
        $this->assertStringContainsString('data.compute_text', $page);
    }

    #[TestDox('S-04.7 one computed day is shown in the singular')]
    public function test_s_04_7_one_day_is_singular(): void
    {
        $cell = $this->statsCell($this->doneRun($this->compute(1, 0, 0)));

        $this->assertSame(self::PARSE_TEXT . ' Computed: 1 day', $cell);
    }

    #[TestDox('stored stats with a malformed compute summary render without error')]
    public function test_malformed_stored_summary_does_not_break_the_page(): void
    {
        $notAList = $this->storedRun('done', self::PARSE_COUNTS + ['compute' => 'not a summary']);
        $wrongTypes = $this->storedRun('done', self::PARSE_COUNTS + ['compute' => [
            'days_computed' => '4<b>', 'locked_skipped' => null, 'errors' => ['x'],
        ]]);
        $notJson = $this->storedRun('done', null);
        $notJson->update(['stats_json' => 'plain text, not JSON']);

        $this->assertSame(self::PARSE_TEXT, $this->statsCell($notAList));
        $this->assertSame(self::PARSE_TEXT . ' Computed: 4 days', $this->statsCell($wrongTypes));
        $this->assertStringNotContainsString('Computed', $this->statsCell($notJson));
    }
}
