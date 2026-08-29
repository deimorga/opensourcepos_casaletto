<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The import template's column order is a compatibility contract.
 *
 * Customers keep filled-in copies of this file. Inserting a column between existing ones shifts
 * every value they have already typed one place to the right, so the new column has to land after
 * the last of the fixed ones and the fixed ones have to keep the order they have always had.
 *
 * No database: the template is a string.
 */
class ImportFileHelperUnitOfMeasureTest extends CIUnitTestCase
{
    /**
     * The column order as it shipped before unit of measure existed. Every one of these has to keep
     * its position, in this sequence.
     */
    private const LEGACY_COLUMNS = [
        'Id',
        'Barcode',
        '"Item Name"',
        'Category',
        '"Supplier ID"',
        '"Cost Price"',
        '"Unit Price"',
        '"Tax 1 Name"',
        '"Tax 1 Percent"',
        '"Tax 2 Name"',
        '"Tax 2 Percent"',
        '"Reorder Level"',
        'Description',
        '"Allow Alt Description"',
        '"Item has Serial Number"',
        'Image',
        'HSN',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../app/Helpers/importfile_helper.php';
    }

    private function fixedHeaders(): array
    {
        // Strip the BOM, then keep only the fixed block: the stock location and attribute columns
        // are generated per installation and are not part of this contract.
        $csv = generate_import_items_csv([], []);
        $csv = substr($csv, 3);

        return explode(',', $csv);
    }

    public function testTheLegacyColumnsKeepTheirExactOrder(): void
    {
        $headers = $this->fixedHeaders();

        $this->assertSame(
            self::LEGACY_COLUMNS,
            array_slice($headers, 0, count(self::LEGACY_COLUMNS)),
            'A template column moved. Every customer file already filled in against the old order breaks.'
        );
    }

    public function testTheUnitColumnIsAppendedAfterTheLegacyOnes(): void
    {
        $headers = $this->fixedHeaders();

        $this->assertSame('"Unit of Measure"', end($headers));
        $this->assertCount(count(self::LEGACY_COLUMNS) + 1, $headers);
    }

    /**
     * The header spelling is what the importer looks the cell up by, so it is part of the contract
     * just as much as the position.
     */
    public function testTheHeaderMatchesTheKeyTheImporterReads(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Controllers/Items.php');

        $this->assertStringContainsString(
            "\$row['Unit of Measure']",
            $controller,
            'The importer reads a different column name than the template writes.'
        );
    }

    /**
     * A file written from a template that predates the column has no such key. The importer has to
     * cope with that rather than warn or fatal, which is what the null-coalescing read is for.
     */
    public function testTheImporterReadsTheColumnDefensively(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Controllers/Items.php');

        $this->assertStringContainsString(
            "\$row['Unit of Measure'] ?? ''",
            $controller,
            'An older template has no such column; reading it unguarded warns on every row.'
        );
    }

    /**
     * The columns still line up with their values when a row is parsed back, which is the property
     * that actually breaks when someone reorders the template.
     */
    public function testAFileWrittenFromThisTemplateParsesBackByName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ospos_csv_');

        $row = '7,"0001","Tomate","Verduras",,1000,2500,,,,,1,"Tomate rojo",,,,"",kg';
        file_put_contents($path, generate_import_items_csv([], []) . "\n" . $row . "\n");

        $rows = get_csv_file($path);
        unlink($path);

        $this->assertCount(1, $rows);
        $this->assertSame('kg', $rows[0]['Unit of Measure']);
        $this->assertSame('Tomate', $rows[0]['Item Name']);
        $this->assertSame('2500', $rows[0]['Unit Price']);
    }
}
