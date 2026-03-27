/* global wpamb, jQuery */
/**
 * WP AMBackup — Admin JavaScript
 */
(function ($) {
	'use strict';

	// =========================================================================
	// CREAR BACKUP
	// =========================================================================

	var progressTimer = null;

	function startBackup() {
		var $btn       = $('#wpamb-create-btn');
		var $cancelBtn = $('#wpamb-cancel-btn');
		var $progress  = $('#wpamb-progress-wrap');
		var $result    = $('#wpamb-create-result');
		var includeFiles = $('#wpamb-inc-files').is(':checked') ? 1 : 0;
		var includeDB    = $('#wpamb-inc-db').is(':checked')    ? 1 : 0;

		if (!includeFiles && !includeDB) {
			showNotice($result, 'error', 'Debes seleccionar al menos archivos o base de datos.');
			return;
		}

		$btn.prop('disabled', true).addClass('loading');
		$cancelBtn.show();
		$progress.show();
		$result.hide();
		updateProgress(0, wpamb.creating);

		// Iniciar polling de progreso
		progressTimer = setInterval(function () {
			$.post(wpamb.ajax_url, { action: 'wpamb_get_progress' }, function (res) {
				if (res.success && res.data) {
					var pct = parseInt(res.data.percent, 10);
					var msg = res.data.message;
					updateProgress(Math.max(0, Math.min(100, pct)), msg);

					// Si es -1 hubo error
					if (pct === -1) {
						clearInterval(progressTimer);
					}
				}
			});
		}, 1500);

		$.post(wpamb.ajax_url, {
			action:        'wpamb_create_backup',
			nonce:         wpamb.nonce,
			include_files: includeFiles,
			include_db:    includeDB
		})
		.done(function (res) {
			clearInterval(progressTimer);
			$btn.prop('disabled', false).removeClass('loading');
			$cancelBtn.hide();

			if (res.success) {
				updateProgress(100, res.data.message);
				showNotice(
					$result,
					'success',
					res.data.message +
					'<br><strong>' + res.data.filename + '</strong> (' + res.data.size + ')' +
					' <a href="' + res.data.url + '" class="wpamb-btn wpamb-btn--sm wpamb-btn--secondary" style="margin-left:10px;">' +
					'<span class="dashicons dashicons-download"></span> Descargar</a>'
				);
				// Recargar lista de backups si existe
				setTimeout(function () { location.reload(); }, 2500);
			} else {
				updateProgress(0, '');
				showNotice($result, 'error', res.data.message || 'Error desconocido.');
				$progress.hide();
			}
		})
		.fail(function () {
			clearInterval(progressTimer);
			$btn.prop('disabled', false).removeClass('loading');
			$cancelBtn.hide();
			showNotice($result, 'error', 'Error de conexión. Por favor intenta de nuevo.');
			$progress.hide();
		});
	}

	function updateProgress(percent, message) {
		$('#wpamb-progress-fill').css('width', percent + '%');
		$('#wpamb-progress-msg').text(message);
	}

	// Cancelar backup
	$(document).on('click', '#wpamb-cancel-btn', function () {
		clearInterval(progressTimer);
		$.post(wpamb.ajax_url, {
			action: 'wpamb_cancel_backup',
			nonce:  wpamb.nonce
		}, function () {
			$('#wpamb-create-btn').prop('disabled', false).removeClass('loading');
			$(this).hide();
			$('#wpamb-progress-wrap').hide();
			showNotice($('#wpamb-create-result'), 'info', 'Backup cancelado.');
		}.bind(this));
	});

	// =========================================================================
	// ELIMINAR BACKUP
	// =========================================================================

	$(document).on('click', '.wpamb-delete-btn', function () {
		var $btn = $(this);
		var id   = $btn.data('id');

		if (!confirm(wpamb.confirm_delete)) {
			return;
		}

		$btn.prop('disabled', true);

		$.post(wpamb.ajax_url, {
			action:    'wpamb_delete_backup',
			nonce:     wpamb.nonce,
			backup_id: id
		})
		.done(function (res) {
			if (res.success) {
				var $row = $btn.closest('tr');
				$row.fadeOut(300, function () {
					$(this).remove();
					updateEmptyState();
				});
			} else {
				alert(res.data.message || 'Error al eliminar.');
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
		if ($tbody.length && $tbody.find('tr').length === 0) {
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
		$('#wpamb-bulk-bar').toggle(checked);
	});

	$(document).on('change', '.wpamb-row-check', function () {
		var anyChecked = $('.wpamb-row-check:checked').length > 0;
		$('#wpamb-bulk-bar').toggle(anyChecked);
		$('#wpamb-select-all').prop('checked', $('.wpamb-row-check:not(:checked)').length === 0);
	});

	$(document).on('click', '#wpamb-bulk-delete', function () {
		var ids = $('.wpamb-row-check:checked').map(function () {
			return $(this).val();
		}).get();

		if (!ids.length) return;
		if (!confirm(wpamb.confirm_delete)) return;

		var $btn = $(this).prop('disabled', true);
		var done = 0;

		ids.forEach(function (id) {
			$.post(wpamb.ajax_url, {
				action:    'wpamb_delete_backup',
				nonce:     wpamb.nonce,
				backup_id: id
			}, function (res) {
				if (res.success) {
					$('tr[data-id="' + id + '"]').fadeOut(200, function () { $(this).remove(); });
				}
				done++;
				if (done === ids.length) {
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

	// Mostrar/ocultar opciones según toggle
	$(document).on('change', '#schedule_enabled', function () {
		$('#wpamb-schedule-options').toggleClass('wpamb-hidden', !$(this).is(':checked'));
	});

	// Mostrar campos según tipo seleccionado
	$(document).on('change', 'input[name="schedule_type"]', function () {
		var type = $(this).val();
		$('.wpamb-schedule-weekly').toggleClass('wpamb-hidden', type !== 'weekly');
		$('.wpamb-schedule-monthly').toggleClass('wpamb-hidden', type !== 'monthly');
		$('.wpamb-schedule-custom').toggleClass('wpamb-hidden', type !== 'custom');
	});

	// Guardar programación vía AJAX
	$(document).on('submit', '#wpamb-schedule-form', function (e) {
		e.preventDefault();
		var $form   = $(this);
		var $result = $('#wpamb-schedule-result');
		var $btn    = $form.find('[type="submit"]').prop('disabled', true);

		var data = $form.serializeArray().reduce(function (acc, field) {
			acc[field.name] = field.value;
			return acc;
		}, {});

		data.action = 'wpamb_save_schedule';
		data.nonce  = wpamb.nonce;
		// Enviar valor de checkbox (no lo incluye serializeArray si no está marcado)
		data.schedule_enabled = $('#schedule_enabled').is(':checked') ? 1 : 0;

		$.post(wpamb.ajax_url, data)
		.done(function (res) {
			$btn.prop('disabled', false);
			if (res.success) {
				showNotice($result, 'success', res.data.message);
				$('#wpamb-next-run').text(res.data.next_run);
			} else {
				showNotice($result, 'error', res.data.message || 'Error.');
			}
		})
		.fail(function () {
			$btn.prop('disabled', false);
			showNotice($result, 'error', 'Error de conexión.');
		});
	});

	// =========================================================================
	// IMPORTAR
	// =========================================================================

	var $dropzone = $('#wpamb-dropzone');
	var $fileInput = $('#backup_file');

	// Drag & Drop
	$dropzone.on('dragover dragenter', function (e) {
		e.preventDefault();
		$(this).addClass('drag-over');
	});

	$dropzone.on('dragleave drop', function (e) {
		e.preventDefault();
		$(this).removeClass('drag-over');
	});

	$dropzone.on('drop', function (e) {
		var files = e.originalEvent.dataTransfer.files;
		if (files.length) {
			$fileInput[0].files = files;
			handleFileSelected(files[0]);
		}
	});

	$fileInput.on('change', function () {
		if (this.files.length) {
			handleFileSelected(this.files[0]);
		}
	});

	function handleFileSelected(file) {
		$('#wpamb-file-name').text(file.name);
		$('#wpamb-file-size').text('(' + formatBytes(file.size) + ')');
		$('#wpamb-selected-file').show();
		$('#wpamb-import-btn').prop('disabled', false);
	}

	function formatBytes(bytes) {
		if (bytes === 0) return '0 B';
		var k    = 1024;
		var sizes = ['B', 'KB', 'MB', 'GB'];
		var i    = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
	}

	$(document).on('submit', '#wpamb-import-form', function (e) {
		e.preventDefault();
		var $form   = $(this);
		var $result = $('#wpamb-import-result');
		var $btn    = $('#wpamb-import-btn').prop('disabled', true);

		var mode = $form.find('input[name="import_mode"]:checked').val();
		if ('restore' === mode) {
			if (!confirm(wpamb.confirm_restore)) {
				$btn.prop('disabled', false);
				return;
			}
		}

		var formData = new FormData($form[0]);
		formData.set('action', 'wpamb_import_backup');
		formData.set('nonce',  wpamb.nonce);

		var $uploadProgress = $('#wpamb-upload-progress').show();
		var $uploadFill     = $('#wpamb-upload-fill');
		var $uploadMsg      = $('#wpamb-upload-msg');

		$.ajax({
			url:         wpamb.ajax_url,
			type:        'POST',
			data:        formData,
			processData: false,
			contentType: false,
			xhr: function () {
				var xhr = new window.XMLHttpRequest();
				xhr.upload.addEventListener('progress', function (e) {
					if (e.lengthComputable) {
						var pct = Math.round((e.loaded / e.total) * 100);
						$uploadFill.css('width', pct + '%');
						$uploadMsg.text('Subiendo… ' + pct + '%');
					}
				});
				return xhr;
			}
		})
		.done(function (res) {
			$btn.prop('disabled', false);
			$uploadProgress.hide();

			if (res.success) {
				showNotice($result, 'success',
					res.data.message +
					(res.data.size ? ' <strong>' + res.data.size + '</strong>' : '')
				);
				$('#wpamb-selected-file').hide();
				$('#wpamb-file-name').text('');
				$fileInput.val('');
				$btn.prop('disabled', true);
			} else {
				showNotice($result, 'error', res.data.message || 'Error al importar.');
			}
		})
		.fail(function () {
			$btn.prop('disabled', false);
			$uploadProgress.hide();
			showNotice($result, 'error', 'Error de conexión.');
		});
	});

	// =========================================================================
	// UTILIDADES
	// =========================================================================

	function showNotice($el, type, message) {
		$el.removeClass('wpamb-notice--success wpamb-notice--error wpamb-notice--info')
		   .addClass('wpamb-notice wpamb-notice--' + type)
		   .html(message)
		   .show();

		if (type === 'success') {
			setTimeout(function () { $el.fadeOut(); }, 6000);
		}
	}

	// =========================================================================
	// INIT
	// =========================================================================

	$(document).ready(function () {
		// Bind botón de crear backup
		$(document).on('click', '#wpamb-create-btn', startBackup);
	});

})(jQuery);
