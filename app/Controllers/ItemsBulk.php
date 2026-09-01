<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Import_staging;
use App\Libraries\Item_export_lib;
use App\Libraries\Item_import_lib;
use App\Models\Attribute;
use App\Models\Stock_location;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use Config\OSPOS;
use Throwable;

/**
 * Descargar el catálogo, corregirlo en Excel, y volver a subirlo.
 *
 * POR QUÉ ESTO NO VIVE EN `Items.php`
 *
 * Porque `Items.php` no se toca. Esa decisión hace tres cosas a la vez:
 *
 * - **No hay que reescribir 37 pruebas que no protegen nada.** `ItemsCsvImportTest` no invoca el
 *   controlador ni una sola vez y varias de sus pruebas reimplementan la lógica y afirman sobre esa
 *   copia. Cambiar el flujo viejo obligaría a rehacerlas para saber si algo se rompió.
 * - **No se le quita nada a nadie.** Quien hoy cargue existencias por archivo sigue pudiendo: ese
 *   camino queda intacto. El camino nuevo las ignora a propósito, pero es otro camino.
 * - **Los dos carriles de desarrollo dejan de competir** por el archivo más grande del módulo.
 *
 * Retirar el flujo viejo es Entrega 2, y entonces sí con su prueba.
 *
 * EL PERMISO ES EL DE ARTÍCULOS, A PROPÓSITO
 *
 * `Secure_Controller('items')`. No se inventa un permiso propio: `Employee::has_module_grant()`
 * compara con `like(..., 'after')`, o sea por prefijo, así que un permiso llamado `items_algo` dejaría
 * entrar a TODO el módulo de artículos a quien solo debiera tener eso. Quien puede editar artículos
 * puede hacer esto; quien no, no.
 *
 * Ojo: la descarga baja el catálogo con costos y márgenes. Que exija el mismo permiso que editar es la
 * decisión, no un descuido.
 */
class ItemsBulk extends Secure_Controller
{
    private Item_export_lib $exporter;
    private Item_import_lib $importer;
    private Import_staging $staging;

    public function __construct()
    {
        parent::__construct('items');

        $this->exporter = new Item_export_lib();
        $this->importer = new Item_import_lib();
        $this->staging  = new Import_staging();
    }

    /**
     * «Descargar mis artículos»: el catálogo entero, en las columnas que la importación lee.
     */
    public function getExportCatalog(): DownloadResponse
    {
        return $this->response->download($this->exporter->fileName(), $this->exporter->toCsv());
    }

    /**
     * «Descargar plantilla vacía»: solo los encabezados, para empezar de cero.
     *
     * Es lo mismo que hace `Items::getGenerateCsvFile()`. Se repite aquí para que las dos descargas
     * vivan juntas en la pantalla nueva; ambas llaman al mismo generador, así que no se pueden separar.
     */
    public function getTemplate(): DownloadResponse
    {
        helper('importfile');

        return $this->response->download(
            'plantilla_articulos.csv',
            generate_import_items_csv(
                model(Stock_location::class)->get_allowed_locations(),
                model(Attribute::class)->get_definition_names(),
            ),
        );
    }

    /**
     * La pantalla. Página completa, no un modal.
     *
     * El modal de BootstrapDialog construye sus botones al abrirse y no los deja cambiar, así que no
     * puede pasar de «Continuar» a «Aplicar / Cancelar». Y una vista previa de mil filas con sus
     * errores no cabe ahí. Una página, además, sobrevive a un F5 y a que el cliente se vaya a Excel a
     * corregir; un modal no.
     */
    public function getIndex(): string
    {
        return view('items/bulk_import', [
            'config'  => config(OSPOS::class)->settings,
            'preview' => null,
            'error'   => null,
        ]);
    }

