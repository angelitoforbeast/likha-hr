{{-- A shift assignment of the employee profile for a whole range: one card, one decision. --}}
@php
    $row = $card['row'];
    $preview = $card['preview'];
    $short = fn ($date) => \Carbon\Carbon::parse($date)->format('M d');
@endphp
<div class="approval-assignment card border-0 shadow-sm mb-3" data-anchor="{{ $card['anchor'] }}" data-assignment-employee="{{ $card['employee']->id }}">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
            <div>
                <input type="checkbox" class="form-check-input entry-select me-1" form="severalForm"
                       name="entries[a{{ $n }}][id]" value="{{ $card['anchor'] }}" aria-label="Select this shift assignment">
                <input type="hidden" form="severalForm" name="entries[a{{ $n }}][seen]" value="{{ $card['seen'] }}">
                <span class="fw-bold">{{ $card['employee']->display_name }}</span>
                <span class="text-muted">&middot;</span>
                <span class="approval-value">{{ $card['summary'] }}</span>
            </div>
            <div class="d-flex flex-wrap gap-1">
                @if($card['employee']->status !== 'active')
                    <span class="badge bg-warning text-dark approval-flag">Inactive employee</span>
                @endif
                <span class="badge bg-light text-dark border approval-flag">Employee profile</span>
            </div>
        </div>
        <div class="small">Reason: {{ $row->reason }}</div>
        <div class="small text-muted mb-2">Asked by {{ $row->requesterName() }} on {{ $row->created_at->format('M d, Y g:i A') }}@if($row->changed_at), changed {{ $row->changed_at->format('M d, Y g:i A') }}@endif</div>

        @if($card['drift'] === 'gone')
            <div class="small text-danger fw-semibold"><i class="bi bi-exclamation-triangle"></i> The assignment this asks to change was removed since. It can only be rejected.</div>
        @elseif($card['drift'] === 'changed')
            <div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> The assignment was changed since this was asked: it is now as the profile shows it, not as "now" above.</div>
        @endif
        @foreach($preview['locked'] as $date)
            <div class="small text-danger fw-semibold"><i class="bi bi-lock"></i> {{ $short($date) }} is locked: its day is not recomputed</div>
        @endforeach
        @if($preview['error'])
            <div class="small text-muted approval-preview-error">Preview not available: {{ $preview['error'] }}</div>
        @elseif(empty($preview['days']))
            <div class="small text-muted mb-2">No computed day in this range yet: approving it recomputes nothing.</div>
        @else
            @include('approvals._totals', ['totals' => $card['totals']])
        @endif

        <div class="approval-actions d-flex flex-wrap align-items-start gap-2">
            <form method="POST" action="{{ url('/approvals/' . $card['anchor'] . '/approve') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form" data-kind="one">
                @csrf
                <input type="hidden" name="seen" value="{{ $card['seen'] }}">
                @if($card['drift'] === 'changed')
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="confirmA{{ $card['anchor'] }}" required>
                        <label class="form-check-label small" for="confirmA{{ $card['anchor'] }}">I have seen the changed values</label>
                    </div>
                @endif
                <button type="submit" class="btn btn-success approval-approve" @disabled($card['drift'] === 'gone')><i class="bi bi-check-lg"></i> Approve</button>
            </form>
            <form method="POST" action="{{ url('/approvals/' . $card['anchor'] . '/reject') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form" data-kind="one">
                @csrf
                <input type="hidden" name="seen" value="{{ $card['seen'] }}">
                <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 16rem"
                       required minlength="3" maxlength="500" placeholder="Reason for rejecting" aria-label="Reason for rejecting">
                <button type="submit" class="btn btn-outline-danger approval-reject"><i class="bi bi-x-lg"></i> Reject</button>
            </form>
        </div>

        @if(!empty($preview['days']))
            <details class="approval-group-days mt-2">
                <summary class="small fw-semibold">See each day ({{ count($preview['days']) }} with attendance)</summary>
                <div class="mt-2">
                    @foreach($preview['days'] as $date => $day)
                        <div class="approval-assignment-day border rounded p-2 mb-2">
                            <div class="fw-semibold small mb-1">{{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</div>
                            @include('approvals._preview', [
                                'preview' => ['now' => $day['now'], 'after' => $day['after'], 'error' => $day['after'] ? null : 'It could not be worked out.', 'warnings' => $day['after'] ? app(\App\Services\AttendanceApprovalService::class)->warnings($day['after']) : []],
                                'entry'   => ['rows' => collect([$row]), 'locked' => false, 'blocks' => []],
                            ])
                        </div>
                    @endforeach
                    @if($preview['more'] > 0)
                        <div class="small text-muted">{{ $preview['more'] }} earlier {{ $preview['more'] === 1 ? 'day is' : 'days are' }} recomputed too and not shown.</div>
                    @endif
                </div>
            </details>
        @endif
    </div>
</div><!-- /approval-assignment -->
