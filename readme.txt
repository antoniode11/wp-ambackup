===  WP AMBackup ===
Contributors: antoniode11
Tags: backup, database backup, file backup, restore, scheduled backup, migration
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.4
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
