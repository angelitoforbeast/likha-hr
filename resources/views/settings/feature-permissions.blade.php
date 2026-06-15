@extends('layouts.app')

@section('title', 'Feature Permissions')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Feature Permissions</h4>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<form method="POST" action="{{ route('settings.feature-permissions.update') }}">
    @csrf

    {{-- ========== NAVIGATION VISIBILITY ========== --}}
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-list-ul"></i> Navigation Visibility</h6>
            <small class="text-muted">
                Control which sidebar menu items appear per role.
                <strong>CEO can declutter their own sidebar</strong> by unchecking items — URL access is still kept for CEO.
                Other roles are fully blocked (sidebar + URL) when unchecked.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="min-width: 220px;">Nav Item</th>
                            @foreach($roles as $roleKey => $roleLabel)
                            <th class="text-center" style="min-width: 100px;">
                                <span class="badge {{ $roleKey === 'ceo' ? 'bg-danger' : ($roleKey === 'admin' ? 'bg-primary' : 'bg-secondary') }}">
                                    {{ $roleLabel }}
                                </span>
                            </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($navMatrix as $featureKey => $feature)
                        <tr>
                            <td class="ps-3 fw-medium">{{ $feature['label'] }}</td>
                            @foreach($roles as $roleKey => $roleLabel)
                            <td class="text-center">
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input type="hidden" name="permissions[{{ $featureKey }}][{{ $roleKey }}][can_view]" value="0">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="permissions[{{ $featureKey }}][{{ $roleKey }}][can_view]"
                                           value="1"
                                           {{ ($feature['roles'][$roleKey]['can_view'] ?? false) ? 'checked' : '' }}>
                                </div>
                            </td>
                            @endforeach
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ========== EMPLOYEE PAGE SECTIONS ========== --}}
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-person-lines-fill"></i> Employee Page Section Visibility</h6>
            <small class="text-muted">Control which sections are visible and editable per role on the Employee edit page.</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="min-width: 200px;">Feature / Section</th>
                            @foreach($roles as $roleKey => $roleLabel)
                            <th class="text-center" colspan="2" style="min-width: 160px;">
                                <span class="badge {{ $roleKey === 'ceo' ? 'bg-danger' : ($roleKey === 'admin' ? 'bg-primary' : 'bg-secondary') }}">
                                    {{ $roleLabel }}
                                </span>
                            </th>
                            @endforeach
                        </tr>
                        <tr class="table-light">
                            <th></th>
                            @foreach($roles as $roleKey => $roleLabel)
                            <th class="text-center small" style="width: 80px;">
                                <i class="bi bi-eye"></i> View
                            </th>
                            <th class="text-center small" style="width: 80px;">
                                <i class="bi bi-pencil"></i> Edit
                            </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($employeeMatrix as $featureKey => $feature)
                        <tr>
                            <td class="ps-3 fw-medium">{{ $feature['label'] }}</td>
                            @foreach($roles as $roleKey => $roleLabel)
                            <td class="text-center">
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input type="hidden" name="permissions[{{ $featureKey }}][{{ $roleKey }}][can_view]" value="0">
                                    <input class="form-check-input permission-view"
                                           type="checkbox"
                                           name="permissions[{{ $featureKey }}][{{ $roleKey }}][can_view]"
                                           value="1"
                                           data-feature="{{ $featureKey }}"
                                           data-role="{{ $roleKey }}"
                                           {{ ($feature['roles'][$roleKey]['can_view'] ?? false) ? 'checked' : '' }}>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input type="hidden" name="permissions[{{ $featureKey }}][{{ $roleKey }}][can_edit]" value="0">
                                    <input class="form-check-input permission-edit"
                                           type="checkbox"
                                           name="permissions[{{ $featureKey }}][{{ $roleKey }}][can_edit]"
                                           value="1"
                                           data-feature="{{ $featureKey }}"
                                           data-role="{{ $roleKey }}"
                                           {{ ($feature['roles'][$roleKey]['can_edit'] ?? false) ? 'checked' : '' }}>
                                </div>
                            </td>
                            @endforeach
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i>
                    Checking "Edit" automatically enables "View". Unchecking "View" automatically disables "Edit".
                </small>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save All Permissions
        </button>
    </div>
</form>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // When Edit is checked, auto-check View
    document.querySelectorAll('.permission-edit').forEach(function(editCb) {
        editCb.addEventListener('change', function() {
            if (this.checked) {
                const feature = this.dataset.feature;
                const role = this.dataset.role;
                const viewCb = document.querySelector(`.permission-view[data-feature="${feature}"][data-role="${role}"]`);
                if (viewCb) viewCb.checked = true;
            }
        });
    });

    // When View is unchecked, auto-uncheck Edit
    document.querySelectorAll('.permission-view').forEach(function(viewCb) {
        viewCb.addEventListener('change', function() {
            if (!this.checked) {
                const feature = this.dataset.feature;
                const role = this.dataset.role;
                const editCb = document.querySelector(`.permission-edit[data-feature="${feature}"][data-role="${role}"]`);
                if (editCb) editCb.checked = false;
            }
        });
    });
});
</script>
@endpush
@endsection
