{{-- One employee and date: its requests, and for a pending one the preview and its own Approve/Reject. --}}
        @php
            $blocked = $isPending && (!empty($entry['blocks']) || $entry['locked']);
            $hasDrift = $isPending && !empty($entry['drift']);
        @endphp
        @if(empty($inGroup))
        <div class="approval-entry card border-0 shadow-sm mb-3" data-anchor="{{ $entry['anchor'] }}" data-batch="{{ $entry['batch'] }}">
        @else
        {{-- A day inside a shift change card: decided with the card, or on its own here. --}}
        <div class="approval-group-day border rounded mb-2" data-day-anchor="{{ $entry['anchor'] }}">
        @endif
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        @if($isPending && empty($inGroup))
                            <input type="checkbox" class="form-check-input entry-select me-1" form="severalForm"
                                   name="entries[{{ $i }}][id]" value="{{ $entry['anchor'] }}" aria-label="Select this entry">
                            <input type="hidden" form="severalForm" name="entries[{{ $i }}][seen]" value="{{ $entry['seen'] }}">
                        @endif
                        @if(empty($inGroup))
                        <span class="fw-bold">{{ $entry['employee']->display_name }}</span>
                        <span class="text-muted">&middot; {{ $fmtDate($entry['date']) }}</span>
                        @else
                        <span class="fw-semibold">{{ $fmtDate($entry['date']) }}</span>
                        @endif
                    </div>
                    <div class="d-flex flex-wrap gap-1">
                        @if($entry['locked'])
                            <span class="badge bg-danger approval-flag"><i class="bi bi-lock"></i> Date locked</span>
                        @endif
                        @if($entry['inactive'])
                            <span class="badge bg-warning text-dark approval-flag">Inactive employee</span>
                        @endif
                        @foreach($entry['blocks'] ?? [] as $block)
                            <span class="badge bg-danger approval-flag">{{ $block }}</span>
                        @endforeach
                        @if($entry['batch'])
                            <span class="badge bg-light text-dark border approval-flag">Bulk action</span>
                        @endif
                    </div>
                </div>

                <div @class(['approval-layout', 'has-preview' => $isPending && isset($entry['preview'])])>
                <div class="approval-request">
                @foreach($entry['rows'] as $row)
                    <div class="approval-field py-2">
                        <div class="small text-muted text-uppercase">{{ $row->fieldLabel() }}</div>
                        <div>
                            @if($row->isAssignment())
                                <span class="approval-value">{{ $row->assignmentSummary($shiftNames) }}</span>
                            @else
                            <span class="approval-value">{{ $row->displayValue($row->new_value, $shiftNames) }}</span>
                            @endif
                            @if($row->isAssignment())
                            @elseif($isPending)
                                <span class="text-muted small">
                                    (official now: {{ $row->displayValue($entry['official'][$row->id] ?? null, $shiftNames, false) }})
                                </span>
                                @if(isset($entry['drift'][$row->id]))
                                    <div class="text-danger small">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Official value changed: was {{ $row->displayValue($entry['drift'][$row->id]['was'], $shiftNames, false) }}, now {{ $row->displayValue($entry['drift'][$row->id]['now'], $shiftNames, false) }}
                                    </div>
                                @endif
                            @else
                                <span class="text-muted small">
                                    (was: {{ $row->displayValue($row->old_value, $shiftNames, false) }})
                                </span>
                            @endif
                        </div>
                        <div class="small">Reason: {{ $row->reason }}</div>
                        <div class="small text-muted">Asked by {{ $row->requesterName() }} on {{ $row->created_at->format('M d, Y g:i A') }}@if($row->changed_at), changed {{ $row->changed_at->format('M d, Y g:i A') }}@endif</div>
                        @unless($isPending)
                            <div class="small text-muted">
                                @if($row->status === 'approved')
                                    Approved by {{ $row->decider->name ?? 'Unknown' }} on {{ $row->decided_at?->format('M d, Y g:i A') }}
                                @elseif($row->status === 'rejected')
                                    Rejected by {{ $row->decider->name ?? 'Unknown' }} on {{ $row->decided_at?->format('M d, Y g:i A') }}: {{ $row->decision_reason }}
                                @elseif($row->status === 'no_longer_needed')
                                    Closed by itself on {{ $row->decided_at?->format('M d, Y g:i A') }}: {{ $row->decision_reason }}
                                @else
                                    Withdrawn by the requester
                                @endif
                            </div>
                        @endunless
                    </div>
                @endforeach

                @if($isPending)
                    @if($entry['day'] && $entry['rows']->contains(fn ($r) => in_array($r->field, ['sil', 'rest_day'], true)))
                        <div class="alert alert-light border small py-2 mt-2 mb-0">
                            This day has attendance:
                            In {{ $entry['day']->time_in ? $entry['day']->time_in->format('g:i A') : '-' }},
                            Lunch {{ $entry['day']->lunch_out ? $entry['day']->lunch_out->format('g:i A') : '-' }}
                            to {{ $entry['day']->lunch_in ? $entry['day']->lunch_in->format('g:i A') : '-' }},
                            Out {{ $entry['day']->time_out ? $entry['day']->time_out->format('g:i A') : '-' }}.
                        </div>
                    @endif
                @endif
                </div>

                @if($isPending)
                    @isset($entry['preview'])
                        <div class="approval-side">
                            @isset($entry['punches'])
                                @include('approvals._punches', ['punches' => $entry['punches']])
                            @endisset
                            @include('approvals._preview', ['preview' => $entry['preview'], 'entry' => $entry])
                        </div>
                    @endisset

                    <div class="approval-actions d-flex flex-wrap align-items-start gap-2">
                        <form method="POST" action="{{ url('/approvals/' . $entry['anchor'] . '/approve') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form" data-kind="one">
                            @csrf
                            <input type="hidden" name="seen" value="{{ $entry['seen'] }}">
                            @if($hasDrift && !$blocked)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="confirm{{ $entry['anchor'] }}" required>
                                    <label class="form-check-label small" for="confirm{{ $entry['anchor'] }}">I have seen the changed values</label>
                                </div>
                            @endif
                            <button type="submit" class="btn btn-success approval-approve" @disabled($blocked)>
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                        </form>
                        <form method="POST" action="{{ url('/approvals/' . $entry['anchor'] . '/reject') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form" data-kind="one">
                            @csrf
                            <input type="hidden" name="seen" value="{{ $entry['seen'] }}">
                            <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 16rem"
                                   required minlength="3" maxlength="500" placeholder="Reason for rejecting" aria-label="Reason for rejecting">
                            <button type="submit" class="btn btn-outline-danger approval-reject">
                                <i class="bi bi-x-lg"></i> Reject
                            </button>
                        </form>
                    </div>
                @endif
                </div>
            </div>
        </div>
