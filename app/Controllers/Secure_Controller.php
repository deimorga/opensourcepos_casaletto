<?php

namespace App\Controllers;

use App\Models\Employee;
use App\Models\Module;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Model;
use CodeIgniter\Session\Session;
use Config\OSPOS;
use Config\Services;

/**
 * Controllers that are considered secure extend Secure_Controller, optionally a $module_id can
 * be set to also check if a user can access a particular module in the system.
 *
 * @property employee employee
 * @property module module
 * @property array global_view_data
 * @property session session
 *
 */
class Secure_Controller extends BaseController
{
    public array $global_view_data;
    protected Employee $employee;
    protected Module $module;
    protected Session $session;

    /**
     * @param string $module_id
     * @param string|null $submodule_id
     * @param string|null $menu_group
     */
    public function __construct(string $module_id = '', ?string $submodule_id = null, ?string $menu_group = null)
    {
        $this->employee = model(Employee::class);
        $this->module = model(Module::class);
        $config = config(OSPOS::class)->settings;
        $validation = Services::validation();

        if (!$this->employee->is_logged_in()) {
            throw new RedirectException('login');
        }

        $logged_in_employee_info = $this->employee->get_logged_in_employee_info();
        if (
            !$this->employee->has_module_grant($module_id, $logged_in_employee_info->person_id)
            || (isset($submodule_id) && !$this->employee->has_module_grant($submodule_id, $logged_in_employee_info->person_id))
        ) {
            throw new RedirectException("no_access/$module_id/$submodule_id");
        }

        // Load up global global_view_data visible to all the loaded views
        $this->session = session();
        if ($menu_group == null) {
            $menu_group = $this->session->get('menu_group');
        } else {
            $this->session->set('menu_group', $menu_group);
        }

        $allowed_modules = $menu_group == 'home'
            ? $this->module->get_allowed_home_modules($logged_in_employee_info->person_id)
            : $this->module->get_allowed_office_modules($logged_in_employee_info->person_id);

        // La clave se crea SIEMPRE, aunque no haya ni un módulo.
        //
        // Antes se inicializaba a `[]` y la clave nacía dentro del bucle, así que un empleado sin
        // NINGÚN módulo permitido en este grupo dejaba `allowed_modules` sin existir -- y
        // `partial/header.php` y `home/office.php` hacen `foreach` sobre ella sin preguntar. El
        // resultado no era una página vacía: era un 500.
        //
        // Pasó en producción el 2026-09-01. Angela Rodríguez, cajera de Paraíso de la Canasta,
        // tiene 19 módulos de inicio y CERO de oficina; el icono de Oficina se le muestra igual, y
        // al tocarlo veía «Whoops!». El defecto llevaba ahí desde siempre y nadie lo había pisado
        // porque hasta entonces todos los empleados de los dos negocios tenían algo en las dos
        // pantallas.
        //
        // Una lista vacía es la respuesta correcta: la pantalla se dibuja sin iconos, que es la
        // verdad -- ese empleado no tiene nada ahí.
        $this->global_view_data = ['allowed_modules' => []];

        foreach ($allowed_modules->getResult() as $module) {
            $this->global_view_data['allowed_modules'][] = $module;
        }

        // UNA PUERTA QUE NO LLEVA A NINGUNA PARTE NO SE OFRECE
        //
        // El grupo de menú vive en la CONCESIÓN, no en el módulo: el mismo módulo aparece en Inicio
        // o en Oficina según cómo se le concedió a cada empleado. Así que un empleado puede tener el
        // icono de Oficina en su pantalla de inicio --porque esa concesión dice 'home'-- y no tener
        // ni un solo módulo concedido con 'office' detrás.
        //
        // Le pasó a Angela Rodríguez, cajera de Paraíso de la Canasta: 19 módulos en inicio, cero en
        // oficina, y todas sus concesiones --incluida la del propio módulo `office`-- en el grupo
        // 'home'. Al tocar Oficina veía una pantalla vacía, que es mejor que el 500 que veía antes
        // pero sigue siendo una puerta que no lleva a ninguna parte.
        //
        // La consulta extra solo se hace cuando el icono está en la lista, que es el único caso en
        // que la respuesta puede cambiar algo.
        if ($menu_group == 'home' && $this->hayModulo('office')) {
            $oficina = $this->module->get_allowed_office_modules($logged_in_employee_info->person_id);

            if ($oficina->getNumRows() === 0) {
                $this->global_view_data['allowed_modules'] = array_values(array_filter(
                    $this->global_view_data['allowed_modules'],
                    static fn ($module): bool => $module->module_id !== 'office'
                ));
            }
        }

        $this->global_view_data += [
            'user_info'       => $logged_in_employee_info,
            'controller_name' => $module_id,
            'config'          => $config
        ];
        view('viewData', $this->global_view_data);
    }

    /**
     * Si un modulo esta en la lista que se le va a mostrar a este empleado.
     */
    private function hayModulo(string $module_id): bool
    {
        foreach ($this->global_view_data['allowed_modules'] as $module) {
            if ($module->module_id === $module_id) {
                return true;
            }
        }

        return false;
    }

    public function sanitizeSortColumn($headers, $field, $default): string
    {
        return $field != null && in_array($field, array_keys(array_merge(...$headers))) ? $field : $default;
    }

    /**
     * AJAX function used to confirm whether values sent in the request are numeric
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getCheckNumeric(): ResponseInterface
    {
        foreach ($this->request->getGet() as $value) {
            if (parse_decimals($value) === false) {
                return $this->response->setJSON('false');
            }
        }
        return $this->response->setJSON('true');
    }

    /**
     * @param $key
     * @return mixed|void
     */
    public function getConfig($key)
    {
        if (isset($config[$key])) {
            return $config[$key];
        }
    }

    /**
     * @return false
     */
    public function getIndex()
    {
        return false;
    }

    /**
     * @return false
     */
    public function getSearch()
    {
        return false;
    }

    /**
     * @return false
     */
    public function suggest_search()
    {
        return false;
    }

    /**
     * @param int $data_item_id
     * @return false
     */
    public function getView(int $data_item_id = -1)
    {
        return false;
    }

    /**
     * @param int $data_item_id
     * @return false
     */
    public function postSave(int $data_item_id = -1)
    {
        return false;
    }

    /**
     * @return false
     */
    public function postDelete()
    {
        return false;
    }
}
