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

    public function testElTurnoDelChequeoLeAvisaAlAdoptanteYALaRescatistaPorLosDosCanales(): void
    {
        $avisos = AvisosAdopcion::chequeoAsignado(101, 120, 103, 104, 'Cliente Dos', 'Pipa', '2026-11-26', '10:00:00');

        $this->assertCount(4, $avisos);
        $porId = array_column($avisos, null, 'id_operacion');
        $this->assertSame(
            ['chequeo-101-120-adoptante-web', 'chequeo-101-120-rescatista-web', 'chequeo-101-120-adoptante-whatsapp', 'chequeo-101-120-rescatista-whatsapp'],
            array_keys($porId)
        );

        $adoptante = $porId['chequeo-101-120-adoptante-whatsapp'];
        $this->assertSame(103, $adoptante['usuario_id']);
        $this->assertSame('WHATSAPP', $adoptante['canal']);
        $this->assertStringContainsString('chequeo de adopción de Pipa el jueves 26/11 a las 10:00', $adoptante['mensaje']);

        $rescatista = $porId['chequeo-101-120-rescatista-web'];
        $this->assertSame(104, $rescatista['usuario_id']);
        $this->assertSame('WEB', $rescatista['canal']);
        $this->assertStringContainsString('Cliente Dos tiene turno para el chequeo de adopción de Pipa', $rescatista['mensaje']);

        foreach ($avisos as $aviso) {
            $this->assertSame('POSTULACION', $aviso['tipo']);
            $this->assertStringNotContainsString("\n", $aviso['mensaje']);   // WhatsApp: un solo renglón
        }
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
