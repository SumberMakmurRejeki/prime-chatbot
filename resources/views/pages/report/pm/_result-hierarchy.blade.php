<div class="grid gap-3" data-report-pm-hierarchy>
    @foreach ($parts as $part)
        <section class="rounded-lg border border-[rgba(0,0,0,0.08)] bg-white p-3">
            <h4 class="text-sm font-semibold text-[rgba(0,0,0,0.95)]">{{ $part['name'] }}</h4>
            <div class="mt-2 divide-y divide-[rgba(0,0,0,0.08)]">
                @foreach ($part['items'] as $item)
                    <div class="py-2 first:pt-0 last:pb-0">
                        <p class="text-sm font-medium text-[rgba(0,0,0,0.95)]">{{ $item->standard_name_snapshot ?: 'Standard' }}</p>
                        <div class="mt-1 text-sm text-[#615d59]">
                            @if ($item->input_type_snapshot === 'number')
                                <span>{{ $item->number_value ?? '-' }}{{ $item->unit_snapshot ? ' '.$item->unit_snapshot : '' }}</span>
                                @if ($item->target_value_snapshot !== null)
                                    <span> (Target: {{ $item->target_value_snapshot }}{{ $item->unit_snapshot ? ' '.$item->unit_snapshot : '' }})</span>
                                @endif
                            @elseif ($item->input_type_snapshot === 'range')
                                <span>{{ $item->number_value ?? '-' }}{{ $item->unit_snapshot ? ' '.$item->unit_snapshot : '' }}</span>
                                @if ($item->min_value_snapshot !== null || $item->max_value_snapshot !== null)
                                    <span> (Rentang: {{ $item->min_value_snapshot ?? '-' }}–{{ $item->max_value_snapshot ?? '-' }}{{ $item->unit_snapshot ? ' '.$item->unit_snapshot : '' }})</span>
                                @endif
                            @elseif ($item->input_type_snapshot === 'action' && $item->effectiveSelectionMode() === 'multiple')
                                @if ($item->actionResults->isNotEmpty())
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach ($item->actionResults as $actionResult)
                                            <li>{{ $actionResult->selected_label_snapshot }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <span>{{ $item->action_value ?? '-' }}</span>
                                @endif
                            @else
                                <span>{{ $item->action_value ?? '-' }}</span>
                            @endif
                        </div>
                        @if ($item->is_warning)
                            <p class="mt-1 text-xs font-semibold text-[#9a3412]">Warning{{ $item->warning_message ? ': '.$item->warning_message : '' }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
