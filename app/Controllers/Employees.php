<?php

namespace App\Controllers;

use App\Libraries\Wiring_lock;
use App\Models\Module;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 *
 *
 * @property module module
 *
 */
class Employees extends Persons
{
    public function __construct()
    {
        parent::__construct('employees');

        $this->module = model('Module');
    }

    /**
     * Returns employee table data rows. This will be called with AJAX.
     *
     * @return void
     */
    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit  = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort   = $this->sanitizeSortColumn(person_headers(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'people.person_id');
        $order  = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $employees = $this->employee->search($search, $limit, $offset, $sort, $order);
        $total_rows = $this->employee->get_found_rows($search);

        $data_rows = [];
        foreach ($employees->getResult() as $person) {
            $data_rows[] = get_person_data_row($person);
        }

        return $this->response->setJSON(['total' => $total_rows, 'rows' => $data_rows]);
    }

    /**
     * AJAX called function gives search suggestions based on what is being searched for.
     *
     * @return ResponseInterface
     */
    public function getSuggest(): ResponseInterface
    {
        $search = $this->request->getGet('term');
        $suggestions = $this->employee->get_search_suggestions($search, 25, true);

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     */
    public function suggest_search(): ResponseInterface
    {
        $search = $this->request->getPost('term');
        $suggestions = $this->employee->get_search_suggestions($search);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Loads the employee edit form
     * @return string
     */
    public function getView(int $employee_id = NEW_ENTRY): string
    {
        $person_info = $this->employee->get_info($employee_id);
        $current_user = $this->employee->get_logged_in_employee_info();

        if ($employee_id != NEW_ENTRY && !$this->employee->canModifyEmployee($person_info->person_id, $current_user->person_id)) {
            throw new RedirectException('no_access/employees/employees');
        }

        foreach (get_object_vars($person_info) as $property => $value) {
            $person_info->$property = $value;
        }
        $data['person_info'] = $person_info;
        $data['employee_id'] = $employee_id;

        $modules = [];
        foreach ($this->module->get_all_modules()->getResult() as $module) {
            $module->grant = $this->employee->has_grant($module->module_id, $person_info->person_id);
            $module->menu_group = $this->employee->get_menu_group($module->module_id, $person_info->person_id);

            $modules[] = $module;
        }
        $data['all_modules'] = $modules;

        $permissions = [];
        foreach ($this->module->get_all_subpermissions()->getResult() as $permission) {    // TODO: subpermissions does not follow naming standards.
            $permission->permission_id = str_replace(' ', '_', $permission->permission_id);
            $permission->grant = $this->employee->has_grant($permission->permission_id, $person_info->person_id);

            $permissions[] = $permission;
        }
        $data['all_subpermissions'] = $permissions;

        return view('employees/form', $data);
    }

    /**
     * Inserts/updates an employee
     * @return ResponseInterface
     */
    public function postSave(int $employee_id = NEW_ENTRY): ResponseInterface
    {
        $current_user = $this->employee->get_logged_in_employee_info();

        if ($employee_id != NEW_ENTRY) {
            $target_employee = $this->employee->get_info($employee_id);
            if (!$this->employee->canModifyEmployee($target_employee->person_id, $current_user->person_id)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Employees.error_updating_admin'),
                    'id'      => NEW_ENTRY
                ]);
            }
        }

        // Free-text fields are read without FILTER_SANITIZE_FULL_SPECIAL_CHARS on purpose. Despite what
        // the PHP manual says, that filter behaves like htmlentities(): it stores "José" as "Jos&eacute;",
        // which then never matches a search or a grid filter. Sanitizing input must not alter the data;
        // escaping belongs to the output. See docs/Tecnico/errores-produccion-upstream.md section 5.
        $first_name = $this->request->getPost('first_name');    // TODO: duplicated code
        $last_name = $this->request->getPost('last_name');
        $email = strtolower($this->request->getPost('email', FILTER_SANITIZE_EMAIL));

        // format first and last name properly
        $first_name = $this->nameize($first_name);
        $last_name = $this->nameize($last_name);

        $person_data = [
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'gender'       => $this->request->getPost('gender', FILTER_SANITIZE_NUMBER_INT),
            'email'        => $email,
            'phone_number' => $this->request->getPost('phone_number'),
            'address_1'    => $this->request->getPost('address_1'),
            'address_2'    => $this->request->getPost('address_2'),
            'city'         => $this->request->getPost('city'),
            'state'        => $this->request->getPost('state'),
            'zip'          => $this->request->getPost('zip'),
            'country'      => $this->request->getPost('country'),
            'comments'     => $this->request->getPost('comments')
        ];

        $grants_array = [];
        $isAdmin = $this->employee->isAdmin($current_user->person_id);

        foreach ($this->module->get_all_permissions()->getResult() as $permission) {
            $grants = [];
            $grant = $this->request->getPost('grant_' . $permission->permission_id) != null ? $this->request->getPost('grant_' . $permission->permission_id, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';

            if ($grant == $permission->permission_id) {
                if (!$isAdmin && !$this->employee->has_grant($permission->permission_id, $current_user->person_id)) {
                    continue;
                }
                $grants['permission_id'] = $permission->permission_id;
                $grants['menu_group'] = $this->request->getPost('menu_group_' . $permission->permission_id) != null ? $this->request->getPost('menu_group_' . $permission->permission_id, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '--';
                $grants_array[] = $grants;
            }
        }

        // Password has been changed OR first time password set
        if (!empty($this->request->getPost('password')) && ENVIRONMENT != 'testing') {
            $language = $this->wired_employee_language();
            $employee_data = [
                'username'      => $this->request->getPost('username'),
                'password'      => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
                'hash_version'  => 2,
                'language_code' => $language['language_code'],
                'language'      => $language['language']
            ];
        } else { // Password not changed
            $language = $this->wired_employee_language();
            $employee_data = [
                'username'      => $this->request->getPost('username'),
                'language_code' => $language['language_code'],
                'language'      => $language['language']
            ];
        }

        // The browser hands these messages to $.notify(), which renders them as HTML, so the name is
        // escaped here. Escaping at the output is what lets the name be stored with its accents intact.
        $display_name = esc($first_name) . ' ' . esc($last_name);

        if ($this->employee->save_employee($person_data, $employee_data, $grants_array, $employee_id)) {
            // New employee
            if ($employee_id == NEW_ENTRY) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Employees.successful_adding') . ' ' . $display_name,
                    'id'      => $employee_data['person_id']
                ]);
            } else { // Existing employee
                $logged_in_employee_id = session()->get('person_id');
                if ($employee_id == $logged_in_employee_id) {
                    session()->set('language_code', $employee_data['language_code']);
                    session()->set('language', $employee_data['language']);
                }
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Employees.successful_updating') . ' ' . $display_name,
                    'id'      => $employee_id
                ]);
            }
        } else { // Failure
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Employees.error_adding_updating') . ' ' . $display_name,
                'id'      => NEW_ENTRY
            ]);
        }
    }

    /**
     * This deletes employees from the employees table
     * @return ResponseInterface
     */
    public function postDelete(): ResponseInterface
    {
        $employees_to_delete = $this->request->getPost('ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $current_user = $this->employee->get_logged_in_employee_info();

        if (!$this->employee->isAdmin($current_user->person_id)) {
            foreach ($employees_to_delete as $emp_id) {
                if ($this->employee->isAdmin((int)$emp_id)) {
                    return $this->response->setJSON(['success' => false, 'message' => lang('Employees.error_deleting_admin')]);
                }
            }
        }

        if ($this->employee->delete_list($employees_to_delete)) {    // TODO: this is passing a string, but delete_list expects an array
            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Employees.successful_deleted') . ' ' . count($employees_to_delete) . ' ' . lang('Employees.one_or_multiple')
            ]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Employees.cannot_be_deleted')]);
        }
    }

    /**
     * Checks an employee username against the database. Used in app\Views\employees\form.php
     *
     * @param $employee_id
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getCheckUsername($employee_id): ResponseInterface
    {
        $exists = $this->employee->username_exists($employee_id, $this->request->getGet('username'));
        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }

    /**
     * El idioma con el que se guarda un empleado.
     *
     * `employees.language_code` GANA sobre `app_config` (`locale_helper.php:20`), así que el candado
     * de la pantalla de configuración no llega hasta acá: cualquiera con permiso sobre empleados podía
     * ponerse otra variante en su propio perfil y reproducir exactamente el incidente que D12 existe
     * para impedir. Una cadena escrita para es-ES es invisible en es-MX: la pantalla sale en inglés y
     * no da ningún error.
     *
     * Con el idioma cableado se fija la pareja completa y el desplegable del formulario se ignora.
     * Sin cablear -- una instalación que no sea de esta plataforma -- manda lo que eligió la persona.
     */
    private function wired_employee_language(): array
    {
        $exploded = explode(':', (string)$this->request->getPost('language', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $chosen   = ['language_code' => $exploded[0] ?? '', 'language' => $exploded[1] ?? ''];

        $wired = Wiring_lock::required_value('language_code');

        if ($wired === '') {
            return $chosen;
        }

        // La mitad del nombre sale de la misma lista que llena el desplegable, para no tener escrito
        // "spanish" en dos sitios que puedan separarse.
        foreach (array_keys(get_languages()) as $pair) {
            [$code, $name] = array_pad(explode(':', $pair, 2), 2, '');

            if ($code === $wired) {
                return ['language_code' => $code, 'language' => $name];
            }
        }

        return ['language_code' => $wired, 'language' => $chosen['language']];
    }
}
