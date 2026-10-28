<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El status era enum('EN CURSO','CERRADO','CANCELADO').
        // Se pasa a varchar para admitir NUEVO y REPORTADO sin perder los 19 registros.
        DB::statement("ALTER TABLE log_conductores MODIFY status VARCHAR(20) NULL DEFAULT 'NUEVO'");

        Schema::table('log_conductores', function (Blueprint $table) {
            $table->string('tipo_caso', 20)->nullable()->after('id_conductor');
            $table->string('motivo', 40)->nullable()->after('tipo_caso');
            $table->unsignedSmallInteger('score')->nullable()->after('motivo');
            $table->unsignedInteger('llamada_tipo_id')->nullable()->after('score');
            $table->json('evidencia')->nullable()->after('metricas');
            $table->unsignedBigInteger('usuario_id')->nullable()->after('status');
            $table->date('fecha_reporte')->nullable()->after('usuario_id');
            $table->text('acuerdo')->nullable()->after('fecha_reporte');
            $table->date('fecha_compromiso')->nullable()->after('acuerdo');
            $table->string('resultado', 20)->nullable()->after('fecha_compromiso');
            $table->date('fecha_resultado')->nullable()->after('resultado');
            // 1 = caso abierto, NULL = cerrado. El unique permite un solo abierto por conductor.
            $table->unsignedTinyInteger('activo')->nullable()->default(1)->after('fecha_resultado');

            $table->index('id_conductor', 'idx_log_conductor');
            $table->index('status', 'idx_log_status');
            $table->index('tipo_caso', 'idx_log_tipo');
            $table->unique(['id_conductor', 'activo'], 'uk_log_activo');
        });

        // Los casos que quedaron "EN CURSO" son los abiertos; el resto pasa a historico.
        DB::table('log_conductores')->update(['activo' => null]);
        DB::table('log_conductores')->where('status', 'EN CURSO')->update(['activo' => 1]);

        Schema::create('log_conductores_eventos', function (Blueprint $table) {
            $table->id('id_evento');
            $table->unsignedInteger('id_log_conductor');
            $table->string('tipo', 20)->default('NOTA'); // APERTURA, CAMBIO_ESTADO, REPORTE, CIERRE, NOTA
            $table->string('estado', 20)->nullable();
            $table->text('detalle')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('id_log_conductor', 'idx_evento_log');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_conductores_eventos');

        Schema::table('log_conductores', function (Blueprint $table) {
            $table->dropUnique('uk_log_activo');
            $table->dropIndex('idx_log_conductor');
            $table->dropIndex('idx_log_status');
            $table->dropIndex('idx_log_tipo');
            $table->dropColumn([
                'tipo_caso', 'motivo', 'score', 'llamada_tipo_id', 'evidencia',
                'usuario_id', 'fecha_reporte', 'acuerdo', 'fecha_compromiso',
                'resultado', 'fecha_resultado', 'activo',
            ]);
        });

        DB::table('log_conductores')->where('status', 'NUEVO')->update(['status' => 'EN CURSO']);
        DB::table('log_conductores')->where('status', 'REPORTADO')->update(['status' => 'EN CURSO']);
        DB::statement("ALTER TABLE log_conductores MODIFY status ENUM('EN CURSO','CERRADO','CANCELADO') NULL DEFAULT 'EN CURSO'");
    }
};
