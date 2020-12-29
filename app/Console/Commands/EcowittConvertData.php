<?php

namespace App\Console\Commands;

use App\Exports\WeewxExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use SplFileInfo;

class EcowittConvertData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecowitt:convert {path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
     * @return int
     */
    public function handle()
    {
        $path = $this->argument('path');
        $files = collect(File::allFiles($path));
        $outputData = [];
        $files->each(function(SplFileInfo $file) use (&$outputData) {
            $ecowitt = json_decode(File::get($file->getPathname()),true);
            if (empty(data_get($ecowitt, 'list.tempf'))) {
                $this->comment("skip '{$file->getBasename()}'. next file");
                return;
            }

            // Temperature in C
            $outdoorTemp = $this->getData($ecowitt, 'list.tempf.list.tempf');
            
            // Feels Like in C
            $outdoorTempGust = $this->getData($ecowitt, 'list.tempf.list.sendible_temp');

            // Dew Point in C
            $outdoorDewTemp = $this->getData($ecowitt, 'list.tempf.list.drew_temp');

            // humidity in %
            $outdoorHumidity = $this->getData($ecowitt, 'list.humidity.list.humidity');

            // temp indoor in C
            $indoorTemp = $this->getData($ecowitt, 'list.tempinf.list.tempinf');

            // humidityin in %
            $indoorHumidity = $this->getData($ecowitt, 'list.humidity.list.humidity');

            // solar in lx -- Solar and UVI
            $solarradiation = $this->getData($ecowitt, 'list.solarradiation.list.solarradiation');

            // uv
            $uvi = $this->getData($ecowitt, 'list.uv.list.uv');
            
            // rainrate in mm/hr b
            $rainRateH = $this->getData($ecowitt, 'list.rain.list.rainratein');

            // daily rainrate total mm/hr
            $rainRateDaily = $this->getData($ecowitt, 'list.rain.list.dailyrainin');

            // wind_speed in m/s
            $windspeed = $this->getData($ecowitt, 'list.wind_speed.list.windspeedmph');

            // windGust
            $windGust = $this->getData($ecowitt, 'list.wind_speed.list.windgustmph');

            // winddir in degree
            $windir = $this->getData($ecowitt, 'list.winddir.list.winddir');

            // pressure relative in hPa
            $pressureRel = $this->getData($ecowitt, 'list.pressure.list.baromrelin');

            // pressure absolute in hPa
            $pressureAbs = $this->getData($ecowitt, 'list.pressure.list.baromabsin');

            foreach($outdoorTemp as $date => $temp) {
                $tmp = [
                    'date_and_time' => $date,                               // %Y-%m-%d %H:%M:%S
                    'temp_out' => $temp,                                    // degree
                    'temp_out_gust' => data_get($outdoorTempGust, $date),   // degree
                    'temp_out_dew' => data_get($outdoorDewTemp, $date),     // degree
                    'humid_out' => data_get($outdoorHumidity, $date),       // percent
                    'temp_in' => data_get($indoorTemp, $date),              // degree
                    'humid_in' => data_get($indoorHumidity, $date),         // percent
                    'rad' => data_get($solarradiation, $date),              // lx
                    'uv' => data_get($uvi, $date),
                    'rain' => data_get($rainRateH,                          // mm
                        $date
                    ),
                    'rain_daily' => data_get($rainRateDaily, $date),        // mm
                    'wind' => data_get($windspeed,                          // m_per_second
                        $date
                    ),
                    'wind_gust' => data_get($windGust, $date),              // m_per_second
                    'wind_dir' => data_get($windir, $date),                 // degree_compass
                    'pressure_rel' => data_get($pressureRel, $date),        // hPa
                    'pressure_abs' => data_get($pressureAbs, $date),        // hPa
                ];
                $outputData[] = $tmp;
            }
        });
        Excel::store(new WeewxExport($outputData), 'weewx.csv', null, \Maatwebsite\Excel\Excel::CSV);
    }

    public function getData($stack, $key) {
        return collect(data_get($stack, $key))
            ->mapWithKeys(function ($value) {
                return [$value[0] => $value[1] ?: null];
            });
    }
}
