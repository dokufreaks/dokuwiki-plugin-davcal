/* DOKUWIKI:include_once fullcalendar-2.4.0/moment.js */
/* DOKUWIKI:include_once fullcalendar-2.4.0/fullcalendar.js */
/* DOKUWIKI:include_once datetimepicker-2.4.5/jquery.datetimepicker.js */

jQuery(function() {
    // Redefine functions for using moment.js with datetimepicker
    
    Date.parseDate = function( input, format ){
      return moment(input,format).toDate();
    };
    Date.prototype.dateFormat = function( format ){
      return moment(this).format(format);
    };
    
    
    // Initialize the davcal popup
    var res = jQuery('#fullCalendar').fullCalendar({
        dayClick: function(date, jsEvent, view) {
            dw_davcal__modals.showNewEventDialog(date);
        },
        eventClick: function(calEvent, jsEvent, view) {
            dw_davcal__modals.showEditEventDialog(calEvent);
        },
        events: {
            url: DOKU_BASE + 'lib/exe/ajax.php',
            type: 'POST',
            data: {
                call: 'plugin_davcal',
                action: 'getEvents',
                id: JSINFO.id
            },
            error: function() {
                //alert('there was an error retrieving calendar data');
            }
        },
        header: {
            left: 'title',
            center: 'today prev,next',
            right: 'month,agendaWeek,agendaDay'
        },
    });
});

