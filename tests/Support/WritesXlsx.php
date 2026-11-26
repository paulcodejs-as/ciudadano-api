<?php

namespace Tests\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Arma un Excel .xlsx de prueba (los números quedan como celdas numéricas, como en Excel). */
trait WritesXlsx
{
    /** @param list<list<string|int|float|null>> $rows */
    protected static function xlsxFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }
}
