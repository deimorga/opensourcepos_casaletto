<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use stdClass;

/**
 * Base class for People classes
 */
class Person extends Model
{
    protected $table = 'people';
    protected $primaryKey = 'person_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'first_name',
        'last_name',
        'phone_number',
        'email',
        'address_1',
        'address_2',
        'city',
        'state',
        'zip',
        'country',
        'comments',
        'gender',
        'document_type',
        'document_number'
    ];

    /**
     * The roles a person can hold. Each role has its own table and creates its own `people` row, so
     * the same human being who is customer and employee exists twice: that is why a document is
     * unique within a role and may repeat across roles (docs/Tecnico/documento-de-identidad.md IT2).
     */
    public const DOCUMENT_ROLES = ['customers', 'employees', 'suppliers'];

    /**
     * people.document_number without dots, commas, spaces and hyphens, uppercased: the same cleaning
     * as Identity_document::normalize() and search_key(), done in SQL so that old numbers, copied as
     * typed from «Id Impuesto», compare like new ones.
     *
     * Raw SQL, so the table carries its prefix here: the query builder adds it only to what it
     * escapes itself, and a bare `people.document_number` is an unknown column on `ospos_people`.
     */
    public function document_number_key_sql(): string
    {
        $column = $this->db->escapeIdentifiers($this->db->prefixTable('people')) . '.' . $this->db->escapeIdentifiers('document_number');

        return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER($column), '.', ''), ',', ''), ' ', ''), '-', '')";
    }

    /**
     * The person of a role, not deleted, who already holds this document, or null.
     *
     * $number must come cleaned by Identity_document::normalize(). A person saved before document
     * types existed has a number and no type (the old «Id Impuesto», copied by the migration); that
     * number is compared without its dots, commas, spaces and hyphens, so an old customer with
     * "1.020.345.678" still counts as the owner of CC 1020345678.
     */
    public function document_owner(string $type, string $number, string $role, int $except_person_id = NEW_ENTRY): ?object
    {
        if (!in_array($role, self::DOCUMENT_ROLES, true) || $type === '' || $number === '') {
            return null;
        }

        $builder = $this->db->table($role);
        $builder->select('people.person_id, people.first_name, people.last_name, people.document_type, people.document_number');
        $builder->join('people', "people.person_id = $role.person_id");
        $builder->where("$role.deleted", 0);
        $builder->where("$role.person_id !=", $except_person_id);
        $builder->groupStart();
        $builder->groupStart();
        $builder->where('people.document_type', $type);
        $builder->where('people.document_number', $number);
        $builder->groupEnd();
        $builder->orGroupStart();
        $builder->where('people.document_type', null);
        $builder->where($this->document_number_key_sql() . ' = ' . $this->db->escape($number), null, false);
        $builder->groupEnd();
        $builder->groupEnd();
        $builder->orderBy('people.person_id', 'asc');
        $builder->limit(1);

        return $builder->get()->getRow();
    }

    /**
     * Who holds the same document in the OTHER roles. Allowed -- it is the same person in another
     * role -- and reported as a warning, never a refusal (I3).
     *
     * @return array<int, array{role: string, person: object}>
     */
    public function document_other_roles(string $type, string $number, string $role, int $except_person_id = NEW_ENTRY): array
    {
        $others = [];

        foreach (self::DOCUMENT_ROLES as $other_role) {
            if ($other_role === $role) {
                continue;
            }

            $owner = $this->document_owner($type, $number, $other_role, $except_person_id);

            if ($owner !== null) {
                $others[] = ['role' => $other_role, 'person' => $owner];
            }
        }

        return $others;
    }

    /**
     * Determines whether the given person exists in the people database table
     *
     * @param integer $person_id identifier of the person to verify the existence
     *
     * @return boolean true if the person exists, false if not
     */
    public function exists(int $person_id): bool
    {
        $builder = $this->db->table('people');
        $builder->where('people.person_id', $person_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets all people from the database table
     *
     * @param integer $limit limits the query return rows
     * @param integer $offset offset the query
     */
    public function get_all(int $limit = 10000, int $offset = 0): ResultInterface
    {
        $builder = $this->db->table('people');
        $builder->orderBy('last_name', 'asc');
        $builder->limit($limit);
        $builder->offset($offset);

        return $builder->get();
    }

    /**
     * Gets total of rows of people database table
     *
     * @return integer row counter
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('people');
        $builder->where('deleted', 0);

        return $builder->countAllResults();
    }

    /**
     * Gets information about a person as an array
     *
     * @param integer $person_id identifier of the person
     *
     * @return object containing all the fields of the table row
     */
    public function get_info(int $person_id): object
    {
        $builder = $this->db->table('people');
        $query = $builder->getWhere(['person_id' => $person_id], 1);

        if ($query->getNumRows() == 1) {
            return $query->getRow();
        } else {
            return $this->getEmptyObject('people');
        }
    }


    /**
     * Initializes an empty object based on database definitions
     * @param string $table_name
     * @return object
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = new stdClass();

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'])) {
                $empty_obj->$field_name = ($field->primary_key == 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->$field_name = null;
            }
        }

        return $empty_obj;
    }

    /**
     * Gets information about people as an array of rows
     *
     * @param array $person_ids array of people identifiers
     *
     */
    public function get_multiple_info(array $person_ids): ResultInterface
    {
        $builder = $this->db->table('people');
        $builder->whereIn('person_id', $person_ids);
        $builder->orderBy('last_name', 'asc');

        return $builder->get();
    }

    /**
     * Inserts or updates a person
     *
     * @param array $person_data array containing person information
     * @param int $person_id identifier of the person to update the information
     * @return boolean true if the save was successful, false if not
     */
    public function save_value(array &$person_data, int $person_id = NEW_ENTRY): bool
    {
        $builder = $this->db->table('people');

        if ($person_id == NEW_ENTRY || !$this->exists($person_id)) {
            if ($builder->insert($person_data)) {
                $person_data['person_id'] = $this->db->insertID();

                return true;
            }

            return false;
        }

        $builder->where('person_id', $person_id);

        return $builder->update($person_data);
    }

    /**
     * Get search suggestions to find person
     *
     * @param string $search string containing the term to search in the people table
     * @param int $limit limit the search
     * @return array array with the suggestion strings
     */
    public function get_search_suggestions(string $search, int $limit = 25): array
    {
        $suggestions = [];

        $builder = $this->db->table('people');

        // TODO: If this won't be added back into the code later, we should delete this commented section of code
        // $builder->select('person_id');
        // $builder->where('deleted', 0);
        // $builder->where('person_id', $search);
        // $builder->groupStart();
        // $builder->like('first_name', $search);
        // $builder->orLike('last_name', $search);
        // $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        // $builder->orLike('email', $search);
        // $builder->orLike('phone_number', $search);
        // $builder->groupEnd();
        // $builder->orderBy('last_name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['label' => $row->person_id];
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return $suggestions;
    }

    /**
     * Deletes one Person (dummy base function)
     *
     * @param integer $person_id person identifier
     * @return boolean always true
     */
    public function delete($person_id = null, bool $purge = false): bool
    {
        return true;
    }

    /**
     * Deletes a list of people (dummy base function)
     *
     * @param array $person_ids list of person identifiers
     * @return boolean always true
     */
    public function delete_list(array $person_ids): bool
    {
        return true;
    }
}
