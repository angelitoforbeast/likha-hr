<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_by',
        'status',
        'stats_json',
    ];

    protected $casts = [
        'stats_json' => 'array',
    ];

    /**
     * The stats as an array, whatever form is stored. Runs committed before the
     * preview filter was stored as an array hold a JSON string in this column,
     * which the cast hands back as a string.
     */
    public function statsArray(): array
    {
        $stats = $this->stats_json;

        if (is_string($stats)) {
            $stats = json_decode($stats, true);
        }

        return is_array($stats) ? $stats : [];
    }

    /**
     * What the automatic compute of this import did, as shown on the Import page,
     * or null when the run has no compute summary (older runs, unfinished runs).
     * The page and its status poll both use this, so they cannot drift apart.
     */
    public function computeSummaryText(): ?string
    {
        $compute = $this->statsArray()['compute'] ?? null;
        if (!is_array($compute)) {
            return null;
        }

        // Stored stats are read back as data, so each count is forced to a plain number.
        $count = fn (string $key) => is_scalar($compute[$key] ?? null) ? max(0, (int) $compute[$key]) : 0;

        $computed = $count('days_computed');
        $locked   = $count('locked_skipped');
        $errors   = $count('errors');

        $text = "Computed: {$computed} " . ($computed === 1 ? 'day' : 'days');
        if ($locked > 0) {
            $text .= ", {$locked} locked skipped";
        }
        if ($errors > 0) {
            $text .= ", {$errors} " . ($errors === 1 ? 'error' : 'errors');
        }

        return $text;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'source_run_id');
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class, 'source_run_id');
    }
}
