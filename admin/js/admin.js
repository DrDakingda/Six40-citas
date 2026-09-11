/* global six40Admin */
(function ($) {
  'use strict';

  function toast(msg, type) {
    var $t = $('<div class="six40-toast ' + (type||'success') + '">' + msg + '</div>');
    $('body').append($t);
    setTimeout(function(){ $t.addClass('show'); }, 10);
    setTimeout(function(){ $t.removeClass('show'); setTimeout(function(){ $t.remove(); },300); }, 3000);
  }

  // Status select: citas
  $(document).on('focus', '.six40-status-select', function(){ $(this).data('previous', $(this).val()); });
  $(document).on('change', '.six40-status-select', function(){
    var $sel = $(this), id = $sel.data('id'), status = $sel.val(), $row = $sel.closest('tr');
    $.post(six40Admin.ajaxUrl, { action:'six40_update_appt_status', nonce:six40Admin.nonce, id:id, status:status }, function(res){
      if (res.success) {
        var labels = {confirmed:'Confirmada',completed:'Completada',cancelled:'Cancelada',no_show:'No presentó'};
        $row.find('.six40-status').attr('class','six40-status six40-status--'+status).text(labels[status]||status);
        toast('Estado actualizado.','success');
      } else {
        toast('Error: '+(res.data||'No se pudo actualizar.'),'error');
        $sel.val($sel.data('previous')||'confirmed');
      }
    }).fail(function(){ toast('Error de conexión.','error'); });
  });

  // Barber status buttons
  $(document).on('click', '.six40-barber-btn', function(){
    var $btn = $(this), bid = $btn.data('id'), status = $btn.data('status'), $card = $btn.closest('.six40-barber-card');
    $btn.prop('disabled',true).text('…');
    $.post(six40Admin.ajaxUrl, { action:'six40_update_barber_status', nonce:six40Admin.nonce, barber_id:bid, status:status }, function(res){
      if (res.success) {
        var labels    = {available:'Disponible',vacation:'Vacaciones',sick:'Baja'};
        var btnLabels = {available:'✅ Disponible',vacation:'🏖️ Vacaciones',sick:'🤒 Baja'};
        $card.attr('class','six40-barber-card six40-barber--'+status);
        $card.find('.six40-barber-status-label').attr('class','six40-status six40-status--'+status+' six40-barber-status-label').text(labels[status]);
        $card.find('.six40-barber-btn').each(function(){
          var $b=$(this); $b.prop('disabled',false).text(btnLabels[$b.data('status')]).toggleClass('active',$b.data('status')===status);
        });
        toast('Barbero actualizado.','success');
      } else {
        toast('Error: '+(res.data||'No se pudo actualizar.'),'error');
        $btn.prop('disabled',false);
      }
    }).fail(function(){ toast('Error de conexión.','error'); $btn.prop('disabled',false); });
  });

  // Vacaciones programadas: añadir
  $(document).on('click', '.six40-vac-add-btn', function(){
    var $btn = $(this), bid = $btn.data('id'), $vac = $btn.closest('.six40-vac');
    var from = $vac.find('.six40-vac-from').val(), to = $vac.find('.six40-vac-to').val();
    if (!from || !to) { toast('Elige fecha desde y hasta.','error'); return; }
    if (to < from)    { toast('"Hasta" no puede ser anterior a "desde".','error'); return; }
    $btn.prop('disabled',true).text('…');
    $.post(six40Admin.ajaxUrl, { action:'six40_add_vacation', nonce:six40Admin.nonce, barber_id:bid, start:from, end:to }, function(res){
      if (res.success) { toast('Vacaciones añadidas.','success'); location.reload(); }
      else { toast('Error: '+(res.data||'No se pudo añadir.'),'error'); $btn.prop('disabled',false).text('Añadir'); }
    }).fail(function(){ toast('Error de conexión.','error'); $btn.prop('disabled',false).text('Añadir'); });
  });

  // Vacaciones programadas: eliminar
  $(document).on('click', '.six40-vac-del', function(){
    var $btn = $(this), bid = $btn.data('id'), index = $btn.data('index');
    $btn.prop('disabled',true);
    $.post(six40Admin.ajaxUrl, { action:'six40_delete_vacation', nonce:six40Admin.nonce, barber_id:bid, index:index }, function(res){
      if (res.success) { toast('Vacaciones eliminadas.','success'); location.reload(); }
      else { toast('Error: '+(res.data||'No se pudo eliminar.'),'error'); $btn.prop('disabled',false); }
    }).fail(function(){ toast('Error de conexión.','error'); $btn.prop('disabled',false); });
  });

  // ── Horarios: abrir/cerrar panel ────────────────────────────────────────────
  $(document).on('click', '.six40-sched-toggle', function(){
    $('#sched-' + $(this).data('id')).slideToggle(180);
  });
  $(document).on('click', '.six40-sched-close', function(){
    $('#sched-' + $(this).data('id')).slideUp(180);
  });

  // Horarios: añadir tramo
  $(document).on('click', '.six40-sched-add-btn', function(){
    var $btn = $(this), bid = $btn.data('id'), day = $btn.data('day'), $row = $btn.closest('.six40-sched-add');
    var from = $row.find('.six40-sched-from').val(), to = $row.find('.six40-sched-to').val();
    if (!from || !to) { toast('Pon hora de inicio y fin.','error'); return; }
    if (to <= from)  { toast('El fin debe ser posterior al inicio.','error'); return; }
    $btn.prop('disabled',true);
    $.post(six40Admin.ajaxUrl, { action:'six40_add_schedule', nonce:six40Admin.nonce, barber_id:bid, day:day, start:from, end:to }, function(res){
      if (res.success) { toast('Tramo añadido.','success'); location.reload(); }
      else { toast('Error: '+(res.data||'No se pudo añadir.'),'error'); $btn.prop('disabled',false); }
    }).fail(function(){ toast('Error de conexión.','error'); $btn.prop('disabled',false); });
  });

  // Horarios: eliminar tramo
  $(document).on('click', '.six40-sched-del', function(){
    var $btn = $(this), id = $btn.data('id');
    $btn.prop('disabled',true);
    $.post(six40Admin.ajaxUrl, { action:'six40_delete_schedule', nonce:six40Admin.nonce, id:id }, function(res){
      if (res.success) { toast('Tramo eliminado.','success'); location.reload(); }
      else { toast('Error: '+(res.data||'No se pudo eliminar.'),'error'); $btn.prop('disabled',false); }
    }).fail(function(){ toast('Error de conexión.','error'); $btn.prop('disabled',false); });
  });

  // Cambios de horario temporales: añadir
  $(document).on('click', '.six40-exc-add-btn', function(){
    var $btn = $(this), bid = $btn.data('id'), $exc = $btn.closest('.six40-exc');
    var type = $exc.find('.six40-exc-type').val() || 'available';
    var from = $exc.find('.six40-exc-from').val(), to = $exc.find('.six40-exc-to').val();
    var startTime = $exc.find('.six40-exc-start-time').val(), endTime = $exc.find('.six40-exc-end-time').val();
    if (!from || !to) { toast('Elige fecha desde y hasta.','error'); return; }
    if (!startTime || !endTime) { toast('Pon hora de inicio y fin.','error'); return; }
    if (to < from) { toast('"Hasta" no puede ser anterior a "desde".','error'); return; }
    if (endTime <= startTime) { toast('La hora fin debe ser posterior al inicio.','error'); return; }
    $btn.prop('disabled',true).text('…');
    var data = { action:'six40_add_schedule_exception', nonce:six40Admin.nonce, barber_id:bid, type:type, start:from, end:to, start_time:startTime, end_time:endTime };
    $.post(six40Admin.ajaxUrl, data, function(res){
      if (res.success) { toast('Cambio de horario añadido.','success'); location.reload(); }
      else { toast('Error: '+(res.data||'No se pudo añadir.'),'error'); $btn.prop('disabled',false).text('Añadir'); }
    }).fail(function(){
      toast('Error de conexión.','error'); $btn.prop('disabled',false).text('Añadir');
    });
  });

  // Cambios de horario temporales: eliminar
  $(document).on('click', '.six40-exc-del', function(){
    var $btn = $(this), bid = $btn.data('id'), index = $btn.data('index');
    $btn.prop('disabled',true);
    $.post(six40Admin.ajaxUrl, { action:'six40_delete_schedule_exception', nonce:six40Admin.nonce, barber_id:bid, index:index }, function(res){
      if (res.success) { toast('Cambio de horario eliminado.','success'); location.reload(); }
      else { toast('Error: '+(res.data||'No se pudo eliminar.'),'error'); $btn.prop('disabled',false); }
    }).fail(function(){ toast('Error de conexión.','error'); $btn.prop('disabled',false); });
  });

})(jQuery);
