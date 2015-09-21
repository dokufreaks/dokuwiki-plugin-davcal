/* DOKUWIKI:include_once fullcalendar-2.4.0/moment.js */
/* DOKUWIKI:include_once fullcalendar-2.4.0/fullcalendar.js */
/* DOKUWIKI:include_once fullcalendar-2.4.0/lang/de.js */
/* DOKUWIKI:include_once fullcalendar-2.4.0/lang/en.js */
/* DOKUWIKI:include_once datetimepicker-2.4.5/jquery.datetimepicker.js */
/* DOKUWIKI:include_once jstz.js */

/**
 * Initialize the DAVCal script, attaching some event handlers and triggering
 * the initial load of the fullcalendar JS
 */
jQuery(function() {
    // Redefine functions for using moment.js with datetimepicker
    
    Date.parseDate = function( input, format ){
      return moment(input,format).toDate();
    };
    Date.prototype.dateFormat = function( format ){
      return moment(this).format(format);
    };
    
    // Attach to event links
    var calendarid = jQuery('#fullCalendar').data('calendarid');
    if(!calendarid) return;
    dw_davcal__modals.calid = calendarid;
    
    jQuery('div.fullCalendarSettings a').each(function() {
        var $link = jQuery(this);
        var href = $link.attr('href');
        if (!href) return;

        $link.click(
            function(e) {
                dw_davcal__modals.showSettingsDialog();
                e.preventDefault();
                return '';
            }
        );
        }
    );
    
    // First, retrieve the current settings.
    // Upon success, initialize fullcalendar.
    var postArray = { };
    jQuery.post(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_davcal',
            id: dw_davcal__modals.calid,
            action: 'getSettings',
            params: postArray
        },
        function(data)
        {
            var result = data['result'];
            if(result === true)
            {
                dw_davcal__modals.settings = data['settings'];
                var wknum = false;
                var tz = false;
                var we = true;
                var detectedTz = jstz.determine().name();
                dw_davcal__modals.detectedTz = detectedTz;
                if(data['settings']['weeknumbers'] == 1)
                    wknum = true;
                if(data['settings']['timezone'] !== '')
                    tz = data['settings']['timezone'];
                if(data['settings']['workweek'] == 1)
                    we = false;
                // Initialize the davcal popup
                var res = jQuery('#fullCalendar').fullCalendar({
                    dayClick: function(date, jsEvent, view) {
                        dw_davcal__modals.showEditEventDialog(date, false);
                    },
                    eventClick: function(calEvent, jsEvent, view) {
                        dw_davcal__modals.showEditEventDialog(calEvent, true);
                    },
                    events: {
                        url: DOKU_BASE + 'lib/exe/ajax.php',
                        type: 'POST',
                        data: {
                            call: 'plugin_davcal',
                            action: 'getEvents',
                            id: dw_davcal__modals.calid
                        },
                        error: function() {
                            dw_davcal__modals.msg = LANG.plugins.davcal['error_retrieving_data'];
                            dw_davcal__modals.showDialog(false);
                        }
                    },
                    header: {
                        left: 'title',
                        center: 'today prev,next',
                        right: 'month,agendaWeek,agendaDay'
                    },
                    lang: JSINFO.plugin.davcal['language'],
                    weekNumbers: wknum,
                    timezone: tz,
                    weekends: we,
                });
            }
        }
    );    
});

/**
 * This holds all modal windows that DAVCal uses.
 */
