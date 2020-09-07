<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Common;
use App\Utility;
use App\Option;

class ProcessHrReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:processhr';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Health Region tests, cases etc. for day-to-day changes and totals';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $option_last = 'report_hr_last_processed';
        $last_run = Option::get($option_last);
        $curr_env = config('app.env');

        $this->line('');

        $this->line("     __ _____    ___  _______  ____  ___  __________");
        $this->line("    / // / _ \  / _ \/ __/ _ \/ __ \/ _ \/_  __/ __/");
        $this->line("   / _  / , _/ / , _/ _// ___/ /_/ / , _/ / / _\ \  ");
        $this->line("  /_//_/_/|_| /_/|_/___/_/   \____/_/|_| /_/ /___/  ");

        $this->line('');

        $this->line(' # <fg=black;bg=white>Health Region report processing utility</>');
        $this->line(" # COVID-19 Tracker API v1.0 #");

        $this->line('');
        $this->line(" # Environment: <fg=yellow>${curr_env}</>");
        $this->line(" # Last Run: <fg=yellow>${last_run}</>");

        // prompt
        $mode_from = $this->choice('Process reports starting from', [
            1 => 'Today',
            2 => 'Yesterday',
            3 => 'Last week',
            4 => 'Custom date',
            0 => 'The beginning',
        ], 2);

        $mode_opt = null;
        switch ($mode_from) {
            case 'Yesterday':
                $mode_opt = 1;
                break;
            case 'Last week':
                $mode_opt = 7;
                break;
            case 'Custom date':
                $mode_opt = $this->ask('Please provide date (format: YYYY-MM-DD e.g. 2020-01-15)');
                break;
            case 'The beginning':
                $mode_opt = 'all';
                break;
            default: // today
                $mode_opt = null;
                break;
        }

        // health region
        $hr_uid = null;
        $choice_region = $this->choice('Would you like to process all Health Regions?', [
            1 => 'Yes',
            2 => 'No',
        ], 1);

        if( $choice_region !== 'Yes' ) {
            $hr_uid = $this->ask('Please enter the Health Region UID');
        }

        $mode = Utility::processReportsMode( $mode_opt );

        $this->output->write(' >> Starting process...');
            $this->line(' testing db connection');
        $this->line('');

        // process total_{stat}s (tests, hospitalizations, criticals, recoveries)
        $this->processReportTotals( $mode, $hr_uid );
        $this->line('');

        // fill in gaps (change <-> total)
        $this->processReportGaps( $mode, $hr_uid );
        $this->line('');

        $this->line(' Finising up...');

        Option::set( $option_last, date('Y-m-d H:i:s') );

        $this->line(" <fg=green;bg=black>Processing complete. Health Region Reports up to date.</>");
        $this->line('');
        $this->line(' Have a nice day ツ');
        $this->line('');
    }
    
    /**
     * totals are data that is stored in the reports log
     * they are an accumulate total of tracked stats
     * this sub-helper moves these totals to processedReports
     */
    public function processReportTotals( $mode = null, $hr_uid = null ) {

        // determine date to run on based on mode
        $from_date = $mode;

        // only for registered provinces
        $hr_uid_codes = Common::getHealthRegionCodes();
        if( in_array($hr_uid, $hr_uid_codes) ) {
            $hr_uid_codes = [$hr_uid];
        }

        // retrieve reports
        $reports = DB::table( 'hr_reports' )
            ->whereIn( 'hr_uid', $hr_uid_codes )
            ->when( $from_date, function( $query ) use( $from_date ) {
                $query->where( 'date', '>=', $from_date );
            })
            ->orderBy('date')
            ->get();

        // [artisan]
        $this->line(" Transferring daily totals");
        $this->line(" (cases, fatalities, tests, hospitalizations, criticals, recoveries, vaccinations)");
        $bar = $this->output->createProgressBar( count($reports) );
        $bar->start();

        // loop through reports and copy records over
        foreach( $reports as $report) {
            DB::table('processed_hr_reports')
                ->updateOrInsert(
                    [
                        'date' => $report->date,
                        'hr_uid' => $report->hr_uid
                    ],
                    [
                        'date' => $report->date,
                        'hr_uid' => $report->hr_uid,
                        'total_cases' => $report->cases,
                        'total_fatalities' => $report->fatalities,
                        'total_tests' => $report->tests,
                        'total_hospitalizations' => $report->hospitalizations,
                        'total_criticals' => $report->criticals,
                        'total_recoveries' => $report->recoveries,
                        'total_vaccinations' => $report->vaccinations,
                        'notes' => $report->notes,
                    ]
                );

            $bar->advance();
        }

        $bar->finish();
        $this->line("");
        $this->line(" Transfers complete >>");
 
    }
    
    /**
     * this sub-helper runs through process reports and attempts
     * to fill in incomplete change_ and total_ numbers
     */
    public function processReportGaps( $mode = null, $hr_uid = null ) {

        // determine date to run on based on mode
        $from_date = $mode;

        // list of health regions
        $hr_codes = Common::getHealthRegionCodes();
        if( in_array($hr_uid, $hr_codes) ) {
            $hr_codes = [$hr_uid];
        }

        // core attributes
        $core_attrs = [
            'cases',
            'fatalities',
            'tests',
            'hospitalizations',
            'criticals',
            'recoveries',
            'vaccinations',
        ];

        // HR reports is all total
        // attributes where change is expected and total must be calculated
        $change_attrs = [];
        // attributes where total is expected and change must be calculated
        $total_attrs = $core_attrs;

        $change_prefix = 'change_';
        $total_prefix = 'total_';
        $reset_value = 0;

        // control, starter to compare to
        $reset_arr = [];
        foreach( [$total_prefix, $change_prefix] as $prefix ) {
            foreach( $core_attrs as $attr ) {
                $reset_arr[$prefix.$attr] = $reset_value; 
            }
        }
        $reset_obj = (object) $reset_arr; // simplifying for later

        // count total
        $total_reports = DB::table( 'processed_hr_reports' )
            ->where( 'date', '>=', $from_date )
            ->count();

        // [artisan]
        $this->output->write(' Locating reports... ');
        $this->line("{$total_reports} found");
        $this->line(" Calculating numbers (changes <-> totals)...");
        $bar = $this->output->createProgressBar( $total_reports );
        $bar->start();

        // loop through each health region uid
        foreach( $hr_codes as $pc ) {

            // retrieve processed reports
            $reports = DB::table( 'processed_hr_reports' )
                ->where( 'hr_uid', '=', $pc )
                ->when( $from_date, function( $query ) use( $from_date ) {
                    $query->where( 'date', '>=', $from_date );
                })
                ->orderBy( 'date' )
                ->get();

            // attempt to retrieve a backtrack reference
            // defaults to our trusted 0 reset otherwise
            $backtrack = clone $reset_obj;
            if( $from_date ) {
                $bt = DB::table( 'processed_hr_reports' )
                    ->where( 'hr_uid', '=', $pc )
                    ->where( 'date', '<', $from_date )
                    ->orderBy( 'date', 'desc' )
                    ->first();
                if( $bt ) $backtrack = $bt;
            }

            // now let's loop through each report
            foreach( $reports as $report ) {
                $update_arr = [];
                // calculate total_ from change_
                foreach( $change_attrs as $attr ) {
                    $ch_attr = $change_prefix.$attr;
                    $tt_attr = $total_prefix.$attr;
                    // add current change with w/ backtrack total
                    $update_arr[ $tt_attr ] = 
                          $backtrack->{$tt_attr}
                        + $report->{$ch_attr};
                    $report->{$tt_attr} = $update_arr[ $tt_attr ];
                }
                // calculate change_ from total_
                foreach( $total_attrs as $attr ) {
                    $ch_attr = $change_prefix.$attr;
                    $tt_attr = $total_prefix.$attr;
                    // gaps can introduce weird results
                    // for health regions, ignore null
                    if( is_null($report->{$tt_attr}) ) {
                        // set it to backtrack value so change is 0
                        // $update_arr[ $tt_attr ] = $backtrack->{$tt_attr};
                        // $report->{$tt_attr} = $update_arr[ $tt_attr ];
                    } else {
                        // subtract current total w/ backtrack total
                        $update_arr[ $ch_attr ] =
                            $report->{$tt_attr}
                            - $backtrack->{$tt_attr};
                        $report->{$ch_attr} = $update_arr[ $ch_attr ];
                    }
                }
                // report is now new backtrack
                $backtrack = clone $report;

                // update db
                DB::table('processed_hr_reports')
                    ->where( 'id', '=', $report->id )
                    ->update( $update_arr );

                $bar->advance();
            }

        }

        $bar->finish();
        $this->line("");
        $this->line(" Calculations complete >>");

    }
}
