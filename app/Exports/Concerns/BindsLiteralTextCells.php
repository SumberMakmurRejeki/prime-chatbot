<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Binder nilai sel untuk export XLSX.
 *
 * PhpSpreadsheet mengetik string yang diawali "=" sebagai sel formula, sehingga
 * teks pengguna (catatan, problem, root cause) bisa dieksekusi Excel. Semua string
 * formula-like dipaksa menjadi sel teks apa adanya; string lain dan nilai numerik
 * tetap memakai perilaku default agar tipe kolom tidak berubah.
 */
trait BindsLiteralTextCells
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && strlen($value) > 1 && $value[0] === '=') {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
