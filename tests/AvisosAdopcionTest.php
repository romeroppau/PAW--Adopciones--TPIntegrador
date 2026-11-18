<?php

use PHPUnit\Framework\TestCase;

// Los avisos del circuito (contrato-adopciones.md, sección 8): a quién, de qué tipo y con qué
// id_operacion. Que lleguen a la campana lo prueba 09_adopciones.sh del Gateway.
final class AvisosAdopcionTest extends TestCase
{
    public function testLaPostulacionNuevaLeAvisaALaRescatista(): void
    {
        $aviso = AvisosAdopcion::postulacionNueva(7, 104, 'Cliente Dos', 'Pipa');

        $this->assertSame(104, $aviso['usuario_id']);
        $this->assertSame('POSTULACION', $aviso['tipo']);
        $this->assertSame('WEB', $aviso['canal']);
        $this->assertSame('postulacion-nueva-7', $aviso['id_operacion']);
        $this->assertStringContainsString('Cliente Dos se postuló para adoptar a Pipa', $aviso['mensaje']);
    }

    public function testElegirLeAvisaAlClienteElegido(): void
    {
        $aviso = AvisosAdopcion::postulacionElegida(7, 103, 'Pipa');

        $this->assertSame(103, $aviso['usuario_id']);
        $this->assertSame('POSTULACION', $aviso['tipo']);
        $this->assertSame('postulacion-elegida-7', $aviso['id_operacion']);
        $this->assertStringContainsString('Te eligieron para adoptar a Pipa', $aviso['mensaje']);
    }

    public function testCompletarLeAvisaAlAdoptante(): void
    {
        $aviso = AvisosAdopcion::adopcionFinalizada(101, 103, 'Pipa');

        $this->assertSame(103, $aviso['usuario_id']);
        $this->assertSame('CONTROL_ADOPCION', $aviso['tipo']);
        $this->assertSame('adopcion-finalizada-101', $aviso['id_operacion']);
    }

    public function testElAvisoCumpleLoQuePideBackend(): void
    {
        // Mensaje de hasta 500 caracteres y fecha AAAA-MM-DD HH:MM:SS, aunque el nombre sea muy largo.
        $aviso = AvisosAdopcion::postulacionNueva(1, 2, str_repeat('Nombre muy largo ', 40), 'Pipa');

        $this->assertLessThanOrEqual(500, mb_strlen($aviso['mensaje']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $aviso['fecha_programada']);
        $this->assertSame(['usuario_id', 'tipo', 'canal', 'mensaje', 'fecha_programada', 'id_operacion'], array_keys($aviso));
    }

    public function testSiBackendNoRespondeElAvisoNoCortaNada(): void
    {
        // Sin INTERNAL_TOKEN ni Backend: enviar() devuelve false y no lanza nada.
        $anterior = getenv('INTERNAL_TOKEN');
        putenv('INTERNAL_TOKEN');

        $this->assertFalse(AvisosAdopcion::enviar(AvisosAdopcion::adopcionFinalizada(1, 2, 'Pipa')));

        if ($anterior !== false) {
            putenv("INTERNAL_TOKEN=$anterior");
        }
    }
}
