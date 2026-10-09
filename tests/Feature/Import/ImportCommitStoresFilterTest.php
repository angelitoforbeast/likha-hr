<?php

namespace Tests\Feature\Import;

use App\Jobs\ParseZktecoImport;
use App\Models\AttendanceImportRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The commit step of the preview, which is where the preview filter of S-03.3 is written.
 */
class ImportCommitStoresFilterTest extends ImportTestCase
{
    private function stageFile(array $lines): void
    {
        Storage::disk('local')->put('import_temp/stage-1/attlog.dat', implode("\r\n", $lines) . "\r\n");
    }

    private function commit(): void
    {
        $this->actingAs($this->uploader)
            ->withSession(['import_temp_attlog' => [
                'id'          => 'stage-1',
                'filename'    => 'attlog.dat',
                'uploaded_by' => $this->uploader->id,
            ]])
            ->post('/import/preview/commit', [
                'date_from'      => self::D1,
                'date_to'        => self::D2,
                'include_zkteco' => ['1'],
            ])
            ->assertRedirect('/import');
    }

    #[TestDox('the commit stores the preview filter as an array, not as a JSON string')]
    public function test_commit_stores_the_preview_filter_as_an_array(): void
    {
        Bus::fake();
        $this->stageFile(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('2', self::D1),
            $this->fullDay('1', self::D3),
        ));

        $this->commit();

        $run = AttendanceImportRun::sole();
        Bus::assertDispatched(ParseZktecoImport::class);
        $this->assertSame([
            'preview_filter' => [
                'date_from'      => self::D1,
                'date_to'        => self::D2,
                'included_count' => 1,
                'kept_lines'     => 4,
                'dropped_lines'  => 8,
            ],
        ], $run->stats_json);
    }

    #[TestDox('a committed preview ends as a done run with the filter, the counts and the computed days')]
    public function test_commit_runs_the_import_and_computes_the_days(): void
    {
        $this->stageFile(array_merge(
            $this->fullDay('1', self::D1),
            $this->fullDay('1', self::D2),
            $this->fullDay('2', self::D1),
        ));

        $this->commit();

        $run = AttendanceImportRun::sole();
        $this->assertSame('done', $run->status);
        $this->assertSame(8, $run->stats_json['preview_filter']['kept_lines']);
        $this->assertSame(8, $run->stats_json['total_logs_inserted']);
        $this->assertSummary(2, 0, 0, $run);
        $this->assertSame([$this->key($this->a, self::D1), $this->key($this->a, self::D2)], $this->dayKeys());
    }
}
