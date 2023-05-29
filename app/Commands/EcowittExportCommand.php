<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EcowittExportCommand extends Command
{
    /** @var Carbon */
    protected $startDate;

    /** @var Carbon */
    protected $endDate;

    protected $ecowitt_account;
    protected $ecowitt_passphrase;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'export {--debug} {--user=} {--pass=} {startDate} {endDate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'fetch all data from ecowitt';

    protected $times = [];

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
        $this->ecowitt_account = $this->option('user');
        $this->ecowitt_passphrase = $this->option('pass');

        if (empty($this->ecowitt_account) || empty($this->ecowitt_passphrase)) {
            $this->error('Ecowitt Username or passphrase required!');
            return 1;
        }

        $session_id = $this->getSessionId();
        $device_ids = $this->getDeviceIds($session_id);

        $this->startDate = Carbon::parse($this->argument('startDate'))->startOfDay();

        $this->endDate = Carbon::parse($this->argument('endDate'))->endOfDay();

        $device_ids->each(function ($deviceId) use ($session_id) {

            $startDate = $this->startDate->clone();
            $endDate = $this->endDate->clone();
            // declare output variable
            $outputData = [];

            do {
                $response = Http::withCookies(
                    [
                        'ousaite_session' => $session_id,
                    ], 'www.ecowitt.net')
                    ->asForm()
                    ->post('https://webapi.www.ecowitt.net/index/get_data', [
                        'device_id' => $deviceId,
                        'is_list' => 0,
                        'mode' => 0,
                        'sdate' => $startDate->clone()->startOfDay()->format('Y-m-d H:i'),
                        'edate' => $startDate->clone()->endOfDay()->format('Y-m-d H:i'),
                        'page' => 1,
                    ]);

                $this->comment("fetching range: {$startDate->clone()->startOfDay()} - {$startDate->clone()->endOfDay()}");

                $ecowitt = $response->json();

                $this->times = data_get($ecowitt,'times',[]);

                // Temperature in C
                $this->debug('fetch outdoor temp');
                $outdoorTemp = $this->getData($ecowitt, 'list.tempf.list.tempf');

                // Feels Like in C
                $this->debug('fetch outdoor temp gust');
                $outdoorTempGust = $this->getData($ecowitt, 'list.tempf.list.apparent_temp');

                // Dew Point in C
                $this->debug('collecting: Dew Point in C');
                $outdoorDewTemp = $this->getData($ecowitt, 'list.tempf.list.drew_temp');

                // humidity in %
                $this->debug('collecting: humidity in %');
                $outdoorHumidity = $this->getData($ecowitt, 'list.humidity.list.humidity');

                // temp indoor in C
                $this->debug('collecting: temp indoor in C');
                $indoorTemp = $this->getData($ecowitt, 'list.tempinf.list.tempinf');

                // humidityin in %
                $this->debug('collecting: humidityin in %');
                $indoorHumidity = $this->getData($ecowitt, 'list.humidity.list.humidity');

                // solar in lx -- Solar and UVI
                $this->debug('collecting: solar in lx -- Solar and UVI');
                $solarradiation = $this->getData($ecowitt, 'list.so_uv.list.solarradiation');

                // uv
                $this->debug('collecting: uv');
                $uvi = $this->getData($ecowitt, 'list.so_uv.list.uv');
                
                //replace empty values with 0
                foreach ($uvi as $key => $value) {
					if(empty($value)) $uvi[$key] = "0";
				}

                // rainrate in mm/hr b
                $this->debug('collecting: rainrate in mm/hr b');
                $rainRateH = $this->getData($ecowitt, 'list.rain.list.rainratein');

                // daily rainrate total mm/hr
                $this->debug('collecting: daily rainrate total mm/hr');
                $rainRateDaily = $this->getData($ecowitt, 'list.rain.list.dailyrainin');

                // wind_speed in m/s
                $this->debug('collecting: wind_speed in m/s');
                $windspeed = $this->getData($ecowitt, 'list.wind_speed.list.windspeedmph');

                // windGust
                $this->debug('collecting: windGust');
                $windGust = $this->getData($ecowitt, 'list.wind_speed.list.windgustmph');

                // winddir in degree
                $this->debug('collecting: winddir in degree');
                $windir = $this->getData($ecowitt, 'list.winddir.list.winddir');

                // pressure relative in hPa
                $this->debug('collecting: pressure relative in hPa');
                $pressureRel = $this->getData($ecowitt, 'list.pressure.list.baromrelin');

                // pressure absolute in hPa
                $this->debug('collecting: pressure absolute in hPa');
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
                $startDate = $startDate->addDay()->startOfDay();
            } while ( $startDate->lte($endDate) );

            $this->export(getcwd() . "/ecowitt_{$deviceId}.csv", $outputData);

        });
    }

    protected function getData($stack, $key)
    {
        return collect(data_get($stack, $key))
            ->mapWithKeys(function ($value, $idx) {
                $dateTime = data_get($this->times, $idx);
                return [$dateTime => $value ?: null];
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
            'account' => $this->ecowitt_account,
            'password' => $this->ecowitt_passphrase,
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

    /**
     * Takes in a filename and an array associative data array and outputs a csv file
     * @param string $fileName
     * @param array $data
     */
    protected function export(string $fileName, array $data) {
        if(isset($data['0'])){
            $fp = fopen($fileName, 'w+');
            fputs($fp, implode(',',array_keys($data['0'])) . "\n");
            foreach($data AS $values){
                fputs($fp, implode(',', $values) . "\n");
            }
            fclose($fp);
        }
    }

    /**
     * @param string $msg
     * @param mixed ...$args
     */
    protected function debug(string $msg, ...$args)
    {

        if ($this->option('debug')) {
            $this->info($msg);
            if (!empty($args)) {
                dump($args);
            }

        }

    }
}
