<?php
/**
 * api/lib/migraciones.php
 * Helpers compartidos por los endpoints del Migrador DB
 * (herramientas_migraciones_*.php).
 */

// Carpeta donde viven los .sql, relativa a la raiz de la app cloud.
function migracionesDir(): string {
    // __DIR__ = cloud/api/lib  →  ../.. = cloud  →  /sql/migrations
    return dirname(__DIR__, 2) . '/sql/migrations';
}

// Solo nombres "planos" de archivo .sql, sin path. Bloquea ../ y similares.
function nombreMigracionValido(string $nombre): bool {
    if ($nombre === '' || strlen($nombre) > 255) return false;
    if (basename($nombre) !== $nombre)            return false;
    return (bool)preg_match('/^[A-Za-z0-9._\-]+\.sql$/', $nombre);
}

// Crea la tabla `migraciones` si no existe. Idempotente y barato.
// Permite que el Migrador funcione en bases pre-existentes que todavia
// no la tienen (la definicion canonica vive en db/schema.sql).
function asegurarTablaMigraciones(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `migraciones` (
            `id`       int(11)     NOT NULL AUTO_INCREMENT,
            `nombre`   varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            `hash`     varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            `aplicada` datetime(0)  NULL DEFAULT NULL,
            PRIMARY KEY (`id`) USING BTREE
         ) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic"
    );
}
