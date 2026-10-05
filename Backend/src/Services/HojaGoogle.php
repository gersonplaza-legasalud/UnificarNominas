<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Envía a la hoja de Google los cambios hechos en la app (app → hoja).
 *
 * ── Flujo ──────────────────────────────────────────────────────────────────────
 *   api/pago.php guarda el cambio en MySQL
 *        └─▶ encolar()              lo anota en la tabla `cola_hoja`
 *        └─▶ procesarPendientes()   lo envía al script de Google (aplicación web de Apps Script)
 *                                   que escribe la celda en la hoja
 *   Si el envío falla (sin internet, script caído…), el cambio queda pendiente en la cola y
 *   scripts/procesar_cola.php lo reintenta cada minuto. Así no se pierde ninguna edición.
 *
 * ── Garantías ──────────────────────────────────────────────────────────────────
 *   - Los cambios se envían EN ORDEN. Si uno falla por un problema pasajero, se detiene el
 *     envío y se reintenta después, para que dos ediciones de la misma celda no lleguen
 *     cambiadas de orden.
 *   - Un error permanente (el script responde que la persona o el mes no existen en la hoja)
 *     marca ese cambio como `fallido` y sigue con los demás: reintentarlo no lo arreglaría.
 *   - Solo un proceso a la vez vacía la cola (candado de MySQL).
 *   - Escribir la celda desde el script NO dispara el aviso de edición de la hoja, así que
 *     no se produce un ciclo (hoja → app → hoja…).
 *
 * Si no hay URL o clave en config/hoja.php, el servicio está inactivo (activa() = false) y la
 * app funciona como si esta conexión no existiera.
 */
class HojaGoogle
{
    /** Reintentos antes de rendirse y marcar el cambio como fallido. */
    private const MAX_INTENTOS = 10;

    /** @param array{url:string, token:string} $config  ver config/hoja.php */
    public function __construct(private PDO $pdo, private array $config)
    {
    }

    /** ¿Está configurada la conexión con la hoja? */
    public function activa(): bool
    {
        return $this->config['url'] !== '' && $this->config['token'] !== '';
    }

    /**
     * Anota un cambio para enviarlo a la hoja.
     *
     * @param array $cambio  rut (int), periodo ('AAAA-MM'), valor (número | 'PAGADO' | 0),
     *                       y opcionalmente nota (string|null) y estado ('NO VIGENTE').
     *                       Solo se incluye lo que hay que escribir: una clave ausente no toca la celda.
     * @return int id del cambio en la cola
     */
    public function encolar(array $cambio): int
    {
        $this->pdo->prepare('INSERT INTO cola_hoja (payload) VALUES (?)')
            ->execute([json_encode($cambio, JSON_UNESCAPED_UNICODE)]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Envía los cambios pendientes, del más antiguo al más nuevo.
     *
     * @param int $limite  cuántos cambios como máximo en esta pasada
     * @return array{enviados:int, fallidos:int, pendientes:int}  `pendientes` = cuántos quedan por enviar
     */
    public function procesarPendientes(int $limite = 20): array
    {
        $resultado = ['enviados' => 0, 'fallidos' => 0, 'pendientes' => 0];

        // Candado: si otro proceso (la petición de otro usuario, o el reintento programado)
        // ya está vaciando la cola, no se hace nada para no enviar el mismo cambio dos veces
        if (!$this->pdo->query("SELECT GET_LOCK('cola_hoja', 0)")->fetchColumn()) {
            return $this->contarPendientes($resultado);
        }

        try {
            $stmt = $this->pdo->prepare("SELECT id, payload, intentos FROM cola_hoja WHERE estado = 'pendiente' ORDER BY id LIMIT " . (int) $limite);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $job) {
                try {
                    $this->enviar(json_decode($job['payload'], true));
                    $this->pdo->prepare("UPDATE cola_hoja SET estado = 'enviado', enviado_at = NOW(), intentos = intentos + 1, ultimo_error = NULL WHERE id = ?")
                        ->execute([$job['id']]);
                    $resultado['enviados']++;
                } catch (ErrorDeHoja $e) {
                    $intentos = (int) $job['intentos'] + 1;
                    $rendirse = $e->permanente || $intentos >= self::MAX_INTENTOS;
                    $this->pdo->prepare('UPDATE cola_hoja SET estado = ?, intentos = ?, ultimo_error = ? WHERE id = ?')
                        ->execute([$rendirse ? 'fallido' : 'pendiente', $intentos, mb_substr($e->getMessage(), 0, 255), $job['id']]);

                    if ($rendirse) {
                        $resultado['fallidos']++;
                        continue; // este cambio ya no se reintenta; se sigue con los demás
                    }
                    break; // problema pasajero: se detiene para conservar el orden y se reintenta después
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('cola_hoja')");
        }

        return $this->contarPendientes($resultado);
    }

    private function contarPendientes(array $resultado): array
    {
        $resultado['pendientes'] = (int) $this->pdo->query("SELECT COUNT(*) FROM cola_hoja WHERE estado = 'pendiente'")->fetchColumn();
        return $resultado;
    }

    /**
     * Pide al script de Google que escriba un cambio en la hoja.
     *
     * El script publicado como aplicación web responde con una redirección a la dirección
     * donde está el resultado; cURL la sigue sola. La respuesta es un JSON {ok: true} o
     * {ok: false, error: "...", permanente: true|false}.
     *
     * @throws ErrorDeHoja  si no se pudo entregar o el script rechazó el cambio
     */
    private function enviar(array $cambio): void
    {
        $ch = curl_init($this->config['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['token' => $this->config['token'], 'accion' => 'actualizar'] + $cambio, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $falloRed = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false) {
            throw new ErrorDeHoja('Sin conexión con la hoja: ' . $falloRed, false);
        }
        $respuesta = json_decode($cuerpo, true);
        if (!is_array($respuesta)) {
            // Google devolvió una página en vez del JSON (script mal publicado, sesión pedida, error 5xx…)
            throw new ErrorDeHoja("Respuesta inesperada de la hoja (HTTP $codigo)", false);
        }
        if (!($respuesta['ok'] ?? false)) {
            throw new ErrorDeHoja((string) ($respuesta['error'] ?? 'La hoja rechazó el cambio'), (bool) ($respuesta['permanente'] ?? false));
        }
    }
}

/** Error al entregar un cambio a la hoja. `permanente` = reintentar no lo va a arreglar. */
class ErrorDeHoja extends RuntimeException
{
    public function __construct(string $mensaje, public bool $permanente)
    {
        parent::__construct($mensaje);
    }
}
