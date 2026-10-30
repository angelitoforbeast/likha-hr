{{-- One shift change asked for several days: one card, one Approve and one Reject for all of its days. --}}
@php
    $first = $card['first'];
    $dates = $card['dates'];
    $uid = $card['uid'];
    $approvable = collect($card['entries'])->reject(fn ($e) => isset($card['blocked'][$e['date']]))->values();
    $short = fn ($date) => \Carbon\Carbon::parse($date)->format('M d');
    $officialNow = collect($card['entries'])->map(fn ($e) => $first->displayValue($e['official'][$e['rows']->first()->id] ?? null, $shiftNames, false))->unique()->implode(', ');
@endphp
<div class="approval-group card border-0 shadow-sm mb-3" data-group-key="{{ $card['key'] }}" data-group-employee="{{ $card['employee']->id }}">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
            <div>
                <input type="checkbox" class="form-check-input entry-select group-select me-1" autocomplete="off" data-group="{{ $uid }}" aria-label="Select every day of this shift change">
                @foreach($card['entries'] as $e)
                    <input type="hidden" class="group-day-input" data-group-of="{{ $uid }}" form="severalForm" name="entries[{{ $uid }}_{{ $e['date'] }}][id]" value="{{ $e['anchor'] }}" disabled><input type="hidden" class="group-day-input" data-group-of="{{ $uid }}" form="severalForm" name="entries[{{ $uid }}_{{ $e['date'] }}][seen]" value="{{ $e['seen'] }}" disabled>
                @endforeach
                <span class="fw-bold">{{ $card['employee']->display_name }}</span>
                <span class="text-muted">&middot;</span>
                <span class="approval-value">Shift → {{ $card['shift'] }}</span>
                <span class="text-muted">&middot; {{ $short($dates[0]) }}–{{ $short(end($dates)) }} ({{ count($dates) }} days)</span>
            </div>
            <div class="d-flex flex-wrap gap-1">
                @if($card['inactive'])
                    <span class="badge bg-warning text-dark approval-flag">Inactive employee</span>
                @endif
                <span class="badge bg-light text-dark border approval-flag">One shift change</span>
            </div>
        </div>
        <div class="small">Dates: {{ $card['ranges'] }}</div>
        <div class="small text-muted">Official now: {{ $officialNow }}</div>
        @foreach($card['reasons'] as $reason)
            <div class="small">Reason: {{ $reason }}</div>
        @endforeach
        <div class="small text-muted mb-2">Asked by {{ $first->requesterName() }} on {{ $first->created_at->format('M d, Y g:i A') }}</div>

        @foreach($card['blocked'] as $date => $why)
            <div class="small text-danger fw-semibold approval-group-blocked"><i class="bi bi-lock"></i> {{ $short($date) }} cannot be approved: {{ $why }}</div>
        @endforeach

        @include('approvals._totals', ['totals' => $card['totals']])

        <div class="approval-actions d-flex flex-wrap align-items-start gap-2">
            <form method="POST" action="{{ url('/approvals/shift-group/approve') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form" data-kind="group"
                  data-confirm="Approve {{ $approvable->count() }} {{ $approvable->count() === 1 ? 'day' : 'days' }} of this shift change?" data-yes="Yes, approve">
                @csrf
                <input type="hidden" name="key" value="{{ $card['key'] }}">
                @foreach($approvable as $n => $e)
                    <input type="hidden" name="entries[{{ $n }}][id]" value="{{ $e['anchor'] }}"><input type="hidden" name="entries[{{ $n }}][seen]" value="{{ $e['seen'] }}">
                @endforeach
                @if($card['drift'])
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="confirm{{ $uid }}" required>
                        <label class="form-check-label small" for="confirm{{ $uid }}">I have seen the changed values</label>
                    </div>
                @endif
                <button type="submit" class="btn btn-success approval-approve" @disabled($approvable->isEmpty())>
                    <i class="bi bi-check-lg"></i> Approve {{ $approvable->count() }} {{ $approvable->count() === 1 ? 'day' : 'days' }}
                </button>
            </form>
            <form method="POST" action="{{ url('/approvals/shift-group/reject') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form" data-kind="group"
                  data-confirm="Reject every day of this shift change with this reason?" data-yes="Yes, reject">
                @csrf
                <input type="hidden" name="key" value="{{ $card['key'] }}">
                @foreach($card['entries'] as $n => $e)
                    <input type="hidden" name="entries[{{ $n }}][id]" value="{{ $e['anchor'] }}"><input type="hidden" name="entries[{{ $n }}][seen]" value="{{ $e['seen'] }}">
                @endforeach
                <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 16rem"
                       required minlength="3" maxlength="500" placeholder="Reason for rejecting" aria-label="Reason for rejecting the shift change">
                <button type="submit" class="btn btn-outline-danger approval-reject"><i class="bi bi-x-lg"></i> Reject all</button>
            </form>
        </div>

        <details class="approval-group-days mt-2">
            <summary class="small fw-semibold">See each day</summary>
            <div class="mt-2">
                @foreach($card['entries'] as $e)
                    @include('approvals._entry', ['entry' => $e, 'i' => $uid . '_' . $e['date'], 'inGroup' => true])
                @endforeach
            </div>
        </details>
    </div>
</div><!-- /approval-group -->
