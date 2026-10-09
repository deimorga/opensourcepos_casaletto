<?php

namespace App\Controllers;

use App\Libraries\Identity_document;
use App\Models\Person;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use function Tamtamchik\NameCase\str_name_case;

abstract class Persons extends Secure_Controller
{
    protected Person $person;

    /**
     * The role this controller manages: customers, employees or suppliers (Person::DOCUMENT_ROLES).
     */
    protected string $person_role;

    /**
     * @param string|null $module_id
     */
    public function __construct(?string $module_id = null)
    {
        parent::__construct($module_id);

        $this->person = model(Person::class);
        $this->person_role = (string)$module_id;
    }

    /**
     * Checks the identity document typed in the form while the person is still being edited. Used by
     * app/Views/people/form_basic_info.php through the form's remote validation.
     *
     * Same rules as the save, from the same method, so the screen and the server cannot disagree. The
     * lookup of the other roles happens in the model, so it needs no permission on those modules.
     *
     * @return ResponseInterface {valid, message, warning}
     * @noinspection PhpUnused
     */
    public function postCheckDocument(): ResponseInterface
    {
        $person_id = (int)$this->request->getPost('person_id', FILTER_SANITIZE_NUMBER_INT);
        $document = $this->read_identity_document($person_id > 0 ? $person_id : NEW_ENTRY, false);

        return $this->response->setJSON([
            'valid'   => $document['error'] === null,
            'message' => $document['error'] ?? '',
            'warning' => $document['warning']
        ]);
    }

    /**
     * Reads, checks and cleans the identity document posted by the form
     * (docs/Tecnico/documento-de-identidad.md IT2, IT3, IT4, IT7).
     *
     * The number is read raw, never with FILTER_SANITIZE_*: Identity_document cleans it. Messages are
     * HTML-safe because the screen shows them with $.notify(), which renders HTML: the names in them
     * are escaped here.
     *
     * @param int $person_id the person being saved, NEW_ENTRY for a new one
     * @param bool $required whether this role must have a document
     * @return array{data: array{document_type: string|null, document_number: string|null}, error: string|null, warning: string}
     */
    protected function read_identity_document(int $person_id, bool $required): array
    {
        $type = trim((string)$this->request->getPost('document_type'));
        $raw_number = trim((string)$this->request->getPost('document_number'));
        $result = ['data' => ['document_type' => null, 'document_number' => null], 'error' => null, 'warning' => ''];

        if ($type === '' && $raw_number === '') {
            if ($required) {
                $result['error'] = lang('Common.document_type_required');
            }

            return $result;
        }

        if ($type === '') {
            $result['error'] = lang('Common.document_type_required');

            return $result;
        }

        $error = Identity_document::validate($type, $raw_number);

        if ($error !== null) {
            $result['error'] = lang($error);

            return $result;
        }

        $number = Identity_document::normalize($type, $raw_number);
        $owner = $this->person->document_owner($type, $number, $this->person_role, $person_id);

        if ($owner !== null) {
            $result['error'] = lang('Common.document_duplicate_' . $this->person_role, [esc(trim($owner->first_name . ' ' . $owner->last_name))]);

            return $result;
        }

        $warnings = [];

        foreach ($this->person->document_other_roles($type, $number, $this->person_role, $person_id) as $other) {
            $warnings[] = lang('Common.document_also_' . $other['role'], [esc(trim($other['person']->first_name . ' ' . $other['person']->last_name))]);
        }

        $result['data'] = ['document_type' => $type, 'document_number' => $number];
        $result['warning'] = implode(' ', $warnings);

        return $result;
    }

    /**
     * @return string
     */
    public function getIndex(): string
    {
        $data['table_headers'] = get_people_manage_table_headers();

        return view('people/manage', $data);
    }

    /**
     * Gives search suggestions based on what is being searched for
     * @return ResponseInterface
     */
    public function getSuggest(): ResponseInterface
    {
        $search = $this->request->getGet('term');
        $suggestions = $this->person->get_search_suggestions($search);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Gets one row for a person manage table. This is called using AJAX to update one row.
     * @return ResponseInterface
     */
    public function getRow(int $row_id): ResponseInterface
    {
        $data_row = get_person_data_row($this->person->get_info($row_id));

        return $this->response->setJSON($data_row);
    }

    /**
     * Capitalize segments of a name, and put the rest into lower case.
     * You can pass the characters you want to use as delimiters as exceptions.
     * The function supports UTF-8 strings
     *
     * Example:
     * i.e. <?php echo nameize("john o'grady-smith"); ?>
     *
     * returns John O'Grady-Smith
     */
    protected function nameize(string $input): string
    {
        $adjusted_name = str_name_case($input);

        // TODO: Use preg_replace to match HTML entities and convert them to lowercase. This is a workaround for https://github.com/tamtamchik/namecase/issues/20
        return preg_replace_callback('/&[a-zA-Z0-9#]+;/', function ($matches) {
            return strtolower($matches[0]);
        }, $adjusted_name);
    }
}
