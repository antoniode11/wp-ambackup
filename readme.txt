===  WP AMBackup ===
Contributors: antoniode11
Tags: backup, database backup, file backup, restore, scheduled backup, migration
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin completo de backup para WordPress. Crea, programa, importa y exporta backups de tu sitio.

== Description ==

**WP AMBackup** es un plugin completo de backup para WordPress que te permite:

* **Crear backups completos** (archivos + base de datos) con un solo clic.
* **Programar backups automáticos** — diarios, semanales, mensuales o con intervalo personalizado (cada X días, semanas o meses).
* **Importar y restaurar backups** — sube un archivo ZIP y restaura tu sitio.
* **Descargar backups** — directamente desde el panel de WordPress.
* **Gestionar backups** — lista, elimina y controla la retención automática.
* **Notificaciones por email** cuando se completa o falla un backup.
* **Actualizaciones automáticas desde GitHub** — instala nuevas versiones directamente desde el panel de WordPress.

= Características =

* Exportación de base de datos (SQL) con soporte para grandes BD
* Compresión de archivos con ZipArchive (fallback a PclZip)
* Reemplaza URLs automáticamente al restaurar en un dominio diferente
* Reemplaza el prefijo de tablas automáticamente al restaurar
* Protección de la carpeta de backups con .htaccess
* Rotación automática de backups antiguos
* Barra de progreso en tiempo real
* Cancelación de backup en curso
* Interfaz moderna y responsiva

== Installation ==

1. Sube el plugin a `/wp-content/plugins/wp-ambackup/`
2. Activa el plugin en el panel de WordPress
3. Ve a **WP AMBackup** en el menú lateral

== Frequently Asked Questions ==

= ¿Los backups son seguros? =
Sí. Los backups se guardan en una carpeta protegida por `.htaccess` que impide el acceso directo.

= ¿Puedo restaurar en otro dominio? =
Sí. El plugin reemplaza automáticamente las URLs de la base de datos al restaurar.

= ¿El plugin se actualiza automáticamente? =
Sí. Una vez que publiques una nueva release en GitHub con un tag de versión (ej: `v1.1.0`), el plugin aparecerá como actualización en el panel de WordPress.

== Changelog ==

= 1.1.4 =
* Fix: límite de 8 MB de datos por chunk además de límite de archivos → lotes con vídeos/fotos grandes ya no superan el tiempo
* Reducido CHUNK_SIZE_INITIAL a 300 y MAX a 1000 para mayor seguridad
* UI muestra MB procesados por lote para diagnóstico

= 1.1.3 =
* Fix definitivo timeout compresión: CM_STORE (sin compresión) en ZIPs de parte → close() es puro I/O sin CPU → 21s pasan a ~2s por lote
* Los backups serán más grandes pero nunca fallarán por timeout de CPU en hosting compartido

= 1.1.2 =
* Fix definitivo timeout en ensamblaje: el ZIP maestro se construye un fichero por petición AJAX en lugar de todo a la vez (un ZIP de parte ~20MB por llamada en vez de 600MB juntos)
* Nueva fase "assembling" con barra de progreso propia en la UI

= 1.1.1 =
* Fix crítico: ZIP maestro usa CM_STORE (sin recomprimir) para los ZIPs de parte → close() deja de procesar cientos de MB de datos → resuelve timeout en el último chunk
* Los ZIPs de parte ya están comprimidos; recomprimirlos en el master era la causa del timeout final

= 1.1.0 =
* Arquitectura ZIP de ZIPs: cada chunk crea un ZIP de parte independiente para evitar timeout en ZipArchive::close() con archivos grandes (>40k archivos)
* ZIP maestro solo contiene los ZIPs de parte + database.sql + manifest.json (~50 entradas → cierre instantáneo)
* Paso de escaneo separado del paso de exportación de BD para evitar timeout en el inicio
* Importación actualizada: soporta formato multipart_v1 (extrae cada files_part_NNNN.zip en orden)
* Ajuste dinámico del tamaño de chunk según tiempo de respuesta del servidor (objetivo: 15s/chunk)

= 1.0.4 =
* Fix: reducido chunk a 500 archivos por petición para evitar timeout en hosting compartido
* Fix: captura de errores fatales PHP en process_chunk

= 1.0.2 =
* Fix: backup por chunks para hosting compartido con timeout de 30s
* Cada lote procesa 1500 archivos por petición AJAX

= 1.0.1 =
* Mejoras en compatibilidad con hosting compartido
* Mejor manejo de errores en el proceso de backup
* Aumentados límites de memoria y tiempo de ejecución

= 1.0.0 =
* Versión inicial

== Upgrade Notice ==

= 1.0.0 =
Versión inicial del plugin.
