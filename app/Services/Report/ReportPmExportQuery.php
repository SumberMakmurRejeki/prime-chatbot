<?php

namespace App\Services\Report;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportPmExportQuery
{
    public function __construct(private readonly ReportPmParentQuery $parentQuery) {}

    /**
     * Mengambil halaman occurrence parent dengan tuple continuation tanpa OFFSET.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{period_mode:string,resolved_start_date:?string,resolved_end_date:?string,include_attention_backlog:bool}  $scope
     * @param  array{status_bucket:int,scheduled_date:string,machine_code_sort:string,schedule_date_id:int}|null  $continuation
     * @return list<object>
     */
    public function parentPage(array $filters, array $scope, ?array $continuation, int $limit): array
    {
        $status = 'LOWER(COALESCE(pe.status, psd.status))';
        $query = $this->parentQuery->build($filters, $scope, true);
        $this->applyContinuation($query, $continuation, $status);

        return $query->limit($limit)->get()->all();
    }

    /**
     * @return list<object>
     */
    public function detailPage(int $executionId, ?int $lastItemId, int $limit): array
    {
        return DB::table('pm_execution_items as pei')->where('pei.pm_execution_id', $executionId)
            ->when($lastItemId !== null, fn (Builder $query) => $query->where('pei.id', '>', $lastItemId))
            ->orderBy('pei.id')->limit($limit)->get([
                'pei.id as execution_item_id', 'pei.part_name_snapshot', 'pei.standard_name_snapshot',
                'pei.input_type_snapshot', 'pei.action_value', 'pei.number_value', 'pei.min_value_snapshot',
                'pei.max_value_snapshot', 'pei.unit_snapshot', 'pei.is_warning', 'pei.note as operator_note',
            ])->all();
    }

    /**
     * @param  list<int>  $itemIds
     * @param  array{item_id:int,position:int,id:int}|null  $continuation
     * @return list<object>
     */
    public function actionLabelsFor(array $itemIds, ?array $continuation, int $limit): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('pm_execution_item_action_results as action')->whereIn('action.pm_execution_item_id', $itemIds)
            ->when($continuation !== null, function (Builder $query) use ($continuation): void {
                $query->where(function (Builder $after) use ($continuation): void {
                    $after->where('action.pm_execution_item_id', '>', $continuation['item_id'])
                        ->orWhere(function (Builder $sameItem) use ($continuation): void {
                            $sameItem->where('action.pm_execution_item_id', $continuation['item_id'])
                                ->where('action.option_position_snapshot', '>', $continuation['position']);
                        })
                        ->orWhere(function (Builder $samePosition) use ($continuation): void {
                            $samePosition->where('action.pm_execution_item_id', $continuation['item_id'])
                                ->where('action.option_position_snapshot', $continuation['position'])
                                ->where('action.id', '>', $continuation['id']);
                        });
                });
            })->orderBy('action.pm_execution_item_id')->orderBy('action.option_position_snapshot')->orderBy('action.id')
            ->limit($limit)->get([
                'action.id', 'action.pm_execution_item_id', 'action.option_position_snapshot', 'action.selected_label_snapshot',
            ])->all();
    }

    /**
     * @param  array{status_bucket:int,scheduled_date:string,machine_code_sort:string,schedule_date_id:int}|null  $continuation
     */
    private function applyContinuation(Builder $query, ?array $continuation, string $status): void
    {
        if ($continuation === null) {
            return;
        }

        $query->where(function (Builder $after) use ($continuation, $status): void {
            $bucket = "CASE WHEN {$status} IN ('overdue', 'missed') THEN 0 ELSE 1 END";
            $after->whereRaw("{$bucket} > ?", [$continuation['status_bucket']])
                ->orWhere(function (Builder $sameBucket) use ($continuation, $bucket): void {
                    $sameBucket->whereRaw("{$bucket} = ?", [$continuation['status_bucket']])
                        ->where('psd.scheduled_date', '<', $continuation['scheduled_date']);
                })
                ->orWhere(function (Builder $sameDate) use ($continuation, $bucket): void {
                    $sameDate->whereRaw("{$bucket} = ?", [$continuation['status_bucket']])
                        ->where('psd.scheduled_date', '=', $continuation['scheduled_date'])
                        ->where('m.machine_code', '>', $continuation['machine_code_sort']);
                })
                ->orWhere(function (Builder $sameMachine) use ($continuation, $bucket): void {
                    $sameMachine->whereRaw("{$bucket} = ?", [$continuation['status_bucket']])
                        ->where('psd.scheduled_date', '=', $continuation['scheduled_date'])
                        ->where('m.machine_code', '=', $continuation['machine_code_sort'])
                        ->where('psd.id', '<', $continuation['schedule_date_id']);
                });
        });
    }
}
