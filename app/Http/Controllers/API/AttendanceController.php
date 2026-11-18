<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Asistencia;
use App\Models\Alumno;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function requestAttendance(Request $request)
    {
        // Iniciamos la medición del tiempo
        $inicio = microtime(true);

        Log::info('DATOS RECIBIDOS EN REGISTRO DE ENTRADA', [
            'control_number' => $request->input('control_number'),
            'conference_id' => $request->input('conference_id'),
        ]);

        $request->validate([
            'control_number' => 'required|string',
            'conference_id' => 'required|exists:conferencia,id_conferencia',
        ]);

        $alumno = Alumno::where(
            'num_control',
            $request->control_number
        )->first();

        if (!$alumno) {
            return response()->json([
                'message' => 'Número de control no encontrado.'
            ], 404);
        }

        $conferencia = DB::table('conferencia')
            ->where('id_conferencia', $request->conference_id)
            ->first();

        $existingAttendance = Asistencia::where(
            'id_conferencia',
            $request->conference_id
        )
        ->where('num_control', $request->control_number)
        ->first();

        if (
            $existingAttendance &&
            $existingAttendance->status === 'confirmed'
        ) {
            return response()->json([
                'message' => 'Ya has registrado tu asistencia previamente. ✅'
            ], 400);
        }

        // Guardamos la asistencia
        Asistencia::updateOrCreate(
            [
                'id_conferencia' => $request->conference_id,
                'num_control' => $request->control_number
            ],
            [
                'status' => 'confirmed',
                'fecha_registro' => now()
            ]
        );

        // Medimos el tiempo hasta guardar la asistencia
        Log::info('TIEMPO HASTA GUARDAR ASISTENCIA', [
            'segundos' => round(microtime(true) - $inicio, 3)
        ]);

        // Conservamos las notificaciones para el docente
        if ($conferencia && isset($conferencia->id_docente)) {
            DB::table('notifications')->insert([
                'id' => Str::uuid(),
                'type' => 'App\Notifications\AsistenciaAlumno',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id' => $conferencia->id_docente,
                'data' => json_encode([
                    'titulo' => 'Nueva Asistencia',
                    'mensaje' => "El alumno {$alumno->nombre} registró asistencia en: {$conferencia->nombre_conferencia}",
                    'tipo' => 'SUCCESS'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Medimos el tiempo total
        Log::info('TIEMPO TOTAL REGISTRO ASISTENCIA', [
            'segundos' => round(microtime(true) - $inicio, 3)
        ]);

        return response()->json([
            'message' => '¡Tu asistencia ha sido registrada correctamente! ✅'
        ], 200);
    }

    public function generateQr($id)
    {
        $frontendUrl = env('FRONTEND_URL');

        if (!$frontendUrl) {
            return response()->json([
                'message' => 'Error de configuración.'
            ], 500);
        }

        $url_destino = $frontendUrl . "/asistencia/" . $id;

        try {
            $qr = QrCode::size(300)->generate($url_destino);

            return response()->json([
                'qr_code' => (string) $qr,
                'url_destino' => $url_destino
            ]);
        } catch (\Exception $e) {
            Log::error('Error al generar el QR: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error interno al generar el código QR.'
            ], 500);
        }
    }

    public function generateExitQr($id)
    {
        $frontendUrl = env('FRONTEND_URL');

        if (!$frontendUrl) {
            return response()->json([
                'message' => 'Error de configuración.'
            ], 500);
        }

        $frontendUrl = rtrim($frontendUrl, '/');
        $url_destino = $frontendUrl . '/salida/' . $id;

        Log::info('URL generada para QR de salida: ' . $url_destino);

        try {
            $qr = QrCode::size(300)->generate($url_destino);

            return response()->json([
                'qr_code' => (string) $qr,
                'url_destino' => $url_destino
            ]);
        } catch (\Exception $e) {
            Log::error('Error al generar QR de salida: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al generar el código QR de salida.'
            ], 500);
        }
    }

    public function registerExit(Request $request)
    {
        $request->validate([
            'control_number' => 'required|string',
            'conference_id' => 'required|exists:conferencia,id_conferencia',
        ]);

        $asistencia = Asistencia::where(
            'id_conferencia',
            $request->conference_id
        )
        ->where('num_control', $request->control_number)
        ->first();

        if (!$asistencia || $asistencia->status !== 'confirmed') {
            return response()->json([
                'message' => 'Primero debes registrar tu entrada.'
            ], 400);
        }

        if ($asistencia->fecha_salida !== null) {
            return response()->json([
                'message' => 'Ya registraste tu salida anteriormente.'
            ], 400);
        }

        $actualizados = Asistencia::where(
            'id_conferencia',
            $request->conference_id
        )
        ->where('num_control', $request->control_number)
        ->where('status', 'confirmed')
        ->whereNull('fecha_salida')
        ->update([
            'fecha_salida' => now()
        ]);

        if (!$actualizados) {
            return response()->json([
                'message' => 'No se pudo registrar la salida.'
            ], 409);
        }

        return response()->json([
            'message' => 'Tu salida ha sido registrada correctamente.'
        ], 200);
    }
}
