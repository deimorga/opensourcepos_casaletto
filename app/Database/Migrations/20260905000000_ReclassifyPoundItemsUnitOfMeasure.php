<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Was: move the "pound-priced" items onto an 'lb' unit of measure. Now: does nothing, on purpose.
 *
 * The premise was wrong. This migration existed because the business said QUESO DE CABEZA is sold
 * by the pound, and it moved that item from 'kg' -- where 20260903000000 had correctly put it, from
 * the Siigo description "Unidad: kilogramo" -- onto a new 'lb' code.
 *
 * The catalogue's own two months of trading say otherwise, and say it plainly. The item is priced
 * at $26.000 and the sale lines read 0.192, 0.250, 0.500: a quarter of it sells for $6.500. That is
 * $26.000 per KILOGRAM. Priced per pound the same quarter would be $14.330, and the kilo $57.000 --
 * not what a deli charges for head cheese. Siigo, the accounting system the catalogue was imported
 * from, says "Unidad: kilogramo" too. The cashiers have been typing kilograms since day one.
 *
 * The other row it converted, CAFÉ MAKOR LIBRA, makes the same point from the other side: "libra"
 * there is the size of the bag, not something anybody weighs. It is a unit.
 *
 * So the standard unit is the kilogram, and a business that says "por libra" out loud still records
 * half a pound as 0.227 kg. 'lb' has been removed from App\Models\Item::ALLOWED_UNITS_OF_MEASURE
 * entirely -- see the note there for why a second weighed unit is a liability rather than a feature.
 *
 * WHY THIS FILE STILL EXISTS, EMPTIED, RATHER THAN DELETED
 *
 * Staging already ran it, so its version row is written and it will never run again there;
 * 20260907000000 undoes what it did. Production never ran it. Deleting the file would leave staging
 * with a version row pointing at nothing, and CodeIgniter walks the directory to decide what is
 * pending -- so the file stays and the body goes. Emptied, it is also correct for any tenant that
 * has not run it: doing nothing is exactly right.
 *
 * It cannot merely be left as it was, either: it referenced Item::UNIT_OF_MEASURE_LB, a constant
 * that no longer exists, so the next tenant to migrate would have died on a fatal error.
 */
class Migration_ReclassifyPoundItemsUnitOfMeasure extends Migration
{
    public function up(): void
    {
        CLI::write('ReclassifyPoundItemsUnitOfMeasure: intentionally does nothing; the pound was removed (see 20260907000000).');
    }

    public function down(): void
    {
    }
}
