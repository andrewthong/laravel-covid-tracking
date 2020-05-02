<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

use App\Common;
use App\Option;
use App\Province;
use App\ProcessedReport;

class ReportController extends Controller
{

    /**
     * summary takes latest reports for each province and aggregates
     *  - $split if true, will not aggregate
     */
    public function summary( $split = false ) {// cache
        $cache_key = "summary";
        if( $split ) $cache_key .= "_split";
        $value = Cache::get( $cache_key, function() use ($split) {
            
            // setup
            $province_codes = Common::getProvinceCodes();
            $core_attrs = Common::attributes();
            $change_prefix = 'change_';
            $total_prefix = 'total_';

            // meta
            $option_last = 'report_last_processed';
            $last_run = Option::get($option_last);

            // preparing SQL query
            $select_core = [];
            $date_select = "MAX(date) AS latest_date";
            $stat_select = 'SUM(%1$s) AS %1$s';

            // $split modifiers, we no longer need to group
            if( $split ) {
                $select_core[] = "province";
                $date_select = "date";
                $stat_select = '%1$s';
            }

            $select_core[] = $date_select;
            foreach( [$change_prefix, $total_prefix] as $prefix ) {
                foreach( $core_attrs as $attr ) {
                    // $select_core[] = "SUM({$prefix}{$attr}) AS {$prefix}{$attr}";
                    $select_core[] = sprintf( $stat_select, "{$prefix}{$attr}" );
                }
            }

            $select_stmt = implode( ",", $select_core );

            $subquery_core = [];
            foreach( $province_codes as $pc ) {
                $subquery_core[] = "(
                    SELECT *
                    FROM processed_reports
                    WHERE
                        province='{$pc}'
                    ORDER BY `date` DESC
                    LIMIT 1
                )";
            }
            $subquery_stmt = implode( " UNION ", $subquery_core );

            $report = DB::select("
                SELECT
                    {$select_stmt}
                FROM (
                    {$subquery_stmt}
                ) pr
            ");

            $response = [
                'data' =>  $report,
                'last_updated' => $last_run,
            ];

            // return to be stored in
            return $response;
            
        });//cache closure

        return $value;
    }
    
    /*
        produces report with daily and cumulative totals for key attributes
    */
    public function generate( Request $request, $province = null ) {

        // setup
        $core_attrs = Common::attributes();
        $change_attrs = Common::attributes('change');
        $total_attrs = Common::attributes('total');
        // TODO: migrate to a config
        $change_prefix = 'change_';
        $total_prefix = 'total_';
        $reset_value = 0;

        $where_core = [];

        // query core modifiers
        foreach( [$change_prefix, $total_prefix] as $prefix ) {
            foreach( $core_attrs as $attr ) {
                $select_core[] = "SUM({$prefix}{$attr}) AS {$prefix}{$attr}";
            }
        }

        // check for province request
        if( $province ) {
            $where_core[] = "province = '{$province}'";
        }

        // date
        if( $request->date ) {
            $where_core[] = "`date` = '{$request->date}'";
        }
        // date range (if date is not provided)
        else if( $request->after ) {
            $where_core[] = "`date` >= '{$request->after}'";
            // before defaults to today
            $date_before = date('Y-m-d');
            if( $request->before ) {
                $date_before = $request->before;
            }
            $where_core[] = "`date` <= '{$date_before}'";
        }

        // stat
        // return on single statistic as defined
        if( $request->stat && in_array( $request->stat, $core_attrs ) ) {
            $core_attrs = [$request->stat];
        }

        // build out select list
        $select_core = ['date'];
        foreach( [$change_prefix, $total_prefix] as $prefix ) {
            foreach( $core_attrs as $attr ) {
                $select_core[] = "SUM({$prefix}{$attr}) AS {$prefix}{$attr}";
            }
        }
        
        // prepare SELECT
        $select_stmt = implode(",", $select_core);
        $where_stmt = "";
        if( $where_core ) {
            $where_stmt = "WHERE " . implode(" AND ", $where_core);
        }

        $result = DB::select("
            SELECT
                {$select_stmt}
            FROM
                processed_reports
            {$where_stmt}
            GROUP BY
                `date`
            ORDER BY
                `date`
        ");

        // convert DB::select to a basic array
        $data = json_decode(json_encode($result), true);

        // fill dates (useful for charting)
        if( $request->fill_dates ) {
            // prepare a reset array; all change_{stat} must be null
            $reset_arr = ['fill' => 1];
            foreach( $core_attrs as $attr ) {
                $reset_arr["{$change_prefix}{$attr}"] = null;
            }
            $data = Common::fillMissingDates( $data, $reset_arr );
        }

        $response = [
            'province' => $province ? $province : 'All',
            'data' => $data,
        ];

        return response()->json($response)->setEncodingOptions(JSON_NUMERIC_CHECK);
    }

}
