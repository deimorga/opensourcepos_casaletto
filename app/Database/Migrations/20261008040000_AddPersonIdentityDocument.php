<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Type and number of a person's identity document (docs/Funcional/documento-de-identidad.md).
 *
 *   people.document_type    varchar(8) NULL   a code of App\Libraries\Identity_document::TYPES
 *   people.document_number  varchar(32) NULL  stored cleaned by Identity_document::normalize()
 *
 * On `people`, not on each role (IT1): it is a fact about the person, and the three forms share one
 * block of personal data. NOT a UNIQUE key (IT2): every role creates its own `people` row, so the
 * same person can be customer and employee with the same document. Uniqueness is per role, checked
 * by the controllers through Person::document_owner(). The index serves that lookup.
 *
 * WHAT IS ALREADY THERE (IT5). Customers and suppliers have a free-text «Id Impuesto»
 * (customers.tax_id, suppliers.tax_id). Its value is copied, trimmed and as typed, into
 * document_number when the person has none, with no type: nobody knows which type an old number
 * is, and the form asks for it the first time the customer is edited. The tax_id columns are NOT
 * dropped and not changed, so rolling back the image loses nothing; they simply stop being shown.
 *
 * Idempotent: the columns and the index are added only when missing, and the copy only fills empty
 * numbers, so a second run changes nothing. Every tenant schema runs it when the container starts.
 *
 * See docs/Tecnico/documento-de-identidad.md.
 */
class Migration_AddPersonIdentityDocument extends Migration
{
    public const INDEX  = 'people_document';
    private const TABLE = 'people';

    /**
     * The roles whose «Id Impuesto» becomes the document number.
     */
    private const TAX_ID_TABLES = ['customers', 'suppliers'];

    public function up(): void
    {
        $this->db->resetDataCache();

        $fields = [];

        if (! $this->db->fieldExists('document_type', self::TABLE)) {
            $fields['document_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'null'       => true,
                'default'    => null,
                'after'      => 'last_name',
            ];
        }

        if (! $this->db->fieldExists('document_number', self::TABLE)) {
            $fields['document_number'] = [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'default'    => null,
                'after'      => 'document_type',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn(self::TABLE, $fields);
            $this->db->resetDataCache();
            $this->report('AddPersonIdentityDocument: added ' . implode(', ', array_keys($fields)) . ' to ' . $this->db->prefixTable(self::TABLE) . '.');
        }

        if (! $this->index_exists()) {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE))
                . ' ADD INDEX ' . $this->db->escapeIdentifiers(self::INDEX) . ' (`document_type`, `document_number`)');
            $this->report('AddPersonIdentityDocument: added index ' . self::INDEX . '.');
        }

        foreach (self::TAX_ID_TABLES as $role) {
            if (! $this->db->tableExists($role) || ! $this->db->fieldExists('tax_id', $role)) {
                continue;
            }

            $people = $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE));
            $roles  = $this->db->escapeIdentifiers($this->db->prefixTable($role));

            $this->db->query(
                "UPDATE {$people} AS p JOIN {$roles} AS r ON r.person_id = p.person_id"
                . ' SET p.document_number = TRIM(r.tax_id)'
                . " WHERE (p.document_number IS NULL OR p.document_number = '')"
                . " AND r.tax_id IS NOT NULL AND TRIM(r.tax_id) <> ''",
            );

            $this->report('AddPersonIdentityDocument: ' . $this->db->affectedRows() . ' ' . $role . ' tax id(s) copied to document_number, without type.');
        }
    }

    /**
     * Drops the index and the two columns. tax_id was never touched, so nothing else needs undoing.
     */
    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->index_exists()) {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE))
                . ' DROP INDEX ' . $this->db->escapeIdentifiers(self::INDEX));
        }

        foreach (['document_number', 'document_type'] as $column) {
            if ($this->db->fieldExists($column, self::TABLE)) {
                $this->forge->dropColumn(self::TABLE, $column);
            }
        }
    }

    private function index_exists(): bool
    {
        return array_key_exists(self::INDEX, $this->db->getIndexData(self::TABLE));
    }

    /**
     * CLI::write and not log_message: the production log threshold throws away anything below
     * "critical", and this is progress, not an error. Guarded for the web installer.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
