<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Session\Session;
use stdClass;

/**
 * Employee class
 *
 * @property session session
 *
 */
class Employee extends Person
{
    public Session $session;
    protected $table = 'Employees';
    protected $primaryKey = 'person_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'username',
        'password',
        'deleted',
        'hashversion',
        'language',
        'language_code'
    ];

    public function __construct()
    {
        parent::__construct();
        $this->session = session();
    }

    /**
     * Determines if a given person_id is an employee
     */
    public function exists(int $person_id): bool
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->where('employees.person_id', $person_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * @param int $employee_id
     * @param string $username
     * @return bool
     */
    public function username_exists(int $employee_id, string $username): bool
    {
        $builder = $this->db->table('employees');
        $builder->where('employees.username', $username);
        $builder->where('employees.person_id <>', $employee_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Esconde al empleado de soporte de la plataforma de todo listado del cliente.
     *
     * POR QUÉ NO SE USA `deleted`
     *
     * `employees` tiene un solo mecanismo para esconder, y está usado en todas partes --incluido el
     * login--. Marcar como borrado algo que no lo está funciona hoy y miente mañana: la primera
     * consulta que cuente empleados vivos para otra cosa dará un número distinto al de la pantalla y
     * nadie sabrá por qué. Por eso la columna es propia (migración 20260908000000).
     *
     * DÓNDE SE APLICA Y DÓNDE NO
     *
     * Se aplica a lo que el cliente LEE: el conteo de la grilla, el listado, las sugerencias y la
     * búsqueda. **No** se aplica a `login()` ni a `check_password()`, y eso es deliberado: son la
     * puerta por la que entran los empleados del negocio todos los días, y no se tocan. Tampoco hace
     * falta -- la contraseña de esa fila no es un hash y `password_verify()` contra ella devuelve
     * false siempre (ver App\Libraries\Platform_support).
     *
     * Esconder de menos es cosmético. **Esconder de más ocultaría a un empleado real**, así que la
     * condición es de igualdad contra 0 y nunca una negación.
     */
    private function hide_platform_support(BaseBuilder $builder): void
    {
        $builder->where('is_platform_support', 0);
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);
        $this->hide_platform_support($builder);

        return $builder->countAllResults();
    }

    /**
     * Todas las filas de empleado, **incluida la de soporte**, para repartir permisos.
     *
     * POR QUÉ EXISTE ESTO Y NO SE USA `get_all()`
     *
     * `get_all()` responde «los empleados que el negocio VE», y por eso esconde al de soporte. Pero
     * `Stock_location::_insert_new_permission()` no está pintando una lista: está dando de alta un
     * permiso nuevo a todo el que deba tenerlo, y una fila escondida sigue necesitándolo.
     *
     * Sin esta separación, cada bodega que creara un cliente dejaba al empleado de soporte sin los
     * permisos de esa ubicación, en silencio y solo para las ubicaciones creadas después de que
     * entráramos. El síntoma llegaría meses más tarde, como «soporte no ve una de mis bodegas».
     *
     * La regla, para quien añada una llamada nueva: si el resultado se le enseña a alguien, es
     * `get_all()`. Si de él dependen permisos o integridad, es este.
     */
    public function get_all_for_permissions(): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->orderBy('last_name', 'asc');

        return $builder->get();
    }

    /**
     * Returns all the employees
     */
    public function get_all(int $limit = 10000, int $offset = 0): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);
        $this->hide_platform_support($builder);
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->orderBy('last_name', 'asc');
        $builder->limit($limit);
        $builder->offset($offset);

        return $builder->get();
    }

    /**
     * Gets information about a particular employee
     */
    public function get_info(int $person_id): object
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->where('employees.person_id', $person_id);
        $query = $builder->get();

        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        }

        // Get empty base parent object, as $employee_id is NOT an employee
        $person_obj = parent::get_info(NEW_ITEM);

        // Get all the fields from employee table
        // Append those fields to base parent object, we have a complete empty object
        foreach ($this->db->getFieldNames('employees') as $field) {
            $person_obj->$field = null;
        }

        return $person_obj;
    }

    /**
     * Gets information about multiple employees
     */
    public function get_multiple_info(array $person_ids): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->whereIn('employees.person_id', $person_ids);
        $builder->orderBy('last_name', 'asc');

        return $builder->get();
    }

    /**
     * Inserts or updates an employee
     */
    public function save_employee(array &$person_data, array &$employee_data, array &$grants_data, int $employee_id = NEW_ENTRY): bool
    {
        $success = false;

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        if (parent::save_value($person_data, $employee_id)) {
            $builder = $this->db->table('employees');
            if ($employee_id == NEW_ENTRY || !$this->exists($employee_id)) {
                $employee_data['person_id'] = $employee_id = $person_data['person_id'];
                $success = $builder->insert($employee_data);
            } else {
                $builder->where('person_id', $employee_id);
                $success = $builder->update($employee_data);
            }

            // We have either inserted or updated a new employee, now lets set permissions.
            if ($success) {
                // First lets clear out any grants the employee currently has.
                $builder = $this->db->table('grants');
                $success = $builder->delete(['person_id' => $employee_id]);

                // Now insert the new grants
                if ($success) {
                    foreach ($grants_data as $grant) {
                        $data = [
                            'permission_id' => $grant['permission_id'],
                            'person_id'     => $employee_id,
                            'menu_group'    => $grant['menu_group']
                        ];

                        $builder = $this->db->table('grants');
                        $success = $builder->insert($data);
                    }
                }
            }
        }

        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Deletes one employee
     */
    public function delete($employee_id = null, bool $purge = false): bool
    {
        $success = false;

        // Don't let employees delete themselves
        if ($employee_id == $this->get_logged_in_employee_info()->person_id) {
            return false;
        }

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        // Delete permissions
        $builder = $this->db->table('grants');

        if ($builder->delete(['person_id' => $employee_id])) {
            $builder = $this->db->table('employees');
            $builder->where('person_id', $employee_id);
            $success = $builder->update(['deleted' => 1]);
        }

        $this->db->transComplete();

        return $success;
    }

    /**
     * Deletes a list of employees
     */
    public function delete_list(array $person_ids): bool
    {
        $success = false;

        // Don't let employees delete themselves
        if (in_array($this->get_logged_in_employee_info()->person_id, $person_ids)) {
            return false;
        }

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        $builder = $this->db->table('grants');
        $builder->whereIn('person_id', $person_ids);
        // Delete permissions
        if ($builder->delete()) {
            // Delete from employee table
            $builder = $this->db->table('employees');
            $builder->whereIn('person_id', $person_ids);
            $success = $builder->update(['deleted' => 1]);
        }

        $this->db->transComplete();
        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Get search suggestions to find employees
     */
    public function get_search_suggestions(string $search, int $limit = 25, bool $unique = false): array
    {
        $suggestions = [];

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        $builder->groupEnd();

        if (!$unique) {
            $builder->where('deleted', 0);
            $this->hide_platform_support($builder);
        }

        $builder->orderBy('last_name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->first_name . ' ' . $row->last_name];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (!$unique) {
            $builder->where('deleted', 0);
            $this->hide_platform_support($builder);
        }

        $builder->like('email', $search);
        $builder->orderBy('email', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->email];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (!$unique) {
            $builder->where('deleted', 0);
            $this->hide_platform_support($builder);
        }

        $builder->like('username', $search);
        $builder->orderBy('username', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->username];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (!$unique) {
            $builder->where('deleted', 0);
            $this->hide_platform_support($builder);
        }

        $builder->like('phone_number', $search);
        $builder->orderBy('phone_number', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->phone_number];
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return $suggestions;
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search): int
    {
        return $this->search($search, 0, 0, 'last_name', 'asc', true);
    }

    /**
     * Performs a search on employees
     */
    public function search(string $search, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'last_name', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'last_name';
        if ($order == null) $order = 'asc';
        if ($count_only == null) $count_only = false;

        $builder = $this->db->table('employees AS employees');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(employees.person_id) as count');
        }

        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('email', $search);
        $builder->orLike('phone_number', $search);
        $builder->orLike('username', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        $builder->groupEnd();
        $builder->where('deleted', 0);
        $this->hide_platform_support($builder);

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Attempts to log in employee and set session. Returns boolean based on outcome.
     */
    public function login(string $username, string $password): bool
    {
        $builder = $this->db->table('employees');
        $query = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() === 1) {
            $row = $query->getRow();

            // Compare passwords depending on the hash version
            if ($row->hash_version === '1' && $row->password === md5($password)) {
                $builder->where('person_id', $row->person_id);
                $this->session->set('person_id', $row->person_id);
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                return $builder->update(['hash_version' => 2, 'password' => $password_hash]);
            } elseif ($row->hash_version === '2' && password_verify($password, $row->password)) {
                $this->session->set('person_id', $row->person_id);

                return true;
            }
        }

        return false;
    }

    /**
     * Logs out a user by destroying all session data and redirect to log in
     */
    public function logout(): void
    {
        session()->destroy();
    }

    /**
     * Determines if an employee is logged in
     */
    public function is_logged_in(): bool
    {
        return ($this->session->get('person_id') != false);
    }

    /**
     * Gets information about the currently logged in employee.
     */
    public function get_logged_in_employee_info(): float|false|array|int|string|stdClass|null
    {
        if ($this->is_logged_in()) {
            return $this->get_info($this->session->get('person_id'));
        }

        return false;
    }

    /**
     * Determines whether the employee has access to at least one submodule
     */
    public function has_module_grant(string $permission_id, int $person_id): bool
    {
        $builder = $this->db->table('grants');
        $builder->like('permission_id', $permission_id, 'after');
        $builder->where('person_id', $person_id);
        $result = $builder->get()->getResultArray();

        if (count($result) != 1) {
            return (count($result) != 0);
        }

        // The sole matching grant is the base module permission itself, so access is
        // granted regardless of whether the module also defines separate sub-permissions
        // (e.g. cashups_delete) that this employee wasn't additionally given.
        if ($result[0]['permission_id'] === $permission_id) {
            return true;
        }

        return $this->has_subpermissions($permission_id);
    }

    /**
     * Checks permissions
     */
    public function has_subpermissions(string $permission_id): bool
    {
        $builder = $this->db->table('permissions');
        $builder->like('permission_id', $permission_id . '_', 'after');

        return ($builder->get()->getNumRows() == 0);    // TODO: ===
    }

    /**
     * Determines whether the employee specified employee has access the specific module.
     */
    public function has_grant(?string $permission_id, ?int $person_id): bool
    {
        // If no module_id is null, allow access
        if ($permission_id == null) {
            return true;
        }
        if ($person_id == null) {
            return false;
        }

        $builder = $this->db->table('grants');
        $query = $builder->getWhere(['person_id' => $person_id, 'permission_id' => $permission_id], 1);

        return ($query->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Returns the menu group designation that this module is to appear in
     */
    public function get_menu_group(string $permission_id, ?int $person_id): string
    {
        $builder = $this->db->table('grants');
        $builder->select('menu_group');
        $builder->where('permission_id', $permission_id);
        $builder->where('person_id', $person_id);

        $row = $builder->get()->getRow();

        // If no grants are assigned yet then set the default to 'home'
        if ($row == null) {
            return 'home';
        } else {
            return $row->menu_group;
        }
    }

    /**
     * Gets employee permission grants
     */
    public function get_employee_grants(int $person_id): array
    {
        $builder = $this->db->table('grants');
        $builder->where('person_id', $person_id);

        return $builder->get()->getResultArray();
    }

    /**
     * Attempts to log in employee and set session. Returns boolean based on outcome.
     */
    public function check_password(string $username, string $password): bool
    {
        $builder = $this->db->table('employees');
        $query = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() == 1) {    // TODO: ===
            $row = $query->getRow();

            if (password_verify($password, $row->password)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change password for the employee
     */
    public function change_password(array $employee_data, $employee_id = false): bool
    {
        $success = false;

        if (!getenv('DISALLOW_PASSWORD_CHANGE')) {
            $this->db->transStart();

            $builder = $this->db->table('employees');
            $builder->where('person_id', $employee_id);
            $success = $builder->update($employee_data);

            $this->db->transComplete();

            $success &= $this->db->transStatus();
        }

        return $success;
    }

    /**
     * Checks if the employee has admin privileges (all module permissions).
     * The first employee (person_id = 1) is considered admin by default.
     */
    public function isAdmin(int $person_id): bool
    {
        if ($person_id === 1) {
            return true;
        }

        foreach (ADMIN_MODULES as $module) {
            if (!$this->has_grant($module, $person_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if current user can modify target employee.
     * Only admins can modify other admin accounts.
     * Users cannot modify their own grants unless they are admin.
     */
    public function canModifyEmployee(int $target_person_id, int $current_person_id): bool
    {
        if ($target_person_id === $current_person_id) {
            return !$this->isAdmin($target_person_id) || $this->isAdmin($current_person_id);
        }

        if ($this->isAdmin($target_person_id) && !$this->isAdmin($current_person_id)) {
            return false;
        }

        return true;
    }
}
