<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Regla de vigencia: una persona pierde la cobertura cuando acumula UMBRAL_MESES meses
 * seguidos sin pago.
 *
 * La usan dos lugares, para que ambos cuenten exactamente igual:
 *   - api/asegurado.php  → muestra "N meses seguidos sin pago" en la ficha (informativo)
 *   - api/pago.php       → al editar un mes a impago, decide si la persona pasa a NO VIGENTE
 */
class Vigencia
{
    /** Meses seguidos sin pago a partir de los cuales se pierde la cobertura. */
    public const UMBRAL_MESES = 3;

    /**
     * Cuenta los meses consecutivos sin pago, hacia atrás desde el último mes completo
     * (si hoy es 5 de octubre, el mes de referencia es septiembre).
     *
     * Un mes cuenta como "sin pago" si está impago O si no tiene datos. La cuenta se detiene
     * en el primer mes pagado, o al llegar al mes de alta de la persona (los meses anteriores
     * a su afiliación no se cuentan).
     *
     * @param array<string,bool> $pagadoPorMes  'AAAA-MM' => true si ese mes está pagado
     * @param string|null        $fechaAlta     'AAAA-MM-DD' o null
     * @return array{mes_referencia:string, consecutivos:int, desde:?string}
     *         `desde` es el primer mes de la racha sin pago (null si no hay racha); sirve para
     *         saber si un mes editado forma parte de ella.
     */
    public static function mesesSinPago(array $pagadoPorMes, ?string $fechaAlta): array
    {
        $referencia = new DateTimeImmutable('first day of last month');
        // Sin fecha de alta, el límite es el primer mes con datos
        $limite = $fechaAlta ? substr($fechaAlta, 0, 7) : ($pagadoPorMes ? min(array_keys($pagadoPorMes)) : $referencia->format('Y-m'));

        $consecutivos = 0;
        $desde = null;
        for ($m = $referencia; $m->format('Y-m') >= $limite; $m = $m->modify('-1 month')) {
            if ($pagadoPorMes[$m->format('Y-m')] ?? false) {
                break;
            }
            $consecutivos++;
            $desde = $m->format('Y-m');
        }

        return ['mes_referencia' => $referencia->format('Y-m'), 'consecutivos' => $consecutivos, 'desde' => $desde];
    }

    /**
     * ¿Hay que dar de baja de la vigencia a esta persona por el mes que acaba de quedar impago?
     * Sí cuando la racha sin pago llega al umbral Y el mes editado forma parte de ella.
     * (Si no formara parte, editar un mes antiguo cualquiera cambiaría el estado por una
     * racha que ya existía sin que el usuario la tocara.)
     */
    public static function debePerderCobertura(array $racha, string $periodoEditado): bool
    {
        return $racha['consecutivos'] >= self::UMBRAL_MESES
            && $periodoEditado >= $racha['desde']
            && $periodoEditado <= $racha['mes_referencia'];
    }
}