var dw_davcal__modals = {
    $editEventDialog: null,
    $dialog: null,
    $settingsDialog: null,
    msg: null,
    completeCb: null,
    action: null,
    uid: null,
    settings: null,
    calid: null,
    detectedTz: null,
    
    /**
     * Show the settings dialog
     */
    showSettingsDialog : function() {
        if(dw_davcal__modals.$settingsDialog)
            return;

        // Dialog buttons are language-dependent and defined here.
        // Attach event handlers for save and cancel.
        var dialogButtons = {};
        dialogButtons[LANG.plugins.davcal['save']] = function() {
            var postArray = { };
            jQuery("input[class=dw_davcal__settings], select[class=dw_davcal__settings]").each(function() {
              if(jQuery(this).attr('type') == 'checkbox')
              {
                  postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
              }
              else
              {
                  postArray[jQuery(this).prop('name')] = jQuery(this).val();
              }
            });
            jQuery('#dw_davcal__ajaxsettings').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
            jQuery.post(
                DOKU_BASE + 'lib/exe/ajax.php',
                {
                    call: 'plugin_davcal',
                    id: dw_davcal__modals.calid,
                    action: 'saveSettings',
                    params: postArray
                },
                function(data)
                {
                    var result = data['result'];
                    var html = data['html'];
                    jQuery('#dw_davcal__ajaxsettings').html(html);
                    if(result === true)
                    {
                        location.reload();
                    }
                }
            );
        };
        dialogButtons[LANG.plugins.davcal['cancel']] = function () {
            dw_davcal__modals.hideSettingsDialog();
        };
        
        dw_davcal__modals.$settingsDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: LANG.plugins.davcal['settings'],
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
            '<div><table>' +
            //'<tr><td>' + LANG.plugins.davcal['use_lang_tz'] + '</td><td><input type="checkbox" name="use_lang_tz" id="dw_davcal__settings_use_lang_tz" class="dw_davcal__settings"></td></tr>' + 
            '<tr><td>' + LANG.plugins.davcal['timezone'] + '</td><td><select name="timezone" id="dw_davcal__settings_timezone" class="dw_davcal__settings"></select></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['weeknumbers'] + '</td><td><input type="checkbox" name="weeknumbers" id="dw_davcal__settings_weeknumbers" class="dw_davcal__settings"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['only_workweek'] + '</td><td><input type="checkbox" name="workweek" id="dw_davcal__settings_workweek" class="dw_davcal__settings"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['sync_url'] + '</td><td><input type="text" name="syncurl" readonly="readonly" id="dw_davcal__settings_syncurl" class="dw_davcal__text" value="' + dw_davcal__modals.settings['syncurl'] + '"></td></tr>' + 
            '<tr><td>' + LANG.plugins.davcal['private_url'] + '</td><td><input type="text" name="privateurl" readonly="readonly" id="dw_davcal__settings_privateurl" class="dw_davcal__text" value="' + dw_davcal__modals.settings['privateurl'] + '"></td></tr>' +
            '</table>' +
            '</div>' +
            '<div id="dw_davcal__ajaxsettings"></div>'
            )
       .parent()
       .attr('id','dw_davcal__settings')
       .show()
       .appendTo('.dokuwiki:first');
       
       jQuery('#dw_davcal__settings').position({
           my: "center",
           at: "center",
           of: window
       });
       
       // Initialize current settings
       jQuery('#dw_davcal__settings_syncurl').on('click', function() {
           jQuery(this).select();
       });
       
       jQuery('#dw_davcal__settings_privateurl').on('click', function() {
           jQuery(this).select(); 
       });
        
        var $tzdropdown = jQuery('#dw_davcal__settings_timezone');
        jQuery('#fullCalendarTimezoneList option').each(function() {
            jQuery('<option />', {value: jQuery(this).val(), 
                    text: jQuery(this).text()}).appendTo($tzdropdown);
        });
        
        if(dw_davcal__modals.settings)
        {
            if(dw_davcal__modals.settings['timezone'] !== '')
                jQuery('#dw_davcal__settings_timezone').val(dw_davcal__modals.settings['timezone']);
            if(dw_davcal__modals.settings['weeknumbers'] == 1)
                jQuery('#dw_davcal__settings_weeknumbers').prop('checked', true);
            else
                jQuery('#dw_davcal__settings_weeknumbers').prop('checked', false);
                
            if(dw_davcal__modals.settings['workweek'] == 1)
                jQuery('#dw_davcal__settings_workweek').prop('checked', true);
            else
                jQuery('#dw_davcal__settings_workweek').prop('checked', false);
        }

        // attach event handlers
        jQuery('#dw_davcal__settings .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideSettingsDialog();
        });
    },
    
    /**
     * Sanity-check our events.
     * 
     * @return boolean false on failure, otherwise true
     */
    checkEvents : function() {
        // Retrieve dates
        var allDay = jQuery('#dw_davcal__allday_edit').prop('checked');
        var startDate = moment(jQuery('#dw_davcal__eventfrom_edit').val(), 'YYYY-MM-DD');
        var endDate = moment(jQuery('#dw_davcal__eventto_edit').val(), 'YYYY-MM-DD');
        
        // Do the checking
        if(!allDay)
        {
            var startTime = moment.duration(jQuery('#dw_davcal__eventfromtime_edit').val());
            var endTime = moment.duration(jQuery('#dw_davcal__eventtotime_edit').val());
            startDate.add(startTime);
            endDate.add(endTime);
        }
        if(!startDate.isValid())
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['start_date_invalid'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        if(!endDate.isValid())
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['end_date_invalid'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        if(endDate.isBefore(startDate))
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['end_date_before_start_date'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        if(!allDay && endDate.isSame(startDate))
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['end_date_is_same_as_start_date'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        return true;
    },
    
    /**
     * Show the edit event dialog, which is also used to create new events
     * @param {Object} event The event to create, that is the date or the calEvent
     * @param {Object} edit  Whether we edit (true) or create a new event (false)
     */
    showEditEventDialog : function(event, edit) {
        if(dw_davcal__modals.$editEventDialog)
            return;
         
        var title = '';   
        var dialogButtons = {};
        var calEvent = [];
        var recurringWarning = '';
        // Buttons are dependent on edit or create
        if(edit && (event.recurring != true))
        {
            calEvent = event;
            title = LANG.plugins.davcal['edit_event'];
            dialogButtons[LANG.plugins.davcal['edit']] = function() {
                if(!dw_davcal__modals.checkEvents())
                  return;
                var postArray = { };
                jQuery("input.dw_davcal__editevent, textarea.dw_davcal__editevent").each(function() {
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
                        id: dw_davcal__modals.calid,
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
            dialogButtons[LANG.plugins.davcal['delete']] = function() {
                dw_davcal__modals.action = 'deleteEvent';
                dw_davcal__modals.msg = LANG.plugins.davcal['really_delete_this_event'];
                dw_davcal__modals.completeCb = function(data) {
                    if(data.result == false)
                    {
                        dw_davcal__modals.msg = data.errmsg;
                        dw_davcal__modals.showDialog(false);
                    }
                    else
                    {
                        jQuery('#fullCalendar').fullCalendar('refetchEvents');
                        dw_davcal__modals.hideEditEventDialog();
                    }
                };
                dw_davcal__modals.showDialog(true);
            };
        }
        else if(edit)
        {
            calEvent = event;
            title = LANG.plugins.davcal['edit_event'];
            recurringWarning = LANG.plugins.davcal['recurring_cant_edit'];
        } 
        else
        {
            calEvent.start = event;
            calEvent.end = moment(event);
            calEvent.start.hour(12);
            calEvent.start.minute(0);
            calEvent.end.hour(13);
            calEvent.end.minute(0);
            calEvent.allDay = false;
            calEvent.recurring = false;
            calEvent.title = '';
            calEvent.description = '';
            calEvent.id = '0';
            title = LANG.plugins.davcal['create_new_event'];
            dialogButtons[LANG.plugins.davcal['create']] = function() {
                if(!dw_davcal__modals.checkEvents())
                  return;

                var postArray = { };
                jQuery("input.dw_davcal__editevent, textarea.dw_davcal__editevent").each(function() {
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
                        id: dw_davcal__modals.calid,
                        action: 'newEvent',
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
        }
        dialogButtons[LANG.plugins.davcal['cancel']] = function() {
            dw_davcal__modals.hideEditEventDialog();
        };
        dw_davcal__modals.uid = calEvent.id;
        dw_davcal__modals.$editEventDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: title,
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
            '<div><table><tr><td>' + LANG.plugins.davcal['title'] + '</td><td><input type="text" id="dw_davcal__eventname_edit" name="eventname" class="dw_davcal__editevent"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['description'] + '</td><td><textarea name="eventdescription" id="dw_davcal__eventdescription_edit" class="dw_davcal__editevent dw_davcal__text"></textarea></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['from'] + '</td><td><input type="text" name="eventfrom" id="dw_davcal__eventfrom_edit" class="dw_davcal__editevent dw_davcal__date"><input type="text" name="eventfromtime" id="dw_davcal__eventfromtime_edit" class="dw_davcal__editevent dw_davcal__time"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['to'] + '</td><td><input type="text" name="eventto" id="dw_davcal__eventto_edit" class="dw_davcal__editevent dw_davcal__date"><input type="text" name="eventtotime" id="dw_davcal__eventtotime_edit" class="dw_davcal__editevent dw_davcal__time"></td></tr>' +
            '<tr><td colspan="2"><input type="checkbox" name="allday" id="dw_davcal__allday_edit" class="dw_davcal__editevent">' + LANG.plugins.davcal['allday'] + '</td></tr>' +
            '</table>' +
            recurringWarning + 
            '<input type="hidden" name="uid" id="dw_davcal__uid_edit" class="dw_davcal__editevent">' +
            '<input type="hidden" name="detectedtz" id="dw_davcal__tz_edit" class="dw_davcal__editevent">' +
            '</div>' +
            '<div id="dw_davcal__ajaxedit"></div>'
            )
       .parent()
       .attr('id','dw_davcal__edit')
       .show()
       .appendTo('.dokuwiki:first');
       
       jQuery('#dw_davcal__edit').position({
           my: "center",
           at: "center",
           of: window
       });
       
       // Set up existing/predefined values
       jQuery('#dw_davcal__tz_edit').val(dw_davcal__modals.detectedTz);
       jQuery('#dw_davcal__uid_edit').val(calEvent.id);
       jQuery('#dw_davcal__eventname_edit').val(calEvent.title);
       jQuery('#dw_davcal__eventfrom_edit').val(calEvent.start.format('YYYY-MM-DD'));
       jQuery('#dw_davcal__eventfromtime_edit').val(calEvent.start.format('HH:mm'));
       jQuery('#dw_davcal__eventdescription_edit').val(calEvent.description);
       if(calEvent.allDay && (calEvent.end === null))
       {
           jQuery('#dw_davcal__eventto_edit').val(calEvent.start.format('YYYY-MM-DD'));
           jQuery('#dw_davcal__eventtotime_edit').val(calEvent.start.format('HH:mm'));
       }
       else if(calEvent.allDay)
       {
           endEvent = moment(calEvent.end);
           endEvent.subtract(1, 'days');
           jQuery('#dw_davcal__eventto_edit').val(endEvent.format('YYYY-MM-DD'));
           jQuery('#dw_davcal__eventotime_edit').val(endEvent.format('HH:mm'));
       }
       else
       {
           jQuery('#dw_davcal__eventto_edit').val(calEvent.end.format('YYYY-MM-DD'));
           jQuery('#dw_davcal__eventtotime_edit').val(calEvent.end.format('HH:mm'));
       }
       jQuery('#dw_davcal__allday_edit').prop('checked', calEvent.allDay);
       
        // attach event handlers
        jQuery('#dw_davcal__edit .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideEditEventDialog();
        });
        jQuery('#dw_davcal__eventfrom_edit').datetimepicker({format:'YYYY-MM-DD',
                                                      formatDate:'YYYY-MM-DD',
                                                      datepicker: true,
                                                      timepicker: false,
                                                      });
        jQuery('#dw_davcal__eventfromtime_edit').datetimepicker({format:'HH:mm',
                                                      formatTime:'HH:mm',
                                                      datepicker: false,
                                                      timepicker: true,
                                                      step: 15});
        jQuery('#dw_davcal__eventto_edit').datetimepicker({format:'YYYY-MM-DD',
                                                      formatDate:'YYYY-MM-DD',
                                                      datepicker: true,
                                                      timepicker: false,
                                                      });
        jQuery('#dw_davcal__eventtotime_edit').datetimepicker({format:'HH:mm',
                                                      formatTime:'HH:mm',
                                                      datepicker: false,
                                                      timepicker: true,
                                                      step:15});
        jQuery('#dw_davcal__allday_edit').change(function() {
            if(jQuery(this).is(":checked"))
            {
                jQuery('#dw_davcal__eventfromtime_edit').prop('readonly', true);
                jQuery('#dw_davcal__eventtotime_edit').prop('readonly', true);
            }
            else
            {
                jQuery('#dw_davcal__eventfromtime_edit').prop('readonly', false);
                jQuery('#dw_davcal__eventtotime_edit').prop('readonly', false);
            }
        });
        jQuery('#dw_davcal__allday_edit').change();
    },
    
    /**
     * Show an info/confirmation dialog
     * @param {Object} confirm Whether a confirmation dialog (true) or an info dialog (false) is requested
     */
    showDialog : function(confirm)
    {
        if(dw_davcal__modals.$confirmDialog)
            return;
        var dialogButtons = {};
        var title = '';
        if(confirm)
        {
            title = LANG.plugins.davcal['confirmation'];
            dialogButtons[LANG.plugins.davcal['yes']] =  function() {
                            jQuery.post(
                                DOKU_BASE + 'lib/exe/ajax.php',
                                {
                                    call: 'plugin_davcal',
                                    id: dw_davcal__modals.calid,
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
                            dw_davcal__modals.hideDialog();
                    };
            dialogButtons[LANG.plugins.davcal['cancel']] = function() {
                            dw_davcal__modals.hideDialog();
                    };
        }
        else
        {
            title = LANG.plugins.davcal['info'];
            dialogButtons[LANG.plugins.davcal['ok']] = function() {
                 dw_davcal__modals.hideDialog();
            };
        }
        dw_davcal__modals.$dialog = jQuery(document.createElement('div'))
            .dialog({
                autoOpen: false,
                draggable: true,
                title: title,
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
   
            jQuery('#dw_davcal__confirm').position({
                my: "center",
                at: "center",
                of: window
            });
                 // attach event handlers
            jQuery('#dw_davcal__confirm .ui-dialog-titlebar-close').click(function(){
                dw_davcal__modals.hideDialog();
            });
    },
    
    /**
     * Hide the edit event dialog
     */
    hideEditEventDialog : function() {
        dw_davcal__modals.$editEventDialog.empty();
        dw_davcal__modals.$editEventDialog.remove();
        dw_davcal__modals.$editEventDialog = null;
    },
    
    /**
     * Hide the confirm/info dialog
     */
    hideDialog: function() {
        dw_davcal__modals.$dialog.empty();
        dw_davcal__modals.$dialog.remove();
        dw_davcal__modals.$dialog = null;
    },
    
    /**
     * Hide the settings dialog
     */
    hideSettingsDialog: function() {
        dw_davcal__modals.$settingsDialog.empty();
        dw_davcal__modals.$settingsDialog.remove();
        dw_davcal__modals.$settingsDialog = null;
    }
};