var dw_davcal__modals = {
    $newEventDialog : null,
    $editEventDialog: null,
    $infoDialog: null,
    $confirmDialog: null,
    msg: null,
    completeCb: null,
    action: null,
    uid: null,
    
    showEditEventDialog : function(calEvent) {
        if(dw_davcal__modals.$editEventDialog)
            return;
            
        var dialogButtons = {};
        dialogButtons[LANG.plugins.davcal['edit']] = function() {
            var postArray = { };
            jQuery("input[class=dw_davcal__editevent]").each(function() {
              if(jQuery(this).attr('type') == 'checkbox')
              {
                  postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
              }
              else
              {
                  postArray[jQuery(this).prop('name')] = jQuery(this).val();
              }
            });
            jQuery('#dw_davcal__ajaxedit').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
            jQuery.post(
                DOKU_BASE + 'lib/exe/ajax.php',
                {
                    call: 'plugin_davcal',
                    id: JSINFO.id,
                    action: 'editEvent',
                    params: postArray
                },
                function(data)
                {
                    var result = data['result'];
                    var html = data['html'];
                    jQuery('#dw_davcal__ajaxedit').html(html);
                    if(result === true)
                    {
                        jQuery('#fullCalendar').fullCalendar('refetchEvents');
                        dw_davcal__modals.hideEditEventDialog();
                    }
                }
            );
        };
        dialogButtons[LANG.plugins.davcal['cancel']] = function() {
            dw_davcal__modals.hideEditEventDialog();
        };
        dialogButtons[LANG.plugins.davcal['delete']] = function() {
            dw_davcal__modals.action = 'deleteEvent';
            dw_davcal__modals.msg = LANG.plugins.davcal['really_delete_this_event'];
            dw_davcal__modals.completeCb = function(data) {
                if(data.result == false)
                {
                    dw_davcal__modals.msg = data.errmsg;
                    dw_davcal__modals.showInfoDialog();
                }
                else
                {
                    jQuery('#fullCalendar').fullCalendar('refetchEvents');
                    dw_davcal__modals.hideEditEventDialog();
                }
            };
            dw_davcal__modals.showConfirmDialog();
        };
        dw_davcal__modals.uid = calEvent.id;
        dw_davcal__modals.$editEventDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: LANG.plugins.davcal['edit_event'],
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
            '<div><table><tr><td>' + LANG.plugins.davcal['title'] + '</td><td><input type="text" id="dw_davcal__eventname_edit" name="eventname" class="dw_davcal__editevent"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['from'] + '</td><td><input type="text" name="eventfrom" id="dw_davcal__eventfrom_edit" class="dw_davcal__editevent"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['to'] + '</td><td><input type="text" name="eventto" id="dw_davcal__eventto_edit" class="dw_davcal__editevent"></td></tr>' +
            '<tr><td colspan="2"><input type="checkbox" name="allday" id="dw_davcal__allday_edit" class="dw_davcal__editevent">' + LANG.plugins.davcal['allday'] + '</td></tr>' +
            '</table>' +
            '<input type="hidden" name="uid" id="dw_davcal__uid_edit" class="dw_davcal__editevent">' +
            '</div>' +
            '<div id="dw_davcal__ajaxedit"></div>'
            )
       .parent()
       .attr('id','dw_davcal__edit')
       .show()
       .appendTo('.dokuwiki:first');
       
       jQuery('#dw_davcal__uid_edit').val(calEvent.id);
       jQuery('#dw_davcal__eventname_edit').val(calEvent.title);
       jQuery('#dw_davcal__eventfrom_edit').val(calEvent.start.format('YYYY-MM-DD HH:mm'));
       jQuery('#dw_davcal__eventto_edit').val(calEvent.end.format('YYYY-MM-DD HH:mm'));
       jQuery('#dw_davcal__allday_edit').prop('checked', calEvent.allDay);
       
           // attach event handlers
        jQuery('#dw_davcal__edit .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideEditEventDialog();
        });
        jQuery('#dw_davcal__eventfrom_edit').datetimepicker({format:'YYYY-MM-DD HH:mm',
                                                      formatTime:'HH:mm',
                                                      formatDate:'YYYY-MM-DD', 
                                                      step: 15});
        jQuery('#dw_davcal__eventto_edit').datetimepicker({format:'YYYY-MM-DD HH:mm',
                                                      formatTime:'HH:mm',
                                                      formatDate:'YYYY-MM-DD', 
                                                      step: 15});
        jQuery('#dw_davcal__allday_edit').change(function() {
            if(jQuery(this).is(":checked"))
            {
                jQuery('#dw_davcal__eventfrom_edit').datetimepicker({timepicker: false});
                jQuery('#dw_davcal__eventto_edit').datetimepicker({timepicker: false});
            }
            else
            {
                jQuery('#dw_davcal__eventfrom_edit').datetimepicker({timepicker: true});
                jQuery('#dw_davcal__eventto_edit').datetimepicker({timepicker: true});
            }
        });        
    },
    
    showNewEventDialog : function(date) {
        if(dw_davcal__modals.$newEventDialog)
            return;
        var dialogButtons = {};
        dialogButtons[LANG.plugins.davcal['create']] = function() {
            var postArray = { };
            jQuery("input[class=dw_davcal__newevent]").each(function() {
              if(jQuery(this).attr('type') == 'checkbox')
              {
                  postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
              }
              else
              {
                  postArray[jQuery(this).prop('name')] = jQuery(this).val();
              }
            });
            jQuery('#dw_davcal__ajaxnew').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
            jQuery.post(
                DOKU_BASE + 'lib/exe/ajax.php',
                {
                    call: 'plugin_davcal',
                    id: JSINFO.id,
                    action: 'newEvent',
                    params: postArray
                },
                function(data)
                {
                    var result = data['result'];
                    var html = data['html'];
                    jQuery('#dw_davcal__ajaxnew').html(html);
                    if(result === true)
                    {
                        jQuery('#fullCalendar').fullCalendar('refetchEvents');
                        dw_davcal__modals.hideNewEventDialog();
                    }
                }
            );
        };
        dialogButtons[LANG.plugins.davcal['cancel']] = function() {
            dw_davcal__modals.hideNewEventDialog();
        };
        dw_davcal__modals.$newEventDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: LANG.plugins.davcal['create_new_event'],
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
            '<div><table><tr><td>' + LANG.plugins.davcal['title'] + '</td><td><input type="text" name="eventname" class="dw_davcal__newevent"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['from'] + '</td><td><input type="text" value="' + date.format('YYYY-MM-DD') + ' 12:00" name="eventfrom" id="dw_davcal__eventfrom" class="dw_davcal__newevent"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['to'] + '</td><td><input type="text" name="eventto"  value="' + date.format('YYYY-MM-DD') + ' 12:00" id="dw_davcal__eventto" class="dw_davcal__newevent"></td></tr>' +
            '<tr><td colspan="2"><input type="checkbox" name="allday" id="dw_davcal__allday" class="dw_davcal__newevent">' + LANG.plugins.davcal['allday'] + '</td></tr>' +
            '</table>' +
            '</div>' +
            '<div id="dw_davcal__ajaxnew"></div>'
            )
       .parent()
       .attr('id','dw_davcal__createnew')
       .show()
       .appendTo('.dokuwiki:first');
       
           // attach event handlers
        jQuery('#dw_davcal__createnew .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideNewEventDialog();
        });
        jQuery('#dw_davcal__eventfrom').datetimepicker({format:'YYYY-MM-DD HH:mm',
                                                      formatTime:'HH:mm',
                                                      formatDate:'YYYY-MM-DD', 
                                                      step: 15});
        jQuery('#dw_davcal__eventto').datetimepicker({format:'YYYY-MM-DD HH:mm',
                                                      formatTime:'HH:mm',
                                                      formatDate:'YYYY-MM-DD', 
                                                      step: 15});
        jQuery('#dw_davcal__allday').change(function() {
            if(jQuery(this).is(":checked"))
            {
                jQuery('#dw_davcal__eventfrom').datetimepicker({timepicker: false});
                jQuery('#dw_davcal__eventto').datetimepicker({timepicker: false});
            }
            else
            {
                jQuery('#dw_davcal__eventfrom').datetimepicker({timepicker: true});
                jQuery('#dw_davcal__eventto').datetimepicker({timepicker: true});
            }
        });
    },
    
    showConfirmDialog : function()
    {
        if(dw_davcal__modals.$confirmDialog)
            return;
        var dialogButtons = {};
        dialogButtons[LANG.plugins.davcal['yes']] =  function() {
                        jQuery.post(
                            DOKU_BASE + 'lib/exe/ajax.php',
                            {
                                call: 'plugin_davcal',
                                id: JSINFO.id,
                                action: dw_davcal__modals.action,
                                params: {
                                    uid: dw_davcal__modals.uid
                                }
                            },
                            function(data)
                            {
                                dw_davcal__modals.completeCb(data);
                            }
                        );
                        dw_davcal__modals.hideConfirmDialog();
                };
        dialogButtons[LANG.plugins.tagrevisions['cancel']] = function() {
                        dw_davcal__modals.hideConfirmDialog();
                };
        dw_davcal__modals.$confirmDialog = jQuery(document.createElement('div'))
            .dialog({
                autoOpen: false,
                draggable: true,
                title: LANG.plugins.tagrevisions['confirmation'],
                resizable: true,
                buttons: dialogButtons,
            })
            .html(
                '<div>' + dw_davcal__modals.msg + '</div>'
            )
            .parent()
            .attr('id','dw_davcal__confirm')
            .show()
            .appendTo('.dokuwiki:first');
   
                 // attach event handlers
            jQuery('#dw_davcal__confirm .ui-dialog-titlebar-close').click(function(){
                dw_davcal__modals.hideConfirmDialog();
            });
    },
    
    showInfoDialog : function() {
       if(dw_davcal__modal.$infoDialog)
            return;
       var dialogButtons = {};
       dialogButtons[LANG.plugins.davcal['ok']] = function() {
                 dw_davcal__modals.hideInfoDialog();
           };
       dw_davcal__modals.$infoDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: LANG.plugins.davcal['info'],
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
            '<div>' + dw_davcal__modals.msg + '</div>'
            )
       .parent()
       .attr('id','dw_davcal__info')
       .show()
       .appendTo('.dokuwiki:first');
       
           // attach event handlers
        jQuery('#dw_davcal__info .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideInfoDialog();
        });
    },         
    
    hideNewEventDialog : function() {
        dw_davcal__modals.$newEventDialog.empty();
        dw_davcal__modals.$newEventDialog.remove();
        dw_davcal__modals.$newEventDialog = null;
    },
    
    hideEditEventDialog : function() {
        dw_davcal__modals.$editEventDialog.empty();
        dw_davcal__modals.$editEventDialog.remove();
        dw_davcal__modals.$editEventDialog = null;
    },
    
    hideInfoDialog : function() {
        dw_davcal__modals.$infoDialog.empty();
        dw_davcal__modals.$infoDialog.remove();
        dw_davcal__modals.$infoDialog = null;
    },
    
    hideConfirmDialog: function() {
        dw_davcal__modals.$confirmDialog.empty();
        dw_davcal__modals.$confirmDialog.remove();
        dw_davcal__modals.$confirmDialog = null;
    }
};
