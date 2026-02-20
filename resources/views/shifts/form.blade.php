@extends('layouts.app')

@section('title', $shift ? 'Edit Shift' : 'Create Shift')
@section('page-title', $shift ? 'Edit Shift: ' . $shift->name : 'Create Shift')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST"
                      action="{{ $shift ? route('shifts.update', $shift) : route('shifts.store') }}">
                    @csrf
                    @if($shift)
                        @method('PUT')
                    @endif

                    {{-- Shift Name --}}
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Shift Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $shift->name ?? '') }}"
                               placeholder="e.g. Day Shift, Night Shift, Mid Shift"
                               required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Schedule --}}
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="start_time" class="form-label fw-semibold">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="start_time"
                                   class="form-control @error('start_time') is-invalid @enderror"
                                   value="{{ old('start_time', $shift ? \Carbon\Carbon::parse($shift->start_time)->format('H:i') : '08:00') }}"
                                   required>
                            @error('start_time')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="end_time" class="form-label fw-semibold">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="end_time"
                                   class="form-control @error('end_time') is-invalid @enderror"
                                   value="{{ old('end_time', $shift ? \Carbon\Carbon::parse($shift->end_time)->format('H:i') : '17:00') }}"
                                   required>
                            @error('end_time')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    {{-- Lunch --}}
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="lunch_start" class="form-label fw-semibold">Lunch Start <span class="text-danger">*</span></label>
                            <input type="time" name="lunch_start" id="lunch_start"
                                   class="form-control @error('lunch_start') is-invalid @enderror"
                                   value="{{ old('lunch_start', $shift ? \Carbon\Carbon::parse($shift->lunch_start)->format('H:i') : '12:00') }}"
                                   required>
                            @error('lunch_start')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="lunch_end" class="form-label fw-semibold">Lunch End <span class="text-danger">*</span></label>
                            <input type="time" name="lunch_end" id="lunch_end"
                                   class="form-control @error('lunch_end') is-invalid @enderror"
                                   value="{{ old('lunch_end', $shift ? \Carbon\Carbon::parse($shift->lunch_end)->format('H:i') : '13:00') }}"
                                   required>
                            @error('lunch_end')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    {{-- Work Minutes --}}
                    <div class="mb-3">
                        <label for="required_work_minutes" class="form-label fw-semibold">Required Work Minutes <span class="text-danger">*</span></label>
                        <input type="number" name="required_work_minutes" id="required_work_minutes"
                               class="form-control @error('required_work_minutes') is-invalid @enderror"
                               value="{{ old('required_work_minutes', $shift->required_work_minutes ?? 480) }}"
                               min="1" max="1440" required>
                        <div class="form-text">Total required work minutes per day (e.g. 480 = 8 hours).</div>
                        @error('required_work_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Grace Periods --}}
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="grace_in_minutes" class="form-label fw-semibold">Grace Period — In <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="grace_in_minutes" id="grace_in_minutes"
                                       class="form-control @error('grace_in_minutes') is-invalid @enderror"
                                       value="{{ old('grace_in_minutes', $shift->grace_in_minutes ?? 0) }}"
                                       min="0" max="120" required>
                                <span class="input-group-text">minutes</span>
                                @error('grace_in_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">Minutes after start time before marking as late.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="grace_out_minutes" class="form-label fw-semibold">Grace Period — Out <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="grace_out_minutes" id="grace_out_minutes"
                                       class="form-control @error('grace_out_minutes') is-invalid @enderror"
                                       value="{{ old('grace_out_minutes', $shift->grace_out_minutes ?? 0) }}"
                                       min="0" max="120" required>
                                <span class="input-group-text">minutes</span>
                                @error('grace_out_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">Minutes before end time before marking as early out.</div>
                        </div>
                    </div>

                    {{-- Lunch Inference Window --}}
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="lunch_inference_window_before_minutes" class="form-label fw-semibold">Lunch Window — Before</label>
                            <div class="input-group">
                                <input type="number" name="lunch_inference_window_before_minutes" id="lunch_inference_window_before_minutes"
                                       class="form-control @error('lunch_inference_window_before_minutes') is-invalid @enderror"
                                       value="{{ old('lunch_inference_window_before_minutes', $shift->lunch_inference_window_before_minutes ?? 0) }}"
                                       min="0" max="120" required>
                                <span class="input-group-text">minutes</span>
                                @error('lunch_inference_window_before_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">Minutes before lunch_start to look for lunch punch.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="lunch_inference_window_after_minutes" class="form-label fw-semibold">Lunch Window — After</label>
                            <div class="input-group">
                                <input type="number" name="lunch_inference_window_after_minutes" id="lunch_inference_window_after_minutes"
                                       class="form-control @error('lunch_inference_window_after_minutes') is-invalid @enderror"
                                       value="{{ old('lunch_inference_window_after_minutes', $shift->lunch_inference_window_after_minutes ?? 60) }}"
                                       min="0" max="120" required>
                                <span class="input-group-text">minutes</span>
                                @error('lunch_inference_window_after_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">Minutes after lunch_end to look for lunch punch.</div>
                        </div>
                    </div>

                    {{-- Buttons --}}
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> {{ $shift ? 'Update Shift' : 'Create Shift' }}
                        </button>
                        <a href="{{ route('shifts.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
