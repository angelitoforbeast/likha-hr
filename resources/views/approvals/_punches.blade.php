{{-- What the device stored for this employee and date, and which punches the day uses now. --}}
<div class="approval-punches border rounded p-2 mb-2">
    <div class="small fw-semibold mb-1">Device punches</div>
    @if(empty($punches['day']))
        <div class="small text-muted approval-punches-none">No punches from the device on this date.</div>
    @else
        <ul class="approval-punch-list list-unstyled mb-0">
            @foreach($punches['day'] as $punch)
                <li class="approval-punch">{{ $punch['at']->format('g:i A') }}@if($punch['labels'])<span class="small text-muted"> · {{ implode(', ', $punch['labels']) }}</span>@endif</li>
            @endforeach
        </ul>
    @endif
    @if(!empty($punches['next_day']))
        <div class="small text-muted mt-1 approval-punches-next">Next day: {{ collect($punches['next_day'])->map(fn ($at) => $at->format('g:i A'))->implode(', ') }}</div>
    @endif
</div><!-- /approval-punches -->
