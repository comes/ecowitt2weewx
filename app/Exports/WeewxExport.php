<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WeewxExport implements FromArray, WithCustomCsvSettings, WithHeadings
{

    protected $weatherData;

    public function __construct($data)
    {
        $this->weatherData = $data;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function array(): array
    {
        return $this->weatherData;
    }

    public function headings() : array {
        return array_keys($this->weatherData[0]);
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '',
        ];
    }


}
