<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/**
 * El archivo que el cliente sube, entre el paso de «ver qué va a pasar» y el de «aplicarlo».
 *
 * POR QUÉ EL ARCHIVO TIENE QUE SOBREVIVIR A LA PETICIÓN
 *
 * La vista previa es una promesa: «esto es lo que voy a hacer». Para que sea verdad, lo que se aplica
 * tiene que ser EXACTAMENTE el archivo que se analizó. Pedirle al cliente que lo suba otra vez al
 * confirmar rompe eso: entre las dos subidas puede haberlo tocado, y entonces la vista previa habló
 * de un archivo que ya no existe. Por eso se guarda.
 *
 * DÓNDE, Y POR QUÉ NO EN LA RAÍZ DE `writable/uploads`
 *
 * En un subdirectorio propio. Esa raíz ya contiene `importCustomers.csv`, que `Customers::getCsv()`
 * **sirve como descarga**: mezclar un buzón donde escriben los clientes con un directorio del que se
 * sirven archivos es exactamente cómo una subida se convierte en una descarga.
 *
 * `writable/` está fuera del docroot y además su `.htaccess` tiene `Require all denied`. Doble cierre.
 *
 * EL NOMBRE ES UN TESTIGO AL AZAR, NO EL ID DE SESIÓN
 *
 * Un identificador de sesión metido en un nombre de archivo es un secreto que acaba en los listados,
 * en los registros y en los respaldos. Aquí el nombre es aleatorio y **el testigo vive en la sesión**:
 * el paso 2 solo acepta el que tiene guardado, nunca uno que venga del formulario. Así no hay ningún
 * parámetro que manipular, y no hace falta validar rutas porque la ruta nunca sale del servidor.
 *
 * CUÁNDO SE BORRA
 *
 * Al aplicar, al subir otro, y por barrido de antigüedad al empezar cada subida. Ese barrido es lo que
 * cubre el caso que nadie recuerda: el cliente que ve la vista previa, se va a comer y no vuelve. No
 * hace falta una tarea programada para algo que casi siempre tiene cero o un archivo.
 *
 * Y OJO: ESTE DIRECTORIO NO ES UN VOLUMEN
 *
 * `writable/uploads` vive dentro del contenedor -- solo `writable/logs` está montado. Un despliegue lo
 * vacía. Para un archivo que vive minutos está bien, pero significa que **«el archivo ya no está» es
 * un caso normal y no una avería**: desplegamos de noche y alguien puede estar a medias.
 */
final class Import_staging
{
    /** Debajo de `writable/uploads/`, para no compartir directorio con lo que se sirve. */
    private const SUBDIR = 'item_import';

    /** La clave de sesión donde vive el testigo. Es lo único que autoriza el paso 2. */
    private const SESSION_KEY = 'item_import_token';

    /**
     * Dos horas, la misma vida que una sesión (`app/Config/Session.php`). Más allá, quien vuelva ya no
     * tiene sesión: guardarle el archivo no le serviría de nada.
     */
    public const MAX_AGE_SECONDS = 7200;

