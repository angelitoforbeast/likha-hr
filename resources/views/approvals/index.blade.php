@extends('layouts.app')

@section('title', 'Approvals')
@section('page-title', 'Approvals')

@push('styles')
<style>
    /* Reasons and names are free text: wrap them instead of widening the page. */
    .approval-entry { overflow-wrap: anywhere; }
    .approval-entry .approval-field + .approval-field { border-top: 1px solid #eee; }
    .approval-value { font-weight: 600; }
    .approval-flag { font-size: .8rem; }
    /* The preview: below the request on a phone, beside it (right) from a laptop width up. */
    .approval-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
    @media (min-width: 992px) {
        .approval-layout.has-preview {
            grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
            grid-template-rows: auto 1fr;
            grid-template-areas: "request preview" "actions preview";
        }
        .approval-layout.has-preview .approval-request { grid-area: request; }
        .approval-layout.has-preview .approval-side { grid-area: preview; align-self: start; }
        .approval-layout.has-preview .approval-actions { grid-area: actions; align-self: start; }
    }
    .approval-preview-grid { display: grid; grid-template-columns: minmax(0, 38fr) minmax(0, 31fr) minmax(0, 31fr); font-size: .85rem; }
    .approval-preview-line { display: contents; }
    .approval-preview-line > div { padding: .15rem .3rem; border-top: 1px solid #eee; }
    .approval-preview-head > div { font-weight: 600; border-top: 0; }
    .approval-preview-changed > div:last-child, .approval-preview-changed-note { background: #fff3cd; font-weight: 600; }
    /* Device punches: several to a line, wrapping on a phone instead of widening the page. */
    .approval-punch-list { display: flex; flex-wrap: wrap; gap: .1rem 1rem; font-size: .85rem; }
</style>
@endpush

@section('content')
@php
    $fmtDate = fn ($date) => \Carbon\Carbon::parse($date)->format('M d, Y');
@endphp

<div class="d-flex flex-wrap gap-2 mb-3">
    @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'] as $key => $label)
        <a href="{{ url('/approvals') }}{{ $key === 'pending' ? '' : '?status=' . $key }}"
           class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
    @endforeach
</div>

<form method="GET" action="{{ url('/approvals') }}" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            @if(!$isPending)<input type="hidden" name="status" value="{{ $status }}">@endif
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="fEmployee">Employee</label>
                <select name="employee_id" id="fEmployee" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($filterEmployees as $emp)
                        <option value="{{ $emp->id }}" @selected($filters['employee_id'] === $emp->id)>{{ $emp->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="fRequester">Asked by</label>
                <select name="requester_id" id="fRequester" class="form-select form-select-sm">
                    <option value="">Anyone</option>
                    @foreach($filterRequesters as $person)
                        <option value="{{ $person->id }}" @selected($filters['requester_id'] === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="fKind">Kind</label>
                <select name="kind" id="fKind" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach(['times' => 'Times', 'overtime' => 'Overtime', 'shift' => 'Shift', 'sil' => 'SIL', 'rest_day' => 'Rest day'] as $key => $label)
                        <option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="fFrom">Work date from</label>
                <input type="date" name="date_from" id="fFrom" class="form-control form-control-sm" value="{{ $filters['date_from'] }}">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="fTo">to</label>
                <input type="date" name="date_to" id="fTo" class="form-control form-control-sm" value="{{ $filters['date_to'] }}">
            </div>
            <div class="col-6 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            </div>
        </div>
    </div>
</form>

@if(empty($entries))
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-check2-circle" style="font-size: 2rem;"></i>
            <p class="mt-2 mb-0">
                @if($isPending)
                    Nothing is waiting for approval.
                @else
                    Nothing here yet.
                @endif
            </p>
        </div>
    </div>
@else
    @if($isPending)
    <form method="POST" id="severalForm" class="card border-0 shadow-sm mb-3 approval-form" data-kind="several">
        @csrf
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <div class="form-check me-2">
                <input class="form-check-input" type="checkbox" id="selectAllEntries"
                       onclick="document.querySelectorAll('.entry-select').forEach(cb => cb.checked = this.checked)">
                <label class="form-check-label small" for="selectAllEntries">Select all on this page</label>
            </div>
            <button type="submit" formaction="{{ url('/approvals/approve-several') }}" class="btn btn-sm btn-success"
                    data-confirm="Approve the selected entries?" data-yes="Yes, approve">Approve selected</button>
            <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 16rem"
                   maxlength="500" placeholder="Reason, to reject selected" aria-label="Reason, to reject selected">
            <button type="submit" formaction="{{ url('/approvals/reject-several') }}" class="btn btn-sm btn-outline-danger"
                    data-confirm="Reject the selected entries with this reason?" data-yes="Yes, reject" data-needs-reason="1">Reject selected</button>
        </div>
    </form>
    @endif

    {{-- What the last decision did, said in the page --}}
    <div id="approvalMsg" class="alert d-none" role="status" aria-live="polite"></div>

    <div id="approvalList">
    @php
        // Entries made by one bulk action, to offer one decision for all of them on this page.
        $batchEntries = $isPending ? collect($entries)->filter(fn ($e) => $e['batch'])->groupBy('batch') : collect();
        $batchShown = [];
    @endphp
    @foreach($entries as $i => $entry)
        @php
            $blocked = $isPending && (!empty($entry['blocks']) || $entry['locked']);
            $hasDrift = $isPending && !empty($entry['drift']);
        @endphp
        @if($isPending && $entry['batch'] && !isset($batchShown[$entry['batch']]) && $batchEntries[$entry['batch']]->count() > 1)
            @php $batchShown[$entry['batch']] = true; $inBatch = $batchEntries[$entry['batch']]; @endphp
            <div class="card border-0 shadow-sm mb-2 approval-batch" data-batch="{{ $entry['batch'] }}">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-semibold">
                        Bulk action by {{ $inBatch->first()['rows']->first()->requesterName() }}: {{ $inBatch->count() }} entries on this page
                    </span>
                    <form method="POST" action="{{ url('/approvals/batch/' . $entry['batch'] . '/approve') }}"
                          class="approval-form" data-kind="batch"
                          data-confirm="Approve every entry of this bulk action shown on this page?" data-yes="Yes, approve">
                        @csrf
                        @foreach($inBatch->values() as $n => $be)
                            <input type="hidden" name="entries[{{ $n }}][id]" value="{{ $be['anchor'] }}" data-batch-entry>
                            <input type="hidden" name="entries[{{ $n }}][seen]" value="{{ $be['seen'] }}" data-batch-entry>
                        @endforeach
                        <button type="submit" class="btn btn-sm btn-success">Approve batch</button>
                    </form>
                    <form method="POST" action="{{ url('/approvals/batch/' . $entry['batch'] . '/reject') }}" class="d-flex flex-wrap align-items-center gap-2 approval-form"
                          data-kind="batch" data-confirm="Reject every entry of this bulk action shown on this page with this reason?" data-yes="Yes, reject">
                        @csrf
                        @foreach($inBatch->values() as $n => $be)
                            <input type="hidden" name="entries[{{ $n }}][id]" value="{{ $be['anchor'] }}" data-batch-entry>
                            <input type="hidden" name="entries[{{ $n }}][seen]" value="{{ $be['seen'] }}" data-batch-entry>
                        @endforeach
                        <input type="text" name="reason" class="form-control form-control-sm" style="max-width: 14rem"
                               required minlength="3" maxlength="500" placeholder="Reason for rejecting" aria-label="Reason for rejecting the batch">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Reject batch</button>
                    </form>
                </div>
            </div>
        @endif
        <div class="approval-entry card border-0 shadow-sm mb-3" data-anchor="{{ $entry['anchor'] }}" data-batch="{{ $entry['batch'] }}">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        @if($isPending)
                            <input type="checkbox" class="form-check-input entry-select me-1" form="severalForm"
                                   name="entries[{{ $i }}][id]" value="{{ $entry['anchor'] }}" aria-label="Select this entry">
                            <input type="hidden" form="severalForm" name="entries[{{ $i }}][seen]" value="{{ $entry['seen'] }}">
                        @endif
                        <span class="fw-bold">{{ $entry['employee']->display_name }}</span>
                        <span class="text-muted">&middot; {{ $fmtDate($entry['date']) }}</span>
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
                            <span class="approval-value">{{ $row->displayValue($row->new_value, $shiftNames) }}</span>
                            @if($isPending)
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
    @endforeach
    </div>
    <div id="approvalListEnd"></div>

    <div class="mt-3">{{ $pairs->links() }}</div>
@endif
@endsection

{{-- Only where there is something to decide: the page with nothing waiting carries no script. --}}
@if($isPending && !empty($entries))
@push('scripts')
<script>
// Decisions on this page are sent in the background and answered in the page: a decided entry
// leaves the list and the count in the nav follows, a refusal is written beside the form that
// was used. Questions ("Approve the selected entries?") are asked in the page as well.
(function () {
    const token = document.querySelector('meta[name="csrf-token"]').content;

    function pageMessage(text, ok) {
        const box = document.getElementById('approvalMsg');
        if (!box) return;
        box.textContent = text;
        box.className = 'alert ' + (ok ? 'alert-success' : 'alert-warning');
    }

    // The message of one form, beside it.
    function formMessage(form, text) {
        let msg = form.parentNode.querySelector(':scope > .approval-form-msg[data-for="' + form.dataset.uid + '"]');
        if (!msg) {
            msg = document.createElement('div');
            msg.className = 'approval-form-msg small text-danger fw-semibold w-100';
            msg.dataset.for = form.dataset.uid;
            form.insertAdjacentElement('afterend', msg);
        }
        msg.textContent = text || '';
        msg.style.display = text ? '' : 'none';
    }

    function setNavCount(count) {
        const badge = document.getElementById('navApprovalsCount');
        if (!badge || typeof count !== 'number') return;
        if (count > 0) badge.textContent = String(count); else badge.remove();
    }

    // A decided entry leaves the page, with what the batch headings still hold of it.
    function removeEntry(id) {
        const card = document.querySelector('.approval-entry[data-anchor="' + id + '"]');
        const batch = card ? card.dataset.batch : '';
        if (card) card.remove();
        document.querySelectorAll('input[data-batch-entry][name$="[id]"][value="' + id + '"]').forEach(function (input) {
            const seen = input.nextElementSibling;
            if (seen && seen.hasAttribute('data-batch-entry')) seen.remove();
            input.remove();
        });
        if (batch && !document.querySelector('.approval-entry[data-batch="' + batch + '"]')) {
            document.querySelectorAll('.approval-batch[data-batch="' + batch + '"]').forEach(el => el.remove());
        }
        if (!document.querySelector('.approval-entry')) {
            const list = document.getElementById('approvalList');
            const empty = document.createElement('div');
            empty.className = 'text-center text-muted py-4';
            empty.textContent = 'Nothing is left on this page.';
            list.appendChild(empty);
        }
    }

    function entryMessage(id, text) {
        const card = document.querySelector('.approval-entry[data-anchor="' + id + '"]');
        if (!card) return;
        let msg = card.querySelector('.approval-entry-msg');
        if (!msg) {
            msg = document.createElement('div');
            msg.className = 'approval-entry-msg small text-danger fw-semibold mt-2';
            card.querySelector('.card-body').appendChild(msg);
        }
        msg.textContent = text;
    }

    function send(form, action) {
        formMessage(form, '');
        const buttons = form.querySelectorAll('button');
        buttons.forEach(b => b.disabled = true);
        fetch(action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        })
        .then(r => r.json().then(data => ({ status: r.status, data: data })))
        .then(function (reply) {
            const data = reply.data || {};
            buttons.forEach(function (b) { if (!b.hasAttribute('data-blocked')) b.disabled = false; });

            if (reply.status >= 400 || !data.success) {
                // Validation names the field; anything else carries one message.
                let text = data.message || 'It failed and nothing was changed.';
                if (data.errors) text = Object.values(data.errors)[0][0];
                if (data.needs_confirm && !form.querySelector('[name="confirmed"]')) text += ' Reload the page to see what changed.';
                formMessage(form, text);
                return;
            }

            if (Array.isArray(data.results)) {
                data.results.forEach(function (result) {
                    if (result.ok) removeEntry(result.id); else entryMessage(result.id, result.message);
                });
                const reason = form.querySelector('[name="reason"]');
                if (reason) reason.value = '';
                pageMessage(data.message, !data.results.some(r => !r.ok));
            } else {
                const card = form.closest('.approval-entry');
                if (card) removeEntry(card.dataset.anchor);
                pageMessage(data.message, true);
            }
            setNavCount(data.pending_entries);
        })
        .catch(function () {
            buttons.forEach(function (b) { if (!b.hasAttribute('data-blocked')) b.disabled = false; });
            formMessage(form, 'Network error. Nothing was changed.');
        });
    }

    // The question of a form, asked in a row under it: "Yes, …" sends, "Cancel" takes the row away.
    function ask(form, action, question, yesText) {
        document.querySelectorAll('.approval-confirm').forEach(el => el.remove());
        const row = document.createElement('div');
        row.className = 'approval-confirm d-flex flex-wrap align-items-center gap-2 small fw-semibold w-100 mt-1';
        const text = document.createElement('span');
        text.textContent = question;
        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'btn btn-sm btn-primary approval-confirm-yes';
        yes.textContent = yesText || 'Yes';
        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'btn btn-sm btn-outline-secondary approval-confirm-no';
        no.textContent = 'Cancel';
        yes.addEventListener('click', function () { row.remove(); send(form, action); });
        no.addEventListener('click', function () { row.remove(); });
        row.append(text, yes, no);
        form.insertAdjacentElement('afterend', row);
    }

    let uid = 0;
    document.querySelectorAll('form.approval-form').forEach(function (form) {
        form.dataset.uid = String(++uid);
        form.querySelectorAll('button[disabled]').forEach(b => b.setAttribute('data-blocked', '1'));

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const button = event.submitter;
            const action = (button && button.getAttribute('formaction')) || form.getAttribute('action');
            const question = (button && button.dataset.confirm) || form.dataset.confirm;
            const yesText = (button && button.dataset.yes) || form.dataset.yes;
            formMessage(form, '');

            if (form.dataset.kind === 'several' && !document.querySelector('.entry-select:checked')) {
                formMessage(form, 'Select at least one entry.');
                return;
            }
            const reason = form.querySelector('[name="reason"]');
            const needsReason = (button && button.dataset.needsReason) || (reason && reason.required);
            if (needsReason && reason) {
                const length = reason.value.trim().length;
                if (length < 3 || length > 500) {
                    formMessage(form, 'A reason of 3 to 500 characters is required to reject.');
                    reason.focus();
                    return;
                }
            }

            if (question) ask(form, action, question, yesText); else send(form, action);
        });
    });
})();
</script>
@endpush
@endif
