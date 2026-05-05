<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ArrayReportExport implements FromArray, ShouldAutoSize, WithHeadings
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public function __construct(
        protected array $headings,
        protected array $rows,
    ) {}

    /**
     * @return array<int, array<int, string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->headings;
    }
}
