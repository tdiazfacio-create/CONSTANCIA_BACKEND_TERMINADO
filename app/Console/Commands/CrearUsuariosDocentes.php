<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CrearUsuariosDocentes extends Command
{
    protected $signature = 'app:crear-usuarios-docentes {--ejecutar}';

    protected $description = 'Crear cuentas de docentes y generar credenciales temporales';

    public function handle()
    {
       $docentes = DB::table('docente')
    ->select('id_docente', 'nombre', 'rfc')
    ->orderBy('id_docente')
    ->get();

        $creados = 0;
        $omitidos = 0;
        $credenciales = [];

        foreach ($docentes as $docente) {
            $nombre = trim($docente->nombre);

            if ($nombre === '' || DB::table('usuario')
                ->where('id_docente', $docente->id_docente)
                ->exists()) {
                $omitidos++;
                continue;
            }

            $username = strtoupper(trim($docente->rfc ?? ''));

            if ($username === '') {
    $this->warn("Docente sin RFC: {$nombre}");
    $omitidos++;
    continue;
}

if (DB::table('usuario')
    ->where('username', $username)->exists()) {
    $this->warn("RFC ya registrado: {$username}");
    $omitidos++;
    continue;
}

            if (!$this->option('ejecutar')) {
                $this->line("Se crearía: {$username}");
                $creados++;
                continue;
            }

            $temporal = Str::random(20);

            DB::table('usuario')->insert([
                'username' => $username,
                'password_hash' => Hash::make($temporal),
                'id_rol' => 2,
                'id_docente' => $docente->id_docente,
                'needs_password_change' => 1,
            ]);

            $credenciales[] = [
                $docente->id_docente,
                $nombre,
                $username,
                $temporal,
            ];

            $creados++;
            $this->line("Cuenta creada: {$username}");
        }

        if ($this->option('ejecutar') && count($credenciales) > 0) {
            $directorio = storage_path('app/private');

            if (!is_dir($directorio)) {
                mkdir($directorio, 0700, true);
            }

            $archivo = $directorio . '/credenciales_docentes.csv';
            $fp = fopen($archivo, 'w');

            if ($fp === false) {
                throw new \RuntimeException(
                    'No se pudo crear el archivo de credenciales.'
                );
            }

            fputcsv($fp, [
                'id_docente',
                'nombre',
                'usuario',
                'contrasena_temporal'
            ]);

            foreach ($credenciales as $fila) {
                fputcsv($fp, $fila);
            }

            fclose($fp);

            $this->info("Credenciales guardadas en: {$archivo}");
        }

        $this->info("Cuentas preparadas o creadas: {$creados}");
        $this->info("Docentes omitidos: {$omitidos}");

        if (!$this->option('ejecutar')) {
            $this->warn('SIMULACIÓN: no se modificó la base de datos.');
        }

        return self::SUCCESS;
    }
}