    /** 5 MB. Un catálogo de 1.184 filas pesa unos 200 KB, así que sobra veinticinco veces. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function directory(): string
    {
        return WRITEPATH . 'uploads/' . self::SUBDIR . '/';
    }

    /**
     * Guarda el archivo recién subido y devuelve su testigo.
     *
     * Barre lo viejo ANTES de escribir: es el único momento en que sabemos que alguien está usando
     * esto, y hace innecesaria una tarea programada.
     *
     * @throws RuntimeException si el directorio no se puede crear o el archivo no se puede mover.
     */
    public function store(UploadedFile $file): string
    {
        $this->ensureDirectory();
        $this->sweepExpired();
        $this->discard();    // Subir otro archivo reemplaza al anterior; no se acumulan.

        $token = bin2hex(random_bytes(16));

        if (! $file->move($this->directory(), $token . '.csv', true)) {
            throw new RuntimeException(lang('Items.csv_import_failed'));
        }

        @chmod($this->pathFor($token), 0640);

        session()->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * La ruta del archivo de esta sesión, o null si no hay ninguno utilizable.
     *
     * Nunca recibe el testigo por parámetro: se lee de la sesión. Un método que aceptara un testigo
     * de fuera sería un parámetro que manipular, y entonces habría que defender la ruta contra `../`.
     * Así el problema no existe.
     */
    public function currentPath(): ?string
    {
        $token = session()->get(self::SESSION_KEY);

        if (! is_string($token) || ! preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        $path = $this->pathFor($token);

        if (! is_file($path)) {
            return null;    // Caducó, lo barrió otro, o se lo llevó un despliegue.
        }

        if (time() - (int) filemtime($path) > self::MAX_AGE_SECONDS) {
            @unlink($path);
            session()->remove(self::SESSION_KEY);

            return null;
        }

        return $path;
    }

    public function hasFile(): bool
    {
        return $this->currentPath() !== null;
    }

    /**
     * Olvida el archivo de esta sesión. Se llama al aplicar --en un `finally`, salga bien o mal-- y
     * al subir otro. Que no exista no es un error: puede haberlo barrido un despliegue.
     */
    public function discard(): void
    {
        $token = session()->get(self::SESSION_KEY);

        if (is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token)) {
            @unlink($this->pathFor($token));
            @unlink($this->previousPathFor($token));
        }

        session()->remove(self::SESSION_KEY);
    }

    /**
     * Consume el archivo subido pero conserva el testigo y la foto del «cómo estaba antes».
     *
     * ESTE MÉTODO EXISTE PORQUE `discard()` HACE DEMASIADO PARA ESTE MOMENTO
     *
     * Al aplicar hay que quitar el CSV --si se queda, un F5 sobre el POST vuelve a aplicarlo todo--
     * pero **no** el testigo: la pantalla de resultado acaba de ofrecer «descargar cómo estaba antes»,
     * y esa foto se localiza precisamente por el testigo. Llamar a `discard()` ahí dejaría el botón
     * apuntando a la nada.
     *
     * Sin este método, quien aplica tiene que borrar el archivo por su cuenta --y entonces la ruta y
     * el nombre los conocen dos sitios, que es como una de las dos partes se queda atrás el día que
     * algo cambie de sitio.
     */
    public function consumeUpload(): void
    {
        $path = $this->currentPath();

        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Dónde se guarda el «cómo estaba antes», que se genera justo antes de aplicar.
     *
     * Vive con el mismo testigo y muere con él: es la foto de ESTE cambio, y conservarla más allá
     * sería ofrecerle a alguien un archivo que ya no describe nada.
     */
    public function previousSnapshotPath(): ?string
    {
        $token = session()->get(self::SESSION_KEY);

        if (! is_string($token) || ! preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        return $this->previousPathFor($token);
    }

    /**
     * Borra lo que lleve ahí más de la cuenta, sea de quien sea.
     *
     * Es O(n) sobre un directorio que casi siempre tiene cero o un archivo, y no puede tumbar la
     * subida que lo dispara: cualquier fallo al borrar se ignora a propósito.
     */
    public function sweepExpired(): void
    {
        $files = glob($this->directory() . '*.csv');

        if ($files === false) {
            return;
        }

        $cutoff = time() - self::MAX_AGE_SECONDS;

        foreach ($files as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function pathFor(string $token): string
    {
        return $this->directory() . $token . '.csv';
    }

    private function previousPathFor(string $token): string
    {
        return $this->directory() . $token . '.antes.csv';
    }

    private function ensureDirectory(): void
    {
        $dir = $this->directory();

        if (is_dir($dir)) {
            return;
        }

        // 0750: lo escribe y lo lee el propio proceso, nadie más. Es el mismo trato que la imagen ya
        // le da a `writable/uploads`.
        if (! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException(lang('Items.csv_import_failed'));
        }
    }
}