    /**
     * Paso 1: se sube el archivo y se enseña qué haría. **No escribe nada.**
     *
     * SE PINTA LA PÁGINA, NO SE REDIRIGE
     *
     * Un patrón POST-redirect-GET obligaría a llevar el plan en la sesión, y un plan de 1.184 filas no
     * cabe ahí --es la tercera opción que `docs/Tecnico/carga-masiva-de-articulos.md` §4.4 descarta.
     * Lo que sobrevive entre los dos pasos es el archivo, no el plan: por eso `postApply()` vuelve a
     * calcularlo sobre el MISMO archivo, y por eso lo que se aplica es lo que se enseñó.
     */
    public function postPreview(): string|RedirectResponse
    {
        // 1.184 filas con sus consultas en lote no tardan, pero el límite de 30 segundos por omisión
        // es de una petición corriente y esta no lo es.
        set_time_limit(240);

        $file      = $this->request->getFile('file_path');
        $tamanoMax = (int) round(Import_staging::MAX_BYTES / 1024 / 1024) . ' MB';

        if ($file === null || ! $file->isValid()) {
            // `UPLOAD_ERR_INI_SIZE` lo pone PHP cuando el archivo pasa de `upload_max_filesize`, y ahí
            // no llega ni un byte: sin este caso el cliente vería «falló la importación» sin motivo.
            $demasiadoGrande = $file !== null && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return view('items/bulk_import', [
                'config'  => config(OSPOS::class)->settings,
                'preview' => null,
                'error'   => $demasiadoGrande ? lang('Items.bulk_file_too_big', [$tamanoMax]) : lang('Items.csv_import_failed'),
            ]);
        }

        if ($file->getSize() > Import_staging::MAX_BYTES) {
            return view('items/bulk_import', [
                'config'  => config(OSPOS::class)->settings,
                'preview' => null,
                'error'   => lang('Items.bulk_file_too_big', [$tamanoMax]),
            ]);
        }

        try {
            $this->staging->store($file);
            $path = $this->staging->currentPath();
        } catch (Throwable $e) {
            log_message('error', 'Carga masiva: no se pudo guardar el archivo subido: ' . $e->getMessage());

            $path = null;
        }

        if ($path === null) {
            return view('items/bulk_import', [
                'config'  => config(OSPOS::class)->settings,
                'preview' => null,
                'error'   => lang('Items.csv_import_failed'),
            ]);
        }

        return view('items/bulk_import', [
            'config'  => config(OSPOS::class)->settings,
            'preview' => $this->importer->plan($path),
            'error'   => null,
        ]);
    }

    /**
     * Paso 2: se aplica el plan que se acaba de enseñar.
     *
     * Antes de escribir nada genera el «cómo estaba antes», que es la única red que tiene el cliente
     * si el resultado no es el que esperaba. Que esa foto falle **no impide aplicar**: es una red, no
     * un requisito, y negarse a trabajar porque no se pudo guardar una copia sería peor.
     */
    public function postApply(): string|RedirectResponse
    {
        set_time_limit(240);

        $path = $this->staging->currentPath();

        if ($path === null) {
            // No es una avería: `writable/uploads` no es un volumen y un despliegue se lo lleva. Se
            // dice con las mismas palabras con las que se le pediría volver a subirlo, porque eso es
            // exactamente lo que hay que hacer.
            return view('items/bulk_import', [
                'config'  => config(OSPOS::class)->settings,
                'preview' => null,
                'error'   => lang('Items.bulk_file_expired'),
            ]);
        }

        $this->snapshotBeforeApplying();

        // EL PLAN SE VUELVE A CALCULAR, Y ESO ES LO QUE HACE VERDAD LA PROMESA
        //
        // Lo que se guardó entre los dos pasos es el archivo, byte a byte, no el plan: así lo que se
        // aplica sale del mismo origen que lo que se enseñó. Guardar el plan en la sesión sería más
        // rápido y menos cierto --y no cabría.
        $result = $this->importer->apply($this->importer->plan($path));

        // Se borra el CSV subido pero NO se llama a `discard()`: eso se llevaría también el testigo de
        // la sesión, y con él la foto del «cómo estaba antes» que esta misma pantalla acaba de ofrecer.
        // Sin el archivo, un F5 sobre este POST ya no puede aplicar dos veces.
        @unlink($path);

        $fallidas = array_map(static fn (array $fila): int => $fila['line'], $result['failed']);

        // La foto previa puede no haberse podido escribir --es una red, no un requisito-- así que se
        // comprueba que exista antes de ofrecerla. Un botón que lleva a «el archivo ya no está» es peor
        // que no tener botón: parece una avería justo cuando el cliente busca su marcha atrás.
        $foto = $this->staging->previousSnapshotPath();

        return view('items/bulk_import', [
            'config'             => config(OSPOS::class)->settings,
            'preview'            => null,
            'error'              => $fallidas === [] ? null : lang('Items.csv_import_partially_failed', [count($fallidas), implode(', ', $fallidas)]),
            'result'             => $result,
            'previous_available' => $foto !== null && is_file($foto),
        ]);
    }

    /**
     * «Descargar cómo estaba antes»: la foto del catálogo justo antes del último cambio aplicado.
     */
    public function getPrevious(): DownloadResponse|RedirectResponse
    {
        $path = $this->staging->previousSnapshotPath();

        if ($path === null || ! is_file($path)) {
            return redirect()->to('items/bulk')->with('error', lang('Items.bulk_file_expired'));
        }

        return $this->response->download('articulos_antes_del_cambio.csv', (string) file_get_contents($path));
    }

    /**
     * La foto previa, generada justo antes de aplicar.
     *
     * Se traga sus propios fallos a propósito: ver el comentario de `postApply()`.
     */
    protected function snapshotBeforeApplying(): void
    {
        $path = $this->staging->previousSnapshotPath();

        if ($path === null) {
            return;
        }

        try {
            $this->exporter->writeTo($path);
        } catch (Throwable $e) {
            log_message('error', 'No se pudo guardar el «cómo estaba antes» del catálogo: ' . $e->getMessage());
        }
    }
}
