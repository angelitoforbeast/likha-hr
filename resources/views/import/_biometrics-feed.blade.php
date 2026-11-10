{{-- The office biometrics feed (spec 012, decision 11). $feed comes from BiometricFeedHealth::snapshot(true). --}}
@php
    $contact = $feed['last_contact']?->copy()->setTimezone('Asia/Manila');
    $codes = array_column($feed['warnings'], 'code');
    $level = in_array('danger', array_column($feed['warnings'], 'level'), true) ? 'danger'
        : (count($feed['warnings']) ? 'warning' : null);
@endphp
<div id="biometrics-feed" class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-fingerprint"></i> Office biometrics feed</h5>
        <span class="small text-muted">
            @if($contact === null)
                Office PC: not set up yet
            @elseif(in_array('no_contact', $codes, true))
                Office PC: silent
            @else
                Office PC: in contact
            @endif
            @if($feed['agent_version'])
                · agent {{ $feed['agent_version'] }}
            @endif
        </span>
    </div>
    <div class="card-body">
        @if($level)
            <div class="alert alert-{{ $level }} small py-2">
                @foreach($feed['warnings'] as $warning)
                    <div data-warning="{{ $warning['code'] }}"><i class="bi bi-exclamation-triangle-fill"></i> {{ $warning['message'] }}</div>
                @endforeach
            </div>
        @endif

        <div class="row g-3 small">
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Last contact</div>
                <div class="fw-semibold">{{ $contact ? $contact->format('M d, Y g:i A') : '—' }}</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Device</div>
                <div class="fw-semibold">
                    @if($feed['device_reachable'] === null)
                        Device: no report yet
                    @elseif($feed['device_reachable'])
                        Device: reachable
                    @else
                        Device: not reachable
                        @if($feed['device_error'])
                            <span class="text-muted fw-normal">({{ $feed['device_error'] }})</span>
                        @endif
                    @endif
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Records on the device</div>
                <div class="fw-semibold">
                    {{ $feed['device_record_count'] === null ? '—' : number_format($feed['device_record_count']) }} / {{ number_format($feed['device_capacity']) }}
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Clock drift (office PC vs Likha HR)</div>
                <div class="fw-semibold">
                    @if($feed['clock_drift_seconds'] === null)
                        —
                    @else
                        {{ sprintf('%+d s', $feed['clock_drift_seconds']) }}
                        <span class="text-muted fw-normal">
                            ({{ $feed['clock_set'] ? 'device clock set' : 'device clock not set' }}{{ $feed['clock_checked_at'] ? ', ' . $feed['clock_checked_at']->copy()->setTimezone('Asia/Manila')->format('M d g:i A') : '' }})
                        </span>
                    @endif
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Held punches (no employee yet)</div>
                <div class="fw-semibold" id="feed-held">{{ number_format($feed['held_count']) }}</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Refused (date before 2020 or over a day ahead)</div>
                <div class="fw-semibold" id="feed-refused">{{ number_format($feed['refused_count']) }}</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Latest punch received</div>
                <div class="fw-semibold">{{ $feed['last_punch_at'] ? \Carbon\Carbon::parse($feed['last_punch_at'])->format('M d, Y g:i A') : '—' }}</div>
            </div>
        </div>

        @if(count($feed['held_by_id']))
            <h6 class="mt-3 mb-1 small fw-semibold">Held punches per ID: applied when HR adds the employee</h6>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light"><tr><th>ZKTeco ID</th><th>Punches</th><th>From</th><th>To</th></tr></thead>
                    <tbody>
                        @foreach($feed['held_by_id'] as $held)
                            <tr data-held-id="{{ $held['zkteco_id'] }}">
                                <td class="font-monospace">{{ $held['zkteco_id'] }}</td>
                                <td>{{ $held['punches'] }} {{ (int) $held['punches'] === 1 ? 'punch' : 'punches' }}</td>
                                <td>{{ \Carbon\Carbon::parse($held['first_punch'])->format('M d, Y g:i A') }}</td>
                                <td>{{ \Carbon\Carbon::parse($held['last_punch'])->format('M d, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(count($feed['device_users_not_in_hr']))
            <h6 class="mt-3 mb-1 small fw-semibold">Users on the device, not in Likha HR yet</h6>
            <div class="small text-muted mb-1">Shown only. Add them through Sync Users; nothing here changes an employee.</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light"><tr><th>Device user ID</th><th>Name on the device</th></tr></thead>
                    <tbody>
                        @foreach($feed['device_users_not_in_hr'] as $user)
                            <tr data-device-user="{{ $user['user_id'] }}">
                                <td class="font-monospace">{{ $user['user_id'] }}</td>
                                <td>{{ $user['name'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
<!-- /biometrics-feed -->
