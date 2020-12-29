<?php

namespace App\Console\Commands;

use App\Exports\WeewxExport;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use SplFileInfo;

class EcowittConvertData extends Command
{
    /** @var Carbon */
    protected $startDate;

    /** @var Carbon */
    protected $endDate;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecowitt:export {startDate} {endDate}';

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
        $session_id = $this->getSessionId();
        $device_ids = $this->getDeviceIds($session_id);

        $this->startDate = Carbon::parse($this->argument('startDate'))->startOfDay()
            ->format('Y-m-d H:i');

        $this->startDate = Carbon::parse($this->argument('endDate'))->endOfDay()
            ->format('Y-m-d H:i');

        $device_ids->each(function ($deviceId) use ($session_id) {

            $startDate = $this->startDate;
            $endDate = $this->endDate;

            $response = Http::withCookies(
                [
                    'ousaite_session' => $session_id,
                ], 'www.ecowitt.net')
                ->asForm()
                ->post('https://webapi.www.ecowitt.net/index/get_data', [
                    'device_id' => $deviceId,
                    'is_list' => 0,
                    'mode' => 0,
                    'sdate' => $startDate,
                    'edate' => $endDate,
                    'page' => 1,
                ]);

            $ecowitt = $response->json();

            // declare output variable
            $outputData = [];

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

            foreach ($outdoorTemp as $date => $temp) {
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

            Excel::store(
                new WeewxExport($outputData),
                "ecowitt_{$deviceId}.csv",
                null,
                \Maatwebsite\Excel\Excel::CSV
            );
        });
    }

    protected function getData($stack, $key)
    {
        return collect(data_get($stack, $key))
            ->mapWithKeys(function ($value) {
                return [$value[0] => $value[1] ?: null];
            });
    }

    /**
     * simulate login to ecowitt.net and store session_id from cookie
     *
     * @return mixed
     */
    protected function getSessionId()
    {
        $response = Http::asForm()->post('https://webapi.www.ecowitt.net/user/site/login', [
            'account' => env('ECOWITT_ACCOUNT'),
            'password' => env('ECOWITT_PASSWORD'),
            'authorize' => '',
        ]);

        $loginData = $response->json();

        $cookies = $response->cookies();

        return $cookies->getCookieByName('ousaite_session')->getValue();
    }

    /**
     * fetch all available device IDs
     * @param $session_id
     * @return \Illuminate\Support\Collection
     */
    protected function getDeviceIds($session_id): \Illuminate\Support\Collection
    {
        $deviceResponse = Http::withCookies(
            [
                'ousaite_session' => $session_id,
            ], 'www.ecowitt.net')
            ->asForm()
            ->post('https://webapi.www.ecowitt.net/index/get_devices', [
                'uid' => '',
                'type' => 1
            ]);

        $devices = collect(data_get($deviceResponse->json(), 'device_list'));

        return $devices->map(function ($device) {
            return data_get($device, 'id');
        });
    }
}
