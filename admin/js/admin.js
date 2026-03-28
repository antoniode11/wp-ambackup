/* global wpamb, jQuery */
/**
 * WP AMBackup — Admin JavaScript
 * Backup por chunks para hosting compartido con timeout de 30s.
 */
(function ($) {
	'use strict';

	// =========================================================================
	// CREAR BACKUP (sistema de chunks)
	// =========================================================================

	var progressTimer  = null;
	var backupRunning  = false;

	function startBackup() {
		if ( backupRunning ) return;

		var includeFiles = $('#wpamb-inc-files').is(':checked') ? 1 : 0;
		var includeDB    = $('#wpamb-inc-db').is(':checked')    ? 1 : 0;

		if ( ! includeFiles && ! includeDB ) {
			showNotice( $('#wpamb-create-result'), 'error', 'Debes seleccionar al menos archivos o base de datos.' );
			return;
		}

		backupRunning = true;
		uiBackupStart();

		$.post( wpamb.ajax_url, {
			action:        'wpamb_create_backup',
			nonce:         wpamb.nonce,
			include_files: includeFiles,
			include_db:    includeDB
		})
		.done(function ( res ) {
			if ( ! res.success ) {
				backupFailed( res.data ? res.data.message : 'Error desconocido.' );
				return;
			}

			if ( res.data.done ) {
				// Solo BD → completado en un paso
				backupCompleted( res.data );
			} else if ( res.data.need_scan ) {
				// Escanear archivos en paso separado
				updateProgress( 12, 'Base de datos lista. Escaneando archivos…' );
				scanFiles();
			}
		})
		.fail(function () {
			backupFailed( 'Error de conexión al iniciar el backup.' );
		});
	}

	/**
	 * Paso intermedio: escanea archivos en petición separada.
	 */
	function scanFiles() {
		if ( ! backupRunning ) return;

		$.post( wpamb.ajax_url, { action: 'wpamb_scan_files', nonce: wpamb.nonce } )
		.done(function ( res ) {
			if ( ! res.success ) {
				backupFailed( res.data ? res.data.message : 'Error al escanear archivos.' );
				return;
			}
			updateProgress( 20, 'Escaneados ' + res.data.total_files + ' archivos. Iniciando compresión…' );
			processNextChunk( res.data.filename, 0, res.data.total_files, res.data.chunk_size || 100 );
		})
		.fail(function () {
			backupFailed( 'Error de conexión al escanear archivos.' );
		});
	}

	/**
	 * Llama recursivamente al servidor para procesar cada chunk de archivos.
	 * El chunk_size se ajusta dinámicamente según el tiempo de respuesta del servidor.
	 */
	function processNextChunk( filename, offset, total, chunkSize ) {
		if ( ! backupRunning ) return;

		$.post( wpamb.ajax_url, {
			action:     'wpamb_backup_chunk',
			nonce:      wpamb.nonce,
			chunk_size: chunkSize
		})
		.done(function ( res ) {
			if ( ! res.success ) {
				backupFailed( res.data ? res.data.message : 'Error procesando archivos.' );
				return;
			}

			if ( res.data.done ) {
				backupCompleted( res.data );
			} else if ( res.data.assembling ) {
				// Fase de ensamblaje: un ZIP de parte por petición
				var ai = res.data.assembly_index || 0;
				var tp = res.data.total_parts    || 0;
				updateProgress(
					res.data.percent || 93,
					'Ensamblando ZIP final… ' + ai + '/' + tp + ' partes'
				);
				setTimeout(function () {
					processNextChunk( filename, offset, total, chunkSize );
				}, 200 );
			} else {
				var pct          = res.data.percent         || 20;
				var current      = res.data.offset          || offset;
				var nextChunk    = res.data.next_chunk_size  || chunkSize;
				var timeTaken    = res.data.time_taken       || 0;

				updateProgress(
					pct,
					'Comprimiendo… ' + current + '/' + total +
					' | Lote: ' + nextChunk + ' archivos | ' + timeTaken + 's'
				);

				setTimeout(function () {
					processNextChunk( filename, current, total, nextChunk );
				}, 200 );
			}
		})
		.fail(function () {
			backupFailed( 'Error de conexión procesando un lote de archivos.' );
		});
	}

	function backupCompleted( data ) {
		backupRunning = false;
		clearInterval( progressTimer );
		uiBackupEnd();

		updateProgress( 100, 'Backup completado.' );

		var $result = $('#wpamb-create-result');
		showNotice(
			$result,
			'success',
			'Backup creado correctamente.<br>' +
			'<strong>' + data.filename + '</strong> (' + data.size + ')' +
			' <a href="' + data.url + '" class="wpamb-btn wpamb-btn--sm wpamb-btn--secondary" style="margin-left:10px;">' +
			'<span class="dashicons dashicons-download"></span> Descargar</a>'
		);

		setTimeout(function () { location.reload(); }, 3000 );
	}

	function backupFailed( message ) {
		backupRunning = false;
		clearInterval( progressTimer );
		uiBackupEnd();
		updateProgress( 0, '' );
		$('#wpamb-progress-wrap').hide();
		showNotice( $('#wpamb-create-result'), 'error', message );
	}

	// =========================================================================
	// UI helpers
	// =========================================================================

	function uiBackupStart() {
		$('#wpamb-create-btn').prop('disabled', true).addClass('loading');
		$('#wpamb-cancel-btn').show();
		$('#wpamb-progress-wrap').show();
		$('#wpamb-create-result').hide();
		updateProgress( 5, wpamb.creating );
	}

	function uiBackupEnd() {
		$('#wpamb-create-btn').prop('disabled', false).removeClass('loading');
		$('#wpamb-cancel-btn').hide();
	}

	function updateProgress( percent, message ) {
		$('#wpamb-progress-fill').css('width', Math.min( percent, 100 ) + '%');
		$('#wpamb-progress-msg').text( message );
	}

	// Cancelar
	$(document).on('click', '#wpamb-cancel-btn', function () {
		backupRunning = false;
		$.post( wpamb.ajax_url, { action: 'wpamb_cancel_backup', nonce: wpamb.nonce } );
		uiBackupEnd();
		$('#wpamb-progress-wrap').hide();
		showNotice( $('#wpamb-create-result'), 'info', 'Backup cancelado.' );
	});

	// =========================================================================
	// ELIMINAR BACKUP
	// =========================================================================

	$(document).on('click', '.wpamb-delete-btn', function () {
		var $btn = $(this);
		var id   = $btn.data('id');
		if ( ! confirm( wpamb.confirm_delete ) ) return;
		$btn.prop('disabled', true);

		$.post( wpamb.ajax_url, { action: 'wpamb_delete_backup', nonce: wpamb.nonce, backup_id: id })
		.done(function ( res ) {
			if ( res.success ) {
				$btn.closest('tr').fadeOut(300, function () {
					$(this).remove();
					updateEmptyState();
				});
			} else {
				alert( res.data.message || 'Error al eliminar.' );
				$btn.prop('disabled', false);
			}
		})
		.fail(function () {
			alert('Error de conexión.');
			$btn.prop('disabled', false);
		});
	});

	function updateEmptyState() {
		var $tbody = $('#wpamb-backup-tbody');
		if ( $tbody.length && $tbody.find('tr').length === 0 ) {
			$tbody.closest('table').replaceWith(
				'<div class="wpamb-empty">' +
				'<span class="dashicons dashicons-archive wpamb-empty__icon"></span>' +
				'<p>No hay backups todavía.</p></div>'
			);
		}
	}

	// =========================================================================
	// SELECCIÓN EN LOTE
	// =========================================================================

	$(document).on('change', '#wpamb-select-all', function () {
		var checked = $(this).is(':checked');
		$('.wpamb-row-check').prop('checked', checked);
		$('#wpamb-bulk-bar').toggle( checked );
	});

	$(document).on('change', '.wpamb-row-check', function () {
		var anyChecked = $('.wpamb-row-check:checked').length > 0;
		$('#wpamb-bulk-bar').toggle( anyChecked );
		$('#wpamb-select-all').prop('checked', $('.wpamb-row-check:not(:checked)').length === 0);
	});

	$(document).on('click', '#wpamb-bulk-delete', function () {
		var ids = $('.wpamb-row-check:checked').map(function () { return $(this).val(); }).get();
		if ( ! ids.length || ! confirm( wpamb.confirm_delete ) ) return;
		var $btn = $(this).prop('disabled', true);
		var done = 0;
		ids.forEach(function ( id ) {
			$.post( wpamb.ajax_url, { action: 'wpamb_delete_backup', nonce: wpamb.nonce, backup_id: id }, function ( res ) {
				if ( res.success ) {
					$('tr[data-id="' + id + '"]').fadeOut(200, function () { $(this).remove(); });
				}
				if ( ++done === ids.length ) {
					$btn.prop('disabled', false);
					$('#wpamb-bulk-bar').hide();
					updateEmptyState();
				}
			});
		});
	});

	// =========================================================================
	// PROGRAMACIÓN
	// =========================================================================

	$(document).on('change', '#schedule_enabled', function () {
		$('#wpamb-schedule-options').toggleClass('wpamb-hidden', ! $(this).is(':checked'));
	});

	$(document).on('change', 'input[name="schedule_type"]', function () {
		var type = $(this).val();
		$('.wpamb-schedule-weekly').toggleClass('wpamb-hidden',  type !== 'weekly');
		$('.wpamb-schedule-monthly').toggleClass('wpamb-hidden', type !== 'monthly');
		$('.wpamb-schedule-custom').toggleClass('wpamb-hidden',  type !== 'custom');
	});

	$(document).on('submit', '#wpamb-schedule-form', function (e) {
		e.preventDefault();
		var $result = $('#wpamb-schedule-result');
		var $btn    = $(this).find('[type="submit"]').prop('disabled', true);
		var data    = $(this).serializeArray().reduce(function (acc, f) { acc[f.name] = f.value; return acc; }, {});
		data.action           = 'wpamb_save_schedule';
		data.nonce            = wpamb.nonce;
		data.schedule_enabled = $('#schedule_enabled').is(':checked') ? 1 : 0;

		$.post( wpamb.ajax_url, data )
		.done(function ( res ) {
			$btn.prop('disabled', false);
			if ( res.success ) {
				showNotice( $result, 'success', res.data.message );
				$('#wpamb-next-run').text( res.data.next_run );
			} else {
				showNotice( $result, 'error', res.data.message || 'Error.' );
			}
		})
		.fail(function () {
			$btn.prop('disabled', false);
			showNotice( $result, 'error', 'Error de conexión.' );
		});
	});

	// =========================================================================
	// IMPORTAR
	// =========================================================================

	var $fileInput = $('#backup_file');

	$('#wpamb-dropzone').on('dragover dragenter', function (e) {
		e.preventDefault(); $(this).addClass('drag-over');
	}).on('dragleave drop', function (e) {
		e.preventDefault(); $(this).removeClass('drag-over');
	}).on('drop', function (e) {
		var files = e.originalEvent.dataTransfer.files;
		if ( files.length ) { $fileInput[0].files = files; handleFileSelected( files[0] ); }
	});

	$fileInput.on('change', function () {
		if ( this.files.length ) handleFileSelected( this.files[0] );
	});

	function handleFileSelected( file ) {
		$('#wpamb-file-name').text( file.name );
		$('#wpamb-file-size').text( '(' + formatBytes( file.size ) + ')' );
		$('#wpamb-selected-file').show();
		$('#wpamb-import-btn').prop('disabled', false);
	}

	function formatBytes( bytes ) {
		if ( bytes === 0 ) return '0 B';
		var k = 1024, sizes = ['B','KB','MB','GB'], i = Math.floor( Math.log(bytes) / Math.log(k) );
		return parseFloat( (bytes / Math.pow(k, i)).toFixed(1) ) + ' ' + sizes[i];
	}

	$(document).on('submit', '#wpamb-import-form', function (e) {
		e.preventDefault();
		var $result = $('#wpamb-import-result');
		var $btn    = $('#wpamb-import-btn').prop('disabled', true);
		var mode    = $(this).find('input[name="import_mode"]:checked').val();

		if ( 'restore' === mode && ! confirm( wpamb.confirm_restore ) ) {
			$btn.prop('disabled', false); return;
		}

		var formData = new FormData( this );
		formData.set('action', 'wpamb_import_backup');
		formData.set('nonce',  wpamb.nonce);

		var $prog = $('#wpamb-upload-progress').show();

		$.ajax({
			url: wpamb.ajax_url, type: 'POST',
			data: formData, processData: false, contentType: false,
			xhr: function () {
				var xhr = new window.XMLHttpRequest();
				xhr.upload.addEventListener('progress', function (e) {
					if ( e.lengthComputable ) {
						var pct = Math.round( e.loaded / e.total * 100 );
						$('#wpamb-upload-fill').css('width', pct + '%');
						$('#wpamb-upload-msg').text('Subiendo… ' + pct + '%');
					}
				});
				return xhr;
			}
		})
		.done(function ( res ) {
			$btn.prop('disabled', false); $prog.hide();
			if ( res.success ) {
				showNotice( $result, 'success', res.data.message + ( res.data.size ? ' <strong>' + res.data.size + '</strong>' : '' ) );
				$('#wpamb-selected-file').hide();
				$fileInput.val('');
				$btn.prop('disabled', true);
			} else {
				showNotice( $result, 'error', res.data.message || 'Error al importar.' );
			}
		})
		.fail(function () {
			$btn.prop('disabled', false); $prog.hide();
			showNotice( $result, 'error', 'Error de conexión.' );
		});
	});

	// =========================================================================
	// UTILIDADES
	// =========================================================================

	function showNotice( $el, type, message ) {
		$el.removeClass('wpamb-notice--success wpamb-notice--error wpamb-notice--info')
		   .addClass('wpamb-notice wpamb-notice--' + type)
		   .html( message ).show();
		if ( 'success' === type ) setTimeout(function () { $el.fadeOut(); }, 7000);
	}

	// =========================================================================
	// INIT
	// =========================================================================

	$(document).ready(function () {
		$(document).on('click', '#wpamb-create-btn', startBackup);
	});

})(jQuery);
